<?php

namespace App\Http\Controllers\v1;

use App\Events\InvoiceDocumentEvent;
use App\Http\Requests\v1\InvoiceDocumentConfirmRequest;
use App\Http\Requests\v1\InvoiceDocumentRequest;
use App\Http\Requests\v1\InvoiceDocumentUpdateRequest;
use App\Http\Requests\v1\OcrUploadRequest;
use App\Http\Requests\v1\OcrUploadReviewRequest;
use App\Services\AnthropicVisionService;
use App\Services\FirebaseService;
use App\Services\InvoiceExtractionValidator;
use App\Services\InvoiceNameMatchingService;
use Carbon\Carbon;
use Google\Cloud\Storage\Bucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;

class OcrUploadController extends SheetResourceController
{
    protected string $sheetName = 'OcrUploads';

    protected string $idColumn = 'upload_id';

    protected array $headers = [
        'upload_id', 'uploaded_by', 'category_id',
        'site_id', 'site_name', 'subcontractor_id', 'subcontractor_name',
        'attendance_id', 'upload_source', 'status', 'image_path',
        'ocr_result_amount', 'ocr_result_date', 'ocr_result_raw',
        'confirmed', 'confirmed_by', 'confirmed_at', 'note',
        'uploaded_at', 'processed_at',
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        'application/vnd.ms-excel', // legacy .xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'text/csv',
    ];

