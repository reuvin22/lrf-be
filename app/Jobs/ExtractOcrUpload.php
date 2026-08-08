<?php

namespace App\Jobs;

use App\Events\InvoiceDocumentEvent;
use App\Services\AnthropicVisionService;
use App\Services\GoogleSheetService;
use App\Services\InvoiceExtractionValidator;
use App\Services\InvoiceNameMatchingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs the Claude Vision extraction for one OcrUploads row after the HTTP
 * response has already been sent (dispatched with ->afterResponse() — this
 * app has no real database/Redis, so there's no persistent queue worker;
 * Laravel runs ShouldQueue jobs dispatched this way in-process via the
 * built-in "sync" connection instead, which still preserves $tries/failed()
 * handling). The request only uploads the file(s) and writes a PROCESSING
 * row; this job does the (up to ~120s) Claude call, updates that row, and
 * mirrors the same extraction into InvoiceDocuments/InvoiceLines (vendor/site
 * matching + multi-line support) — one Claude call populates both sheets.
 * The InvoiceDocuments row reuses this upload's ID as its document_id so the
 * two sheets correlate directly.
 */
class ExtractOcrUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    private const SHEET = 'OcrUploads';

    private const HEADERS = [
        'upload_id', 'uploaded_by', 'category_id',
        'site_id', 'site_name', 'subcontractor_id', 'subcontractor_name',
        'attendance_id', 'upload_source', 'status', 'image_path',
        'ocr_result_amount', 'ocr_result_date', 'ocr_result_raw',
        'confirmed', 'confirmed_by', 'confirmed_at', 'note',
        'uploaded_at', 'processed_at',
    ];

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

    /**
     * @param  array<int, array{mime: string, base64: string}>  $decodedFiles  freshly-uploaded pages, bytes already in hand — no re-fetch needed
     * @param  array<int, string>  $keptPaths  pre-existing pages the client didn't resubmit (update() only) — only these need fetching by URL
     */
    public function __construct(
        private readonly string $uploadId,
        private readonly array $decodedFiles = [],
        private readonly array $keptPaths = []
    ) {}

    public function handle(
        AnthropicVisionService $vision,
        InvoiceNameMatchingService $matcher,
        InvoiceExtractionValidator $validator,
        GoogleSheetService $sheet
    ): void {
        $located = $this->locate($sheet);
        if (! $located) {
            Log::warning('ExtractOcrUpload: row not found, skipping.', ['upload_id' => $this->uploadId]);

            return;
        }

        $files = array_merge(
            $vision->filesFromDecoded($this->decodedFiles),
            $vision->filesFromUrls($this->keptPaths)
        );
        $extracted = empty($files) ? null : $vision->extractInvoice($files);

        $update = $extracted === null
            ? ['status' => 'ERROR']
            : [
                'ocr_result_raw' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
                'ocr_result_amount' => $this->deriveAmount($extracted) ?: null,
                'ocr_result_date' => $extracted['issue_date'] ?? null,
                // PENDING here means "extraction finished, awaiting human review"
                // — see OcrUploadController::review() for the approve/reject step.
                'status' => 'PENDING',
            ];
        $update['processed_at'] = Carbon::now('Asia/Manila')->toDateTimeString();

        $this->updateRow($sheet, $located, $update);
        $this->syncInvoiceDocument($sheet, $matcher, $validator, $located['data'], $extracted);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ExtractOcrUpload job failed permanently.', [
            'upload_id' => $this->uploadId,
            'error' => $e->getMessage(),
        ]);

        try {
            $sheet = app(GoogleSheetService::class);
            $located = $this->locate($sheet);
            if ($located) {
                $this->updateRow($sheet, $located, [
                    'status' => 'ERROR',
                    'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
                ]);
                $this->syncInvoiceDocument($sheet, app(InvoiceNameMatchingService::class), app(InvoiceExtractionValidator::class), $located['data'], null);
            }
        } catch (\Throwable $inner) {
            Log::error('ExtractOcrUpload: failed to mark row ERROR after job failure.', [
                'upload_id' => $this->uploadId,
                'error' => $inner->getMessage(),
            ]);
        }
    }

    /**
     * Mirror this upload's extraction into InvoiceDocuments/InvoiceLines,
     * reusing the upload_id as document_id so the two sheets correlate.
     * Upserts: creates the row on first extraction, updates it (and replaces
     * its lines) on any re-extraction (e.g. OcrUploadController::update()
     * re-running vision after new/changed pages).
     *
     * @param  array<string, mixed>  $uploadRow
     * @param  array<string, mixed>|null  $extracted
     */
    private function syncInvoiceDocument(
        GoogleSheetService $sheet,
        InvoiceNameMatchingService $matcher,
        InvoiceExtractionValidator $validator,
        array $uploadRow,
        ?array $extracted
    ): void {
        $documentId = $this->uploadId;
        $now = Carbon::now('Asia/Manila')->toDateTimeString();

        $document = [
            'document_id' => $documentId,
            'uploaded_by' => $uploadRow['uploaded_by'] ?? '',
            'category_id' => $uploadRow['category_id'] ?? '',
            'file_path' => $uploadRow['image_path'] ?? '',
            'note' => $uploadRow['note'] ?? '',
            'uploaded_at' => $uploadRow['uploaded_at'] ?? $now,
            'processed_at' => $now,
        ];

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
        }

        $located = $this->locateInvoiceDocument($sheet, $documentId);
        if ($located) {
            $this->updateInvoiceDocumentAt($sheet, $located['rowNumber'], $document);
        } else {
            $this->appendInvoiceDocument($sheet, $document);
        }

        // Re-extraction (update()) replaces the line set entirely rather than
        // appending on top of stale lines from a previous extraction.
        $this->deleteInvoiceLinesFor($sheet, $documentId);

        foreach (($extracted['lines'] ?? []) as $extractedLine) {
            $siteCandidates = $matcher->candidatesForSite($extractedLine['site_name'] ?? null);
            $topSite = $siteCandidates[0] ?? null;
            $sitePreselect = $topSite && ! empty($topSite['preselect']);

            $this->appendInvoiceLine($sheet, [
                'line_id' => (string) Str::uuid(),
                'invoice_document_id' => $documentId,
                'site_id' => $sitePreselect ? $topSite['id'] : '',
                'site_name' => $sitePreselect ? $topSite['name'] : '',
                'site_name_raw' => $extractedLine['site_name'] ?? '',
                'amount' => $extractedLine['amount'] ?? '',
                'amount_with_tax' => $extractedLine['amount_with_tax'] ?? '',
            ]);
        }

        event(new InvoiceDocumentEvent($document, strtolower($document['status'])));
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

    private function spreadsheetId(): string
    {
        return config('services.google_sheets.spreadsheet_id');
    }

    /**
     * @return array{rowNumber: int, data: array<string, mixed>}|null
     */
    private function locate(GoogleSheetService $sheet): ?array
    {
        $rows = $sheet->getRowsAsAssoc($this->spreadsheetId(), self::SHEET);
        foreach ($rows as $index => $row) {
            if (($row['upload_id'] ?? null) === $this->uploadId) {
                return ['rowNumber' => $index + 2, 'data' => $row];
            }
        }

        return null;
    }

    /**
     * @param  array{rowNumber: int, data: array<string, mixed>}  $located
     * @param  array<string, mixed>  $update
     */
    private function updateRow(GoogleSheetService $sheet, array $located, array $update): void
    {
        $merged = array_merge($located['data'], $update);
        $merged['upload_id'] = $this->uploadId;

        $sheet->updateRow($this->spreadsheetId(), self::SHEET, $located['rowNumber'], $this->toRow($merged, self::HEADERS));
    }

    /**
     * @return array{rowNumber: int, data: array<string, mixed>}|null
     */
    private function locateInvoiceDocument(GoogleSheetService $sheet, string $documentId): ?array
    {
        $rows = $sheet->getRowsAsAssoc($this->spreadsheetId(), self::INVOICE_SHEET);
        foreach ($rows as $index => $row) {
            if (($row['document_id'] ?? null) === $documentId) {
                return ['rowNumber' => $index + 2, 'data' => $row];
            }
        }

        return null;
    }

    private function appendInvoiceDocument(GoogleSheetService $sheet, array $data): void
    {
        $sheet->appendRow($this->spreadsheetId(), self::INVOICE_SHEET, $this->toRow($data, self::INVOICE_HEADERS));
    }

    private function updateInvoiceDocumentAt(GoogleSheetService $sheet, int $rowNumber, array $data): void
    {
        $sheet->updateRow($this->spreadsheetId(), self::INVOICE_SHEET, $rowNumber, $this->toRow($data, self::INVOICE_HEADERS));
    }

    private function appendInvoiceLine(GoogleSheetService $sheet, array $data): void
    {
        $sheet->appendRow($this->spreadsheetId(), self::LINES_SHEET, $this->toRow($data, self::LINES_HEADERS));
    }

    private function deleteInvoiceLinesFor(GoogleSheetService $sheet, string $documentId): void
    {
        $rows = $sheet->getRowsAsAssoc($this->spreadsheetId(), self::LINES_SHEET);
        $rowNumbers = [];

        foreach ($rows as $index => $row) {
            if (($row['invoice_document_id'] ?? null) === $documentId) {
                $rowNumbers[] = $index + 2;
            }
        }

        // Delete bottom-up so earlier row numbers don't shift mid-loop.
        rsort($rowNumbers);
        foreach ($rowNumbers as $rowNumber) {
            $sheet->deleteRow($this->spreadsheetId(), self::LINES_SHEET, $rowNumber);
        }
    }

    private function toRow(array $data, array $headers): array
    {
        return array_map(function ($h) use ($data) {
            $v = $data[$h] ?? '';

            return is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? '');
        }, $headers);
    }
}
