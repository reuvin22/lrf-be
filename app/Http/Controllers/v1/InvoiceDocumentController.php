<?php

namespace App\Http\Controllers\v1;

use App\Events\InvoiceDocumentEvent;
use App\Http\Requests\v1\InvoiceDocumentConfirmRequest;
use App\Http\Requests\v1\InvoiceDocumentRequest;
use App\Http\Requests\v1\InvoiceDocumentUpdateRequest;
use App\Services\AnthropicVisionService;
use App\Services\FirebaseService;
use App\Services\InvoiceExtractionValidator;
use App\Services\InvoiceNameMatchingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceDocumentController extends SheetResourceController
{
    protected string $sheetName = 'InvoiceDocuments';

    protected string $idColumn = 'document_id';

    protected array $headers = [
        'document_id', 'uploaded_by', 'subcontractor_id', 'subcontractor_name',
        'vendor_name_raw', 'issue_date', 'billing_month', 'document_type', 'category_id',
        'subtotal', 'tax_amount', 'total_with_tax', 'file_path', 'ocr_result_raw',
        'status', 'warnings', 'confirmed_by', 'confirmed_at', 'note',
        'uploaded_at', 'processed_at',
    ];

    private const LINES_SHEET = 'InvoiceLines';

    private const LINES_HEADERS = [
        'line_id', 'invoice_document_id', 'site_id', 'site_name', 'site_name_raw',
        'amount', 'amount_with_tax',
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    public function index(Request $request): JsonResponse
    {
        $rows = $this->all();
        usort($rows, fn ($a, $b) => strcmp((string) ($b['uploaded_at'] ?? ''), (string) ($a['uploaded_at'] ?? '')));

        $linesByDocument = [];
        foreach ($this->allLines() as $line) {
            $linesByDocument[$line['invoice_document_id'] ?? ''][] = $line;
        }

        foreach ($rows as &$row) {
            $row['lines'] = $linesByDocument[$row['document_id'] ?? ''] ?? [];
        }
        unset($row);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(string $id, ?InvoiceNameMatchingService $matcher = null): JsonResponse
    {
        $matcher ??= app(InvoiceNameMatchingService::class);

        $document = $this->find($id);
        if (! $document) {
            return $this->notFound();
        }

        $lines = $this->linesFor($id);
        foreach ($lines as &$line) {
            $line['candidates'] = $matcher->candidatesForSite($line['site_name_raw'] ?? null);
        }
        unset($line);

        return response()->json([
            'success' => true,
            'data' => $document + [
                'lines' => $lines,
                'vendor_candidates' => $matcher->candidatesForSubcontractor($document['vendor_name_raw'] ?? null),
            ],
        ]);
    }

    public function store(
        InvoiceDocumentRequest $request,
        FirebaseService $firebase,
        AnthropicVisionService $vision,
        InvoiceNameMatchingService $matcher,
        InvoiceExtractionValidator $validator
    ): JsonResponse {
        $data = $request->validated();
        $documentId = (string) Str::uuid();
        $uploadedBy = $data['uploaded_by'] ?? null;
        $now = Carbon::now('Asia/Manila');

        $files = [];
        $filePaths = [];

        foreach ($data['files'] as $i => $file) {
            $decoded = $this->decodeFile($file['data']);
            if ($decoded === null) {
                return response()->json([
                    'success' => false,
                    'message' => "File #{$i} is not a supported image or PDF.",
                ], 422);
            }

            $uploaded = $this->uploadFile($firebase, $decoded, $uploadedBy ?? 'unknown', $i);
            if ($uploaded instanceof JsonResponse) {
                return $uploaded;
            }

            $filePaths[] = $uploaded;
            $files[] = ['media_type' => $decoded['mime'], 'base64' => $decoded['base64']];
        }

        $document = [
            'document_id' => $documentId,
            'uploaded_by' => $uploadedBy ?? '',
            'category_id' => $data['category_id'] ?? '',
            'file_path' => $filePaths,
            'note' => $data['note'] ?? '',
            'uploaded_at' => $now->toDateTimeString(),
            'processed_at' => $now->toDateTimeString(),
        ];

        $lines = [];
        $vendorCandidates = [];
        $runsVision = $vision->isEnabled();

        if (! $runsVision) {
            $document['status'] = 'NEEDS_REVIEW';
            $document['warnings'] = ['LLM extraction is not configured; please enter details manually.'];
            $this->appendRow($document);
        } else {
            // Write the row as PROCESSING before calling the LLM, then update
            // it once extraction finishes, so other clients polling the list
            // see the in-progress state rather than the row appearing only
            // after extraction completes.
            $document['status'] = 'PROCESSING';
            $this->appendRow($document);
            $located = $this->locate($documentId);

            $extracted = $vision->extractInvoice($files);

            if ($extracted === null) {
                $document['status'] = 'ERROR';
            } else {
                $warnings = $validator->validate($extracted);
                $vendorCandidates = $matcher->candidatesForSubcontractor($extracted['vendor_name'] ?? null);
                $topVendor = $vendorCandidates[0] ?? null;
                $vendorPreselect = $topVendor && ! empty($topVendor['preselect']);

                $document['vendor_name_raw'] = $extracted['vendor_name'] ?? '';
                $document['issue_date'] = $extracted['issue_date'] ?? '';
                $document['billing_month'] = $extracted['billing_month'] ?? '';
                $document['document_type'] = $extracted['document_type'] ?? '';
                $document['subtotal'] = $extracted['subtotal'] ?? '';
                $document['tax_amount'] = $extracted['tax_amount'] ?? '';
                $document['total_with_tax'] = $extracted['total_with_tax'] ?? '';
                $document['ocr_result_raw'] = json_encode($extracted, JSON_UNESCAPED_UNICODE);
                $document['status'] = 'NEEDS_REVIEW';
                $document['warnings'] = $warnings;
                $document['subcontractor_id'] = $vendorPreselect ? $topVendor['id'] : '';
                $document['subcontractor_name'] = $vendorPreselect ? $topVendor['name'] : '';

                foreach (($extracted['lines'] ?? []) as $extractedLine) {
                    $siteCandidates = $matcher->candidatesForSite($extractedLine['site_name'] ?? null);
                    $topSite = $siteCandidates[0] ?? null;
                    $sitePreselect = $topSite && ! empty($topSite['preselect']);

                    $lineData = [
                        'line_id' => (string) Str::uuid(),
                        'invoice_document_id' => $documentId,
                        'site_id' => $sitePreselect ? $topSite['id'] : '',
                        'site_name' => $sitePreselect ? $topSite['name'] : '',
                        'site_name_raw' => $extractedLine['site_name'] ?? '',
                        'amount' => $extractedLine['amount'] ?? '',
                        'amount_with_tax' => $extractedLine['amount_with_tax'] ?? '',
                    ];

                    $this->appendLine($lineData);
                    $lines[] = $lineData + ['candidates' => $siteCandidates];
                }
            }

            if ($located) {
                $this->updateRowAt($located['rowNumber'], $document);
            }
        }

        event(new InvoiceDocumentEvent($document, strtolower($document['status'])));

        return response()->json([
            'success' => true,
            'message' => 'Invoice document created successfully.',
            'data' => $document + [
                'lines' => $lines,
                'vendor_candidates' => $vendorCandidates,
            ],
        ], 201);
    }

    public function update(InvoiceDocumentUpdateRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (! $located) {
            return $this->notFound();
        }

        $data = $request->validated();
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        $merged = array_merge($located['data'], $data);
        $merged['document_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $merged);

        if ($lines !== null) {
            $this->deleteLinesFor($id);
            $newLines = [];

            foreach ($lines as $line) {
                $lineData = [
                    'line_id' => (string) Str::uuid(),
                    'invoice_document_id' => $id,
                    'site_id' => $line['site_id'] ?? '',
                    'site_name' => $line['site_name'] ?? '',
                    'site_name_raw' => $line['site_name_raw'] ?? '',
                    'amount' => $line['amount'] ?? '',
                    'amount_with_tax' => $line['amount_with_tax'] ?? '',
                ];
                $this->appendLine($lineData);
                $newLines[] = $lineData;
            }
        } else {
            $newLines = $this->linesFor($id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Invoice document updated successfully.',
            'data' => $merged + ['lines' => $newLines],
        ]);
    }

    public function confirm(string $id, InvoiceDocumentConfirmRequest $request, InvoiceNameMatchingService $matcher): JsonResponse
    {
        $located = $this->locate($id);
        if (! $located) {
            return $this->notFound();
        }

        $document = $located['data'];
        $data = $request->validated();

        if (! empty($data['new_subcontractor_name'])) {
            $subcontractorId = $matcher->createSubcontractor($data['new_subcontractor_name']);
        } else {
            $subcontractorId = $data['subcontractor_id'];
        }

        $matcher->confirmSubcontractorMapping(
            $document['vendor_name_raw'] ?: ($data['new_subcontractor_name'] ?? ''),
            $subcontractorId,
            $id
        );

        foreach ($data['lines'] as $lineInput) {
            $locatedLine = $this->locateLine($lineInput['line_id']);
            if (! $locatedLine) {
                continue;
            }

            $lineRow = $locatedLine['data'];

            if (! empty($lineInput['new_site_name'])) {
                $siteId = $matcher->createSite($lineInput['new_site_name']);
            } else {
                $siteId = $lineInput['site_id'];
            }

            $matcher->confirmSiteMapping(
                $lineRow['site_name_raw'] ?: ($lineInput['new_site_name'] ?? ''),
                $siteId,
                $id
            );

            $updatedLine = array_merge($lineRow, [
                'site_id' => $siteId,
                'site_name' => $matcher->siteName($siteId) ?? ($lineInput['new_site_name'] ?? ''),
                'amount' => $lineInput['amount'] ?? ($lineRow['amount'] ?? ''),
                'amount_with_tax' => $lineInput['amount_with_tax'] ?? ($lineRow['amount_with_tax'] ?? ''),
            ]);

            $this->updateLineAt($locatedLine['rowNumber'], $updatedLine);
        }

        $document = array_merge($document, [
            'document_id' => $id,
            'subcontractor_id' => $subcontractorId,
            'subcontractor_name' => $matcher->subcontractorName($subcontractorId) ?? ($data['new_subcontractor_name'] ?? ''),
            'status' => 'CONFIRMED',
            'confirmed_by' => $data['confirmed_by'],
            'confirmed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
        ]);

        $this->updateRowAt($located['rowNumber'], $document);

        event(new InvoiceDocumentEvent($document, 'confirmed'));

        return response()->json([
            'success' => true,
            'message' => 'Invoice document confirmed successfully.',
            'data' => $document + ['lines' => $this->linesFor($id)],
        ]);
    }

    public function destroy(Request $request, ?string $id = null, ?FirebaseService $firebase = null): JsonResponse
    {
        if ($id === null) {
            return $this->notFound('Id is required.');
        }

        $located = $this->locate($id);
        if (! $located) {
            return $this->notFound();
        }

        if ($firebase) {
            $raw = $located['data']['file_path'] ?? '';
            $paths = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;

            try {
                $bucket = $firebase->storage()->getBucket();
                foreach ($paths as $path) {
                    $fileName = 'invoice_documents/'.basename((string) parse_url($path, PHP_URL_PATH));
                    $object = $bucket->object($fileName);
                    if ($object->exists()) {
                        $object->delete();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete invoice file(s) from Firebase.', ['error' => $e->getMessage()]);
            }
        }

        $this->deleteLinesFor($id);
        $this->deleteRowAt($located['rowNumber']);

        return response()->json([
            'success' => true,
            'message' => 'Invoice document deleted successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // InvoiceLines helpers — a second sheet tab, addressed directly via
    // $this->sheet since SheetResourceController's row helpers are scoped to
    // the single $sheetName/$headers pair configured above.
    // -------------------------------------------------------------------------

    private function allLines(): array
    {
        return $this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::LINES_SHEET);
    }

    private function linesFor(string $documentId): array
    {
        return array_values(array_filter(
            $this->allLines(),
            fn ($row) => ($row['invoice_document_id'] ?? null) === $documentId
        ));
    }

    private function appendLine(array $data): void
    {
        $this->sheet->appendRow($this->spreadsheetId(), self::LINES_SHEET, $this->toLineRow($data));
    }

    private function locateLine(string $lineId): ?array
    {
        $rows = $this->allLines();
        foreach ($rows as $index => $row) {
            if (($row['line_id'] ?? null) === $lineId) {
                return ['rowNumber' => $index + 2, 'data' => $row];
            }
        }

        return null;
    }

    private function updateLineAt(int $rowNumber, array $data): void
    {
        $this->sheet->updateRow($this->spreadsheetId(), self::LINES_SHEET, $rowNumber, $this->toLineRow($data));
    }

    private function deleteLinesFor(string $documentId): void
    {
        $rows = $this->allLines();
        $rowNumbers = [];

        foreach ($rows as $index => $row) {
            if (($row['invoice_document_id'] ?? null) === $documentId) {
                $rowNumbers[] = $index + 2;
            }
        }

        // Delete bottom-up so earlier row numbers don't shift mid-loop.
        rsort($rowNumbers);
        foreach ($rowNumbers as $rowNumber) {
            $this->sheet->deleteRow($this->spreadsheetId(), self::LINES_SHEET, $rowNumber);
        }
    }

    private function toLineRow(array $data): array
    {
        return array_map(function ($h) use ($data) {
            $v = $data[$h] ?? '';
            if (is_array($v) || is_object($v)) {
                return json_encode($v, JSON_UNESCAPED_UNICODE);
            }

            return (string) ($v ?? '');
        }, self::LINES_HEADERS);
    }

    // -------------------------------------------------------------------------
    // File handling
    // -------------------------------------------------------------------------

    /**
     * @return array{mime: string, base64: string}|null
     */
    private function decodeFile(string $dataUri): ?array
    {
        if (! preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $dataUri, $m)) {
            return null;
        }

        $mime = strtolower($m[1]);
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return null;
        }

        $raw = base64_decode($m[2], true);
        if ($raw === false || strlen($raw) < 100) {
            return null;
        }

        return ['mime' => $mime, 'base64' => $m[2]];
    }

    /**
     * Upload one decoded file to Firebase. Returns the public URL or a JsonResponse on error.
     *
     * @param  array{mime: string, base64: string}  $decoded
     */
    private function uploadFile(FirebaseService $firebase, array $decoded, string $uploadedBy, int $index): JsonResponse|string
    {
        $extension = match ($decoded['mime']) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $raw = base64_decode($decoded['base64']);
        $fileName = 'invoice_documents/'.$uploadedBy.'_'.Carbon::now('Asia/Manila')->format('Ymd_His_u').'_'.$index.'.'.$extension;

        try {
            $bucket = $firebase->storage()->getBucket();
            $object = $bucket->upload($raw, ['name' => $fileName]);

            if (! $object) {
                throw new \Exception('Firebase upload returned null.');
            }

            $object->update([], ['predefinedAcl' => 'PUBLICREAD']);

            $uploaded = $bucket->object($fileName);
            if (! $uploaded->exists() || ($uploaded->info()['size'] ?? 0) == 0) {
                throw new \Exception('Uploaded file is empty or missing.');
            }

            return 'https://storage.googleapis.com/'.$bucket->name().'/'.$fileName;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Firebase upload failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