    // Claude's vision API only accepts images and PDFs as attachments — these
    // mime types have their text extracted server-side instead and sent as a
    // plain text block (see buildVisionFile()).
    private const TEXT_EXTRACTED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
    ];

    // -------------------------------------------------------------------------
    // Invoice-document module — a second and third sheet tab (InvoiceDocuments,
    // InvoiceLines) plus read-only summary reporting, addressed directly via
    // $this->sheet since SheetResourceController's row helpers above are
    // scoped to the single $sheetName/$headers pair (OcrUploads) configured
    // for this class.
    // -------------------------------------------------------------------------

    private const INVOICE_SHEET = 'InvoiceDocuments';

    private const INVOICE_HEADERS = [
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

    private const INVOICE_ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    private const AGGREGATABLE_TYPES = ['INVOICE', 'MONTHLY_STATEMENT'];

    public function index(Request $request): JsonResponse
    {
        $rows = $this->all();
        usort($rows, fn ($a, $b) => strcmp((string) ($b['uploaded_at'] ?? ''), (string) ($a['uploaded_at'] ?? '')));

        return response()->json(['success' => true, 'data' => array_map($this->presentRow(...), $rows)]);
    }

    public function show(string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (! $located) {
            return $this->notFound();
        }

        return response()->json(['success' => true, 'data' => $this->presentRow($located['data'])]);
    }

    public function store(OcrUploadRequest $request, FirebaseService $firebase, AnthropicVisionService $vision): JsonResponse
    {
        $data = $request->validated();
        $data['upload_id'] = (string) Str::uuid();
        $rawImages = $this->collectNewImages($data);
        $visionFiles = [];

        if (! empty($rawImages)) {
            $result = $this->processNewImages($firebase, $rawImages, $data['uploaded_by'] ?? 'unknown');
            if ($result instanceof JsonResponse) {
                return $result;
            }

            $data['image_path'] = $this->normalizePaths($result['paths']);
            $visionFiles = $result['files'];
        }

        unset($data['image_base64'], $data['images_base64'], $data['previous_image_paths'], $data['use_vision']);

        // Write the row as PROCESSING before calling the LLM, then update it
        // once extraction finishes, so other clients polling the list see the
        // in-progress state rather than the row appearing only after OCR completes.
        $runsVision = ! empty($visionFiles) && $vision->isEnabled();
        if ($runsVision) {
            $data['status'] = 'PROCESSING';
        }

        $data = $this->resolveNames($data);
        $this->appendRow($data);

        if ($runsVision) {
            $located = $this->locate($data['upload_id']);
            $data = array_merge($data, $this->extractOcr($vision, $visionFiles));
            $data = $this->resolveNames($data);

            if ($located) {
                $this->updateRowAt($located['rowNumber'], $data);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'OCR upload created successfully.',
            'data' => $this->presentRow($data),
        ], 201);
    }

    public function update(OcrUploadRequest $request, string $id, FirebaseService $firebase, AnthropicVisionService $vision): JsonResponse
    {
        $located = $this->locate($id);
        if (! $located) {
            return $this->notFound();
        }

        $existing = $located['data'];
        $data = $request->validated();
        $bucket = $firebase->storage()->getBucket();

        // The frontend always resubmits the full desired image set on edit:
        // `previous_image_paths` is the subset of existing images the user
        // kept, and `images_base64` is whatever new files they added. Any
        // existing image not present in `previous_image_paths` was removed
        // by the user and must be deleted from Firebase.
        $touchesImages = array_key_exists('images_base64', $data) || array_key_exists('previous_image_paths', $data);
        $finalPaths = [];
        $visionFiles = [];

        try {
            if ($touchesImages) {
                $rawImages = $this->collectNewImages($data);
                $keepPaths = array_values(array_filter((array) ($data['previous_image_paths'] ?? [])));
                $existingPaths = $this->resolvePaths($existing['image_path'] ?? null);

                $this->deleteStoredImages($bucket, array_diff($existingPaths, $keepPaths));

                $newPaths = [];
                if (! empty($rawImages)) {
                    $result = $this->processNewImages($firebase, $rawImages, $data['uploaded_by'] ?? ($existing['uploaded_by'] ?? 'unknown'));
                    if ($result instanceof JsonResponse) {
                        return $result;
                    }

                    $newPaths = $result['paths'];
                    $visionFiles = $result['files'];
                }

                $finalPaths = array_values(array_merge($keepPaths, $newPaths));

                if (empty($finalPaths)) {
                    $data['image_path'] = null;
                    $data['ocr_result_amount'] = null;
                    $data['ocr_result_date'] = null;
                    $data['ocr_result_raw'] = null;
                } else {
                    $data['image_path'] = $this->normalizePaths($finalPaths);

                    // Kept images only have their Firebase URL, not the raw
                    // bytes — re-fetch them so the whole document (kept +
                    // new pages) gets re-extracted together, same as the
                    // "multi-page = one document" behavior on create.
                    foreach ($keepPaths as $keepPath) {
                        $file = $this->fetchAsVisionFile($keepPath);
                        if ($file !== null) {
                            $visionFiles[] = $file;
                        }
                    }
                }
            } else {
                unset($data['image_path']);
            }

            unset($data['image_base64'], $data['images_base64'], $data['previous_image_paths'], $data['use_vision']);

            // Same PROCESSING → (PENDING|ERROR) two-write pattern as store():
            // persist the in-progress state before calling the LLM.
            $runsVision = ! empty($visionFiles) && $vision->isEnabled();
            if ($runsVision) {
                $data['status'] = 'PROCESSING';
            }

            $merged = array_merge($existing, $data);
            $merged['upload_id'] = $id;
            $merged = $this->resolveNames($merged);

            $this->updateRowAt($located['rowNumber'], $merged);

            if ($runsVision) {
                $merged = array_merge($merged, $this->extractOcr($vision, $visionFiles));
                $merged = $this->resolveNames($merged);
                $this->updateRowAt($located['rowNumber'], $merged);
            }

            return response()->json([
                'success' => true,
                'message' => 'OCR upload updated successfully.',
                'data' => $this->presentRow($merged),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Update failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve or reject an OCR upload after human review. Approve sets
     * confirmed=true; reject sets status=REJECTED (confirmed stays false) so
     * it's distinguishable from an unreviewed PENDING/ERROR upload.
     */
    public function review(string $id, OcrUploadReviewRequest $request): JsonResponse
    {
        $located = $this->locate($id);
        if (! $located) {
            return $this->notFound();
        }

        $data = $request->validated();
        $now = Carbon::now('Asia/Manila')->toDateTimeString();

        $merged = array_merge($located['data'], [
            'upload_id' => $id,
            'confirmed' => $data['action'] === 'approve',
            'confirmed_by' => $data['confirmed_by'],
            'confirmed_at' => $now,
        ]);

        if ($data['action'] === 'reject') {
            $merged['status'] = 'REJECTED';
        }

        $this->updateRowAt($located['rowNumber'], $merged);

        return response()->json([
            'success' => true,
            'message' => $data['action'] === 'approve'
                ? 'OCR upload approved successfully.'
                : 'OCR upload rejected successfully.',
            'data' => $this->presentRow($merged),
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

        $paths = $this->resolvePaths($located['data']['image_path'] ?? null);
        if (! empty($paths) && $firebase) {
            try {
                $this->deleteStoredImages($firebase->storage()->getBucket(), $paths);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete image from Firebase.',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        $this->deleteRowAt($located['rowNumber']);

        return response()->json([
            'success' => true,
            'message' => 'OCR upload deleted successfully.',
        ]);
    }

    /**
     * Shape one OcrUploads row for API responses: parse ocr_result_raw back
     * into the structured object Claude actually extracted (document_type,
     * vendor_name, lines, subtotal, tax_amount, total_with_tax, notes,
     * warnings, ...) instead of leaving it as the JSON-encoded string the
     * sheet cell stores it as. Falls back to the raw value if it isn't
     * valid JSON (e.g. not yet processed).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row): array
    {
        if (! empty($row['ocr_result_raw']) && is_string($row['ocr_result_raw'])) {
            $decoded = json_decode($row['ocr_result_raw'], true);
            if (is_array($decoded)) {
                $row['ocr_result_raw'] = $decoded;
            }
        }

        return $row;
    }

    /**
     * Fill site_name / subcontractor_name from the source sheets whenever the
     * matching id is present. The sheet's onEdit script only fires on manual
     * edits, so backend-created rows would otherwise leave these blank.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveNames(array $data): array
    {
        if (! empty($data['site_id'])) {
            $data['site_name'] = $this->lookupName(
                'ConstructionSites',
                'site_id',
                $data['site_id'],
                'site_name'
            );
        }

        if (! empty($data['subcontractor_id'])) {
            $data['subcontractor_name'] = $this->lookupName(
                'SubContractors',
                'subcontractor_id',
                $data['subcontractor_id'],
                'company_name'
            );
        }

        return $data;
    }

    /**
     * Look up a single column value from a source tab by matching an id column.
     * Returns '' when the source tab or matching row is not found.
     */
    private function lookupName(string $sheetName, string $idColumn, string $idValue, string $nameColumn): string
    {
        try {
            $row = collect($this->sheet->getRowsAsAssoc($this->spreadsheetId(), $sheetName))
                ->firstWhere($idColumn, $idValue);

            return $row[$nameColumn] ?? '';
        } catch (\Throwable $e) {
            // Name resolution is best-effort — never fail the upload over it.
            Log::warning('OCR name lookup failed.', ['sheet' => $sheetName, 'error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Send every page/shot of one OCR upload (images, PDFs, and/or
     * extracted-text entries from DOCX/Excel/CSV) to Claude in a single
     * request — multi-page/multi-shot = one document, same as the
     * invoice-document pipeline. Returns the OCR result columns to merge
     * into the row, or an empty array when the LLM is skipped (so the
     * upload still succeeds).
     *
     * Fills three columns:
     *   - ocr_result_raw    → the full extraction JSON
     *   - ocr_result_amount → total_with_tax, falling back to subtotal or the sum of lines
     *   - ocr_result_date   → the extracted issue_date
     *
     * @param  array<int, array{media_type: string, base64?: string, text?: string}>  $files
     * @return array<string, mixed>
     */
    private function extractOcr(AnthropicVisionService $vision, array $files): array
    {
        $files = array_values(array_filter($files));

        if (! $vision->isEnabled() || empty($files)) {
            return [];
        }

        $extracted = $vision->extractInvoice($files);

        if ($extracted === null) {
            return [
                'status' => 'ERROR',
                'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
            ];
        }

        return [
            'ocr_result_raw' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
            'ocr_result_amount' => $this->deriveAmount($extracted) ?: null,
            'ocr_result_date' => $extracted['issue_date'] ?? null,
            // PENDING here means "extraction finished, awaiting human review"
            // — see the review() action above for the approve/reject step.
            'status' => 'PENDING',
            'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
        ];
    }

    /**
     * ocr_uploads only has a single amount column (unlike invoice_documents'
     * subtotal/tax/total split), so collapse the richer extraction down to
     * one number: prefer the tax-inclusive total, then the tax-exclusive
     * subtotal, then fall back to summing the extracted lines.
     */
    private function deriveAmount(array $extracted): ?int
    {
        if (is_numeric($extracted['total_with_tax'] ?? null)) {
            return (int) $extracted['total_with_tax'];
        }

        if (is_numeric($extracted['subtotal'] ?? null)) {
            return (int) $extracted['subtotal'];
        }

        $lineSum = array_sum(array_map(
            fn ($line) => (float) ($line['amount_with_tax'] ?? $line['amount'] ?? 0),
            $extracted['lines'] ?? []
        ));

        return $lineSum > 0 ? (int) $lineSum : null;
    }

    /**
     * Pull the newly-added images out of the validated request data. Accepts
     * either `images_base64` (array of base64/data-URI strings — the current
     * frontend contract) or the legacy single `image_base64` field —
     * `images_base64` takes priority when both are present.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function collectNewImages(array $data): array
    {
        if (! empty($data['images_base64']) && is_array($data['images_base64'])) {
            return array_values(array_filter($data['images_base64']));
        }

        return ! empty($data['image_base64']) ? [$data['image_base64']] : [];
    }

    /**
     * Collapse a list of stored paths back to the legacy single-string shape
     * when there is only one, so existing single-image consumers of
     * image_path keep working. Multiple images fall back to a plain array,
     * which SheetResourceController::toRow JSON-encodes for the sheet cell.
     *
     * @param  array<int, string>  $paths
     * @return string|array<int, string>
     */
    private function normalizePaths(array $paths): string|array
    {
        return count($paths) === 1 ? $paths[0] : $paths;
    }

    /**
     * Decode a stored image_path cell back into a list of URLs. Handles the
     * legacy plain-string shape (single image), a JSON-encoded array
     * (multiple images), and an already-decoded array.
     */
    private function resolvePaths(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        if (is_array($raw)) {
            return array_values(array_filter($raw));
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded)) : [(string) $raw];
    }

    /**
     * Delete every given public Firebase URL from the ocr_uploads/ prefix.
     * Best-effort per file — a missing/already-deleted object is not an error.
     *
     * @param  array<int, string>  $paths
     */
    private function deleteStoredImages(Bucket $bucket, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $parsed = parse_url($path);
                $filePath = ltrim(str_replace('/'.$bucket->name().'/', '', $parsed['path'] ?? ''), '/');
                if ($filePath === '') {
                    continue;
                }

                $object = $bucket->object($filePath);
                if ($object->exists()) {
                    $object->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Firebase delete failed.', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Decode, validate, and upload every newly-added file to Firebase,
     * building both the public URL list (for image_path) and the vision-file
     * list Claude's vision API needs — decoded once and reused for both, so
     * we never re-fetch what we just uploaded.
     *
     * @param  array<int, string>  $rawImages
     * @return array{paths: array<int, string>, files: array<int, array{media_type: string, base64?: string, text?: string}>}|JsonResponse
     */
    private function processNewImages(FirebaseService $firebase, array $rawImages, string $uploadedBy): array|JsonResponse
    {
        $bucket = $firebase->storage()->getBucket();
        $paths = [];
        $files = [];

        foreach ($rawImages as $index => $dataUri) {
            $decoded = $this->decodeImage($dataUri);
            if ($decoded === null) {
                return response()->json([
                    'success' => false,
                    'message' => "File #{$index} is not a supported file type.",
                ], 422);
            }

            $uploaded = $this->uploadDecodedImage($bucket, $decoded, $uploadedBy, $index);
            if ($uploaded instanceof JsonResponse) {
                return $uploaded;
            }

            $paths[] = $uploaded;
            $files[] = $this->buildVisionFile($decoded);
        }

        return ['paths' => $paths, 'files' => $files];
    }

    /**
     * Build one Claude-ready file entry from a decoded upload. Images and
     * PDFs go straight through as base64 attachments (what Claude's vision
     * API accepts natively); DOCX/Excel/CSV have no such support, so their
     * text is extracted server-side first and sent as a plain text block.
     *
     * @param  array{mime: string, base64: string}  $decoded
     * @return array{media_type: string, base64?: string, text?: string}
     */
    private function buildVisionFile(array $decoded): array
    {
        if (! in_array($decoded['mime'], self::TEXT_EXTRACTED_MIME_TYPES, true)) {
            return ['media_type' => $decoded['mime'], 'base64' => $decoded['base64']];
        }

        $raw = base64_decode($decoded['base64']);

        $text = match ($decoded['mime']) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocxText($raw),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $this->extractSpreadsheetText($raw, 'xlsx'),
            'application/vnd.ms-excel' => $this->extractSpreadsheetText($raw, 'xls'),
            'text/csv' => $this->extractCsvText($raw),
            default => '',
        };

        return ['media_type' => 'text/plain', 'text' => $text];
    }

    /**
     * Extract plain text from a .docx file via PhpWord. Covers paragraphs,
     * text runs, and table cells — good enough for an OCR prefill, not a
     * full-fidelity document reader.
     */
    private function extractDocxText(string $raw): string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'ocr_docx_');
        unlink($tmpBase);
        $tmpPath = $tmpBase.'.docx';
        file_put_contents($tmpPath, $raw);

        try {
            $phpWord = IOFactory::load($tmpPath);
            $lines = [];

            foreach ($phpWord->getSections() as $section) {
                $this->collectPhpWordText($section, $lines);
            }

            return trim(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::warning('DOCX text extraction failed.', ['error' => $e->getMessage()]);

            return '';
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function collectPhpWordText(mixed $container, array &$lines): void
    {
        if (! method_exists($container, 'getElements')) {
            return;
        }

        foreach ($container->getElements() as $element) {
            if ($element instanceof Text) {
                $lines[] = $element->getText();
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->collectPhpWordText($cell, $lines);
                    }
                }
            } elseif (method_exists($element, 'getElements')) {
                $this->collectPhpWordText($element, $lines);
            }
        }
    }

    /**
     * Extract every sheet's cells as tab-separated text via PhpSpreadsheet.
     */
    private function extractSpreadsheetText(string $raw, string $extension): string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'ocr_xls_');
        unlink($tmpBase);
        $tmpPath = $tmpBase.'.'.$extension;
        file_put_contents($tmpPath, $raw);

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
            $lines = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    $line = implode("\t", array_map(fn ($cell) => trim((string) ($cell ?? '')), $row));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }

            return trim(implode("\n", $lines));
        } catch (\Throwable $e) {
            Log::warning('Spreadsheet text extraction failed.', ['error' => $e->getMessage()]);

            return '';
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * CSV is already plain text — just normalize the encoding. Japanese CSV
     * exports are frequently Shift-JIS rather than UTF-8, so detect and
     * convert when needed.
     */
    private function extractCsvText(string $raw): string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $detected = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP'], true);
            if ($detected && $detected !== 'UTF-8') {
                $raw = mb_convert_encoding($raw, 'UTF-8', $detected);
            }
        }

        return trim($raw);
    }

    /**
     * @return array{mime: string, base64: string}|null
     */
    private function decodeImage(string $dataUri): ?array
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
    private function uploadDecodedImage(Bucket $bucket, array $decoded, string $uploadedBy, int $index): string|JsonResponse
    {
        $extension = match ($decoded['mime']) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'text/csv' => 'csv',
            default => 'jpg',
        };

        $raw = base64_decode($decoded['base64']);
        $fileName = 'ocr_uploads/'.$uploadedBy.'_'.Carbon::now('Asia/Manila')->format('Ymd_His_u').'_'.$index.'.'.$extension;

        try {
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

    /**
     * Re-download an already-uploaded (kept) file so it can be included in a
     * fresh Claude extraction alongside newly-added pages. Best-effort —
     * returns null on any failure so one unreachable file doesn't fail the
     * whole update.
     *
     * @return array{media_type: string, base64?: string, text?: string}|null
     */
    private function fetchAsVisionFile(string $url): ?array
    {
        try {
            $response = Http::timeout(30)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $mime = strtolower((string) $response->header('Content-Type')) ?: $this->guessMimeFromUrl($url);
            $mime = strtok($mime, ';'); // strip a "; charset=..." suffix if present

            if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                return null;
            }

            return $this->buildVisionFile(['mime' => $mime, 'base64' => base64_encode($response->body())]);
        } catch (\Throwable $e) {
            Log::warning('Failed to re-fetch kept image for OCR re-extraction.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function guessMimeFromUrl(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            default => 'image/jpeg',
        };
    }

    // ===========================================================================
    // Invoice documents — CRUD + confirm (1 upload = 1 document, 1 document = N
    // site lines). See the constants block above for the sheet/header layout.
    // ===========================================================================

    public function invoiceIndex(Request $request): JsonResponse
    {
        $rows = $this->safeReadSheet(self::INVOICE_SHEET);

        if ($status = $request->query('status')) {
            $rows = array_values(array_filter($rows, fn ($row) => ($row['status'] ?? '') === $status));
        }

        if ($uploadedBy = $request->query('uploaded_by')) {
            $rows = array_values(array_filter($rows, fn ($row) => ($row['uploaded_by'] ?? '') === $uploadedBy));
        }

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

    public function invoiceShow(string $id, ?InvoiceNameMatchingService $matcher = null): JsonResponse
    {
        $matcher ??= app(InvoiceNameMatchingService::class);

        $document = collect($this->safeReadSheet(self::INVOICE_SHEET))->firstWhere('document_id', $id);
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

    public function invoiceStore(
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
            $decoded = $this->decodeInvoiceFile($file['data']);
            if ($decoded === null) {
                return response()->json([
                    'success' => false,
                    'message' => "File #{$i} is not a supported image or PDF.",
                ], 422);
            }

            $uploaded = $this->uploadInvoiceFile($firebase, $decoded, $uploadedBy ?? 'unknown', $i);
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
        $extracted = null;
        $runsVision = $vision->isEnabled();

        Log::info('Invoice document upload received.', [
            'document_id' => $documentId,
            'file_count' => count($files),
            'vision_enabled' => $runsVision,
        ]);

        try {
            if (! $runsVision) {
                $document['status'] = 'NEEDS_REVIEW';
                $document['warnings'] = ['LLM extraction is not configured; please enter details manually.'];
                $this->appendInvoiceDocument($document);
            } else {
                // Write the row as PROCESSING before calling the LLM, then update
                // it once extraction finishes, so other clients polling the list
                // see the in-progress state rather than the row appearing only
                // after extraction completes.
                $document['status'] = 'PROCESSING';
                $this->appendInvoiceDocument($document);
                $located = $this->locateInvoiceDocument($documentId);

                $extracted = $vision->extractInvoice($files);

                Log::info('Invoice extraction result.', [
                    'document_id' => $documentId,
                    'extracted' => $extracted,
                ]);

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
                    $this->updateInvoiceDocumentAt($located['rowNumber'], $document);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to save invoice document to the spreadsheet.', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save invoice document.',
                'error' => $e->getMessage(),
            ], 500);
        }

        Log::info('Invoice document saved.', [
            'document_id' => $documentId,
            'status' => $document['status'],
            'line_count' => count($lines),
        ]);

        event(new InvoiceDocumentEvent($document, strtolower($document['status'])));

        return response()->json([
            'success' => true,
            'message' => 'Invoice document created successfully.',
            'data' => $document + [
                'lines' => $lines,
                'vendor_candidates' => $vendorCandidates,
                'claude_response' => $extracted,
            ],
        ], 201);
    }

    public function invoiceUpdate(InvoiceDocumentUpdateRequest $request, string $id): JsonResponse
    {
        $located = $this->locateInvoiceDocument($id);
        if (! $located) {
            return $this->notFound();
        }

        $data = $request->validated();
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        $merged = array_merge($located['data'], $data);
        $merged['document_id'] = $id;

        $this->updateInvoiceDocumentAt($located['rowNumber'], $merged);

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

    public function invoiceConfirm(string $id, InvoiceDocumentConfirmRequest $request, InvoiceNameMatchingService $matcher): JsonResponse
    {
        $located = $this->locateInvoiceDocument($id);
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

        $this->updateInvoiceDocumentAt($located['rowNumber'], $document);

        event(new InvoiceDocumentEvent($document, 'confirmed'));

        return response()->json([
            'success' => true,
            'message' => 'Invoice document confirmed successfully.',
            'data' => $document + ['lines' => $this->linesFor($id)],
        ]);
    }

    public function invoiceDestroy(Request $request, ?string $id = null, ?FirebaseService $firebase = null): JsonResponse
    {
        if ($id === null) {
            return $this->notFound('Id is required.');
        }

        $located = $this->locateInvoiceDocument($id);
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
        $this->sheet->deleteRow($this->spreadsheetId(), self::INVOICE_SHEET, $located['rowNumber']);

        return response()->json([
            'success' => true,
            'message' => 'Invoice document deleted successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // InvoiceDocuments sheet helpers — parallel to SheetResourceController's
    // all()/locate()/appendRow()/updateRowAt()/toRow(), but scoped to
    // INVOICE_SHEET/INVOICE_HEADERS instead of this class's OcrUploads pair.
    // -------------------------------------------------------------------------

    private function locateInvoiceDocument(string $id): ?array
    {
        $rows = $this->safeReadSheet(self::INVOICE_SHEET);
        foreach ($rows as $index => $row) {
            if (($row['document_id'] ?? null) === $id) {
                return ['rowNumber' => $index + 2, 'data' => $row];
            }
        }

        return null;
    }

    private function appendInvoiceDocument(array $data): void
    {
        $this->sheet->appendRow($this->spreadsheetId(), self::INVOICE_SHEET, $this->toInvoiceRow($data));
    }

    private function updateInvoiceDocumentAt(int $rowNumber, array $data): void
    {
        $this->sheet->updateRow($this->spreadsheetId(), self::INVOICE_SHEET, $rowNumber, $this->toInvoiceRow($data));
    }

    private function toInvoiceRow(array $data): array
    {
        return array_map(function ($h) use ($data) {
            $v = $data[$h] ?? '';
            if (is_array($v) || is_object($v)) {
                return json_encode($v, JSON_UNESCAPED_UNICODE);
            }

            return (string) ($v ?? '');
        }, self::INVOICE_HEADERS);
    }

    // -------------------------------------------------------------------------
    // InvoiceLines helpers — a second sheet tab, addressed directly via
    // $this->sheet since SheetResourceController's row helpers are scoped to
    // this class's OcrUploads sheet/headers pair.
    // -------------------------------------------------------------------------

    private function allLines(): array
    {
        return $this->safeReadSheet(self::LINES_SHEET);
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
    // Invoice file handling — images/PDFs only (no docx/xlsx/csv support,
    // unlike the ocr-uploads decodeImage()/uploadDecodedImage() above).
    // -------------------------------------------------------------------------

    /**
     * @return array{mime: string, base64: string}|null
     */
    private function decodeInvoiceFile(string $dataUri): ?array
    {
        if (! preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $dataUri, $m)) {
            return null;
        }

        $mime = strtolower($m[1]);
        if (! in_array($mime, self::INVOICE_ALLOWED_MIME_TYPES, true)) {
            return null;
        }

        $raw = base64_decode($m[2], true);
        if ($raw === false || strlen($raw) < 100) {
            return null;
        }

        return ['mime' => $mime, 'base64' => $m[2]];
    }

    /**
     * Upload one decoded invoice file to Firebase. Returns the public URL or a JsonResponse on error.
     *
     * @param  array{mime: string, base64: string}  $decoded
     */
    private function uploadInvoiceFile(FirebaseService $firebase, array $decoded, string $uploadedBy, int $index): JsonResponse|string
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

    // ===========================================================================
    // Invoice summary — read-only aggregation over InvoiceDocuments/InvoiceLines.
    // Per the aggregation policy: only CONFIRMED INVOICE/MONTHLY_STATEMENT
    // documents count, both tax bases are always exposed, and totals are
    // computed on demand from line-level data rather than baked into a
    // summary table.
    // ===========================================================================

    /**
     * GET /invoice-summary?month=YYYY-MM
     * Per-site totals (both tax bases) across confirmed invoice-type
     * documents. Omit `month` for a running cumulative total across all
     * billing months.
     */
    public function invoiceSummary(Request $request): JsonResponse
    {
        $month = $request->query('month') ?: null;
        $lines = $this->confirmedLines($month);

        $sites = [];
        foreach ($lines as $line) {
            $siteId = $line['site_id'] ?: '';
            $sites[$siteId] ??= [
                'site_id' => $siteId ?: null,
                'site_name' => $line['site_name'] ?: ($line['site_name_raw'] ?: '未確定'),
                'amount' => 0,
                'amount_with_tax' => 0,
            ];

            $sites[$siteId]['amount'] += (float) ($line['amount'] ?: 0);
            $sites[$siteId]['amount_with_tax'] += (float) ($line['amount_with_tax'] ?: 0);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'sites' => array_values($sites),
            ],
        ]);
    }

    /**
     * GET /invoice-summary/sites/{site_id}?month=YYYY-MM
     * Per-vendor breakdown for one site: cumulative total through last
     * month, this month's per-vendor totals, and this month's individual
     * documents. `month` defaults to the current month when omitted.
     */
    public function invoiceSummarySites(string $siteId, Request $request): JsonResponse
    {
        $month = $request->query('month') ?: Carbon::now('Asia/Manila')->format('Y-m');
        $lines = $this->confirmedLines(null, $siteId);

        $siteName = null;
        $throughLastMonth = [];
        $thisMonth = [];
        $documents = [];

        foreach ($lines as $line) {
            $siteName ??= ($line['site_name'] ?: $line['site_name_raw']) ?: null;

            $document = $line['_document'];
            $billingMonth = $document['billing_month'] ?? '';

            if ($billingMonth !== '' && $billingMonth < $month) {
                $this->addVendorTotal($throughLastMonth, $document, $line);
            } elseif ($billingMonth === $month) {
                $this->addVendorTotal($thisMonth, $document, $line);

                $documents[] = [
                    'document_id' => $document['document_id'] ?? '',
                    'issue_date' => $document['issue_date'] ?? '',
                    'vendor_name' => $document['subcontractor_name'] ?: ($document['vendor_name_raw'] ?? ''),
                    'amount' => (float) ($line['amount'] ?: 0),
                    'amount_with_tax' => (float) ($line['amount_with_tax'] ?: 0),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'month' => $month,
                'through_last_month' => array_values($throughLastMonth),
                'this_month' => array_values($thisMonth),
                'documents' => $documents,
            ],
        ]);
    }

    /**
     * Accumulate one line's amounts into a per-vendor totals bucket, keyed
     * by subcontractor_id.
     *
     * @param  array<string, array<string, mixed>>  $totals  mutated in place
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $line
     */
    private function addVendorTotal(array &$totals, array $document, array $line): void
    {
        $vendorId = $document['subcontractor_id'] ?: '';
        $totals[$vendorId] ??= [
            'subcontractor_id' => $vendorId ?: null,
            'subcontractor_name' => $document['subcontractor_name'] ?: (($document['vendor_name_raw'] ?? '') ?: '未確定'),
            'amount' => 0,
            'amount_with_tax' => 0,
        ];

        $totals[$vendorId]['amount'] += (float) ($line['amount'] ?: 0);
        $totals[$vendorId]['amount_with_tax'] += (float) ($line['amount_with_tax'] ?: 0);
    }

    /**
     * Every invoice line belonging to a CONFIRMED INVOICE/MONTHLY_STATEMENT
     * document, each annotated with its parent document under `_document`.
     * Optionally filtered to one billing month and/or one site.
     *
     * @return array<int, array<string, mixed>>
     */
    private function confirmedLines(?string $month = null, ?string $siteId = null): array
    {
        $documentsById = [];
        foreach ($this->safeReadSheet(self::INVOICE_SHEET) as $document) {
            if (($document['status'] ?? '') !== 'CONFIRMED') {
                continue;
            }
            if (! in_array($document['document_type'] ?? '', self::AGGREGATABLE_TYPES, true)) {
                continue;
            }
            if ($month !== null && ($document['billing_month'] ?? '') !== $month) {
                continue;
            }

            $documentsById[$document['document_id'] ?? ''] = $document;
        }

        $lines = [];
        foreach ($this->safeReadSheet(self::LINES_SHEET) as $line) {
            $document = $documentsById[$line['invoice_document_id'] ?? ''] ?? null;
            if ($document === null) {
                continue;
            }
            if ($siteId !== null && ($line['site_id'] ?? '') !== $siteId) {
                continue;
            }

            $line['_document'] = $document;
            $lines[] = $line;
        }

        return $lines;
    }
}
