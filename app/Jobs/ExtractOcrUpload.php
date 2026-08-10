<?php

namespace App\Jobs;

use App\Events\InvoiceDocumentEvent;
use App\Services\GoogleSheetService;
use App\Services\InvoiceExtractionValidator;
use App\Services\InvoiceNameMatchingService;
use App\Services\LlmVisionService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs the LLM-vision extraction (Claude or Gemini, see LlmVisionService) for
 * one OcrUploads row after the HTTP response has already been sent
 * (dispatched with ->afterResponse() — this app has no real database/Redis,
 * so there's no persistent queue worker; Laravel runs ShouldQueue jobs
 * dispatched this way in-process via the built-in "sync" connection instead,
 * which still preserves $tries/failed() handling). The request only uploads
 * the file(s) and writes a PROCESSING row; this job does the (up to ~120s)
 * LLM call, updates that row, and mirrors the same extraction into
 * InvoiceDocuments/InvoiceLines (vendor/site matching + multi-line support)
 * — one LLM call populates both sheets. A batch can contain more than one
 * vendor's invoice (see LlmVisionService PROMPT rule 1), so it can write more
 * than one InvoiceDocuments row per upload; every row it writes carries this
 * upload's ID via the `upload_id` column, and the first row also reuses it
 * as its own document_id so the common single-vendor case still correlates
 * 1:1 as before.
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
        'document_id', 'upload_id', 'uploaded_by', 'subcontractor_id', 'subcontractor_name',
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
        LlmVisionService $vision,
        InvoiceNameMatchingService $matcher,
        InvoiceExtractionValidator $validator,
        GoogleSheetService $sheet
    ): void {
        // This job runs in-process via ->afterResponse() (no real queue
        // worker), so $timeout above is never enforced by Laravel — PHP's
        // own max_execution_time ini limit (commonly 60s) applies instead
        // and will fatal the whole request mid-extraction otherwise.
        set_time_limit($this->timeout);

        $located = $this->locate($sheet);
        if (! $located) {
            Log::warning('ExtractOcrUpload: row not found, skipping.', ['upload_id' => $this->uploadId]);

            return;
        }

        Log::info('ExtractOcrUpload: row located, starting extraction.', [
            'upload_id' => $this->uploadId,
            'decoded_file_count' => count($this->decodedFiles),
            'kept_path_count' => count($this->keptPaths),
        ]);

        $files = array_merge(
            $vision->filesFromDecoded($this->decodedFiles),
            $vision->filesFromUrls($this->keptPaths)
        );

        Log::info('ExtractOcrUpload: vision files prepared.', [
            'upload_id' => $this->uploadId,
            'file_count' => count($files),
            'page_count' => $vision->countPages($files),
        ]);

        $extracted = empty($files) ? null : $vision->extractInvoice($files);

        Log::info('ExtractOcrUpload: LLM extraction finished.', [
            'upload_id' => $this->uploadId,
            'result' => $extracted,
        ]);

        // $extracted is a list — a batch can contain several vendors'
        // invoices (see LlmVisionService PROMPT rule 1). ocr_result_amount
        // sums every vendor found; ocr_result_date uses the first one's,
        // since OcrUploads only has room for a single summary date.
        $update = $extracted === null
            ? ['status' => 'ERROR']
            : [
                'ocr_result_raw' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
                'ocr_result_amount' => $this->deriveAmount($extracted) ?: null,
                'ocr_result_date' => $extracted[0]['issue_date'] ?? null,
                // PENDING here means "extraction finished, awaiting human review"
                // — see OcrUploadController::review() for the approve/reject step.
                'status' => 'PENDING',
            ];
        $update['processed_at'] = Carbon::now('Asia/Manila')->toDateTimeString();

        $this->updateRow($sheet, $located, $update);

        Log::info('ExtractOcrUpload: OcrUploads row updated.', [
            'upload_id' => $this->uploadId,
            'status' => $update['status'],
        ]);

        $this->syncInvoiceDocument($sheet, $matcher, $validator, $located['data'], $extracted);

        Log::info('ExtractOcrUpload: finished.', ['upload_id' => $this->uploadId]);
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
     * Mirror this upload's extraction into InvoiceDocuments/InvoiceLines. A
     * batch can contain several vendors' invoices at once (see
     * LlmVisionService PROMPT rule 1: one row per distinct subcontractor
     * found), so $extractedList is a list — one InvoiceDocuments row (+ its
     * own InvoiceLines) is written per vendor, all tagged with this upload's
     * ID via the `upload_id` column so the whole set can be found and
     * replaced together on re-extraction (e.g. OcrUploadController::update()
     * re-running vision after new/changed pages — the number of vendors
     * found can differ between runs, so the old set is deleted first rather
     * than diffed in place). The first vendor reuses the upload_id as its
     * own document_id, preserving the common single-vendor case's existing
     * 1:1 correlation; any additional vendors get a fresh document_id.
     *
     * @param  array<string, mixed>  $uploadRow
     * @param  array<int, array<string, mixed>>|null  $extractedList
     */
    private function syncInvoiceDocument(
        GoogleSheetService $sheet,
        InvoiceNameMatchingService $matcher,
        InvoiceExtractionValidator $validator,
        array $uploadRow,
        ?array $extractedList
    ): void {
        $now = Carbon::now('Asia/Manila')->toDateTimeString();

        $this->deleteInvoiceDocumentsFor($sheet, $this->uploadId);

        $base = [
            'upload_id' => $this->uploadId,
            'uploaded_by' => $uploadRow['uploaded_by'] ?? '',
            'category_id' => $uploadRow['category_id'] ?? '',
            'file_path' => $uploadRow['image_path'] ?? '',
            'note' => $uploadRow['note'] ?? '',
            'uploaded_at' => $uploadRow['uploaded_at'] ?? $now,
            'processed_at' => $now,
        ];

        if ($extractedList === null) {
            $document = $base + ['document_id' => $this->uploadId, 'status' => 'ERROR'];
            $this->appendInvoiceDocument($sheet, $document);
            event(new InvoiceDocumentEvent($document, 'error'));

            Log::info('ExtractOcrUpload: InvoiceDocuments/InvoiceLines sync finished.', [
                'upload_id' => $this->uploadId,
                'document_count' => 1,
                'status' => 'ERROR',
            ]);

            return;
        }

        foreach ($extractedList as $i => $extracted) {
            $documentId = $i === 0 ? $this->uploadId : (string) Str::uuid();

            $warnings = $validator->validate($extracted);
            [$subcontractorId, $subcontractorName] = $this->resolveSubcontractor($matcher, $uploadRow, $extracted);

            $document = $base + [
                'document_id' => $documentId,
                'vendor_name_raw' => $extracted['vendor_name'] ?? '',
                'issue_date' => $extracted['issue_date'] ?? '',
                'billing_month' => $extracted['billing_month'] ?? '',
                'document_type' => $extracted['document_type'] ?? '',
                'subtotal' => $extracted['subtotal'] ?? '',
                'tax_amount' => $extracted['tax_amount'] ?? '',
                'total_with_tax' => $extracted['total_with_tax'] ?? '',
                'ocr_result_raw' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
                'status' => 'NEEDS_REVIEW',
                'warnings' => $warnings,
                'subcontractor_id' => $subcontractorId,
                'subcontractor_name' => $subcontractorName,
            ];

            $this->appendInvoiceDocument($sheet, $document);

            foreach (($extracted['lines'] ?? []) as $extractedLine) {
                [$siteId, $siteName] = $this->resolveSite($matcher, $uploadRow, $extractedLine);

                $this->appendInvoiceLine($sheet, [
                    'line_id' => (string) Str::uuid(),
                    'invoice_document_id' => $documentId,
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'site_name_raw' => $extractedLine['site_name'] ?? '',
                    'amount' => $extractedLine['amount'] ?? '',
                    'amount_with_tax' => $extractedLine['amount_with_tax'] ?? '',
                ]);
            }

            event(new InvoiceDocumentEvent($document, strtolower($document['status'])));
        }

        Log::info('ExtractOcrUpload: InvoiceDocuments/InvoiceLines sync finished.', [
            'upload_id' => $this->uploadId,
            'document_count' => count($extractedList),
            'status' => 'NEEDS_REVIEW',
        ]);
    }

    /**
     * Resolve the subcontractor for one extracted vendor. The user can
     * already pre-assign a subcontractor directly on the OcrUploads row at
     * upload time (OcrUploadController accepts subcontractor_id/
     * subcontractor_name there) — when present, that explicit choice takes
     * precedence over the fuzzy vendor-name auto-match for every document
     * extracted from this upload (same rule as resolveSite() below: the
     * upload belongs to one pre-known subcontractor context regardless of
     * how many vendor-labeled documents the LLM parses out of it). Falls
     * back to auto-matching a document's own vendor_name when no
     * subcontractor was pre-assigned on the upload.
     *
     * @param  array<string, mixed>  $uploadRow
     * @param  array<string, mixed>  $extracted
     * @return array{0: string, 1: string} [subcontractor_id, subcontractor_name]
     */
    private function resolveSubcontractor(InvoiceNameMatchingService $matcher, array $uploadRow, array $extracted): array
    {
        $preselectedId = (string) ($uploadRow['subcontractor_id'] ?? '');

        if ($preselectedId !== '') {
            $name = $matcher->subcontractorName($preselectedId) ?? (string) ($uploadRow['subcontractor_name'] ?? '');

            return [$preselectedId, $name];
        }

        $topVendor = $matcher->candidatesForSubcontractor($extracted['vendor_name'] ?? null)[0] ?? null;
        $vendorPreselect = $topVendor && ! empty($topVendor['preselect']);

        return $vendorPreselect ? [$topVendor['id'], $topVendor['name']] : ['', ''];
    }

    /**
     * Resolve the site for one extracted line. The user can already
     * pre-assign a site directly on the OcrUploads row at upload time
     * (OcrUploadController accepts site_id/site_name there) — when present,
     * that explicit choice takes precedence over the fuzzy site-name
     * auto-match for every line in this upload: the upload is for one
     * physical site regardless of how many vendors/lines the LLM parses out
     * of it. Falls back to auto-matching a line's own site_name when no site
     * was pre-assigned on the upload.
     *
     * @param  array<string, mixed>  $uploadRow
     * @param  array<string, mixed>  $extractedLine
     * @return array{0: string, 1: string} [site_id, site_name]
     */
    private function resolveSite(InvoiceNameMatchingService $matcher, array $uploadRow, array $extractedLine): array
    {
        $preselectedId = (string) ($uploadRow['site_id'] ?? '');

        if ($preselectedId !== '') {
            $name = $matcher->siteName($preselectedId) ?? (string) ($uploadRow['site_name'] ?? '');

            return [$preselectedId, $name];
        }

        $topSite = $matcher->candidatesForSite($extractedLine['site_name'] ?? null)[0] ?? null;
        $sitePreselect = $topSite && ! empty($topSite['preselect']);

        return $sitePreselect ? [$topSite['id'], $topSite['name']] : ['', ''];
    }

    /**
     * ocr_uploads only has a single amount column (unlike invoice_documents'
     * subtotal/tax/total split), so collapse the richer, possibly-multi-vendor
     * extraction down to one number: sum every vendor's derived amount
     * (tax-inclusive total, falling back to tax-exclusive subtotal, falling
     * back to summing that vendor's lines).
     *
     * @param  array<int, array<string, mixed>>  $extractedList
     */
    private function deriveAmount(array $extractedList): ?int
    {
        $total = array_sum(array_map($this->deriveAmountForOne(...), $extractedList));

        return $total > 0 ? (int) $total : null;
    }

    private function deriveAmountForOne(array $extracted): float
    {
        if (is_numeric($extracted['total_with_tax'] ?? null)) {
            return (float) $extracted['total_with_tax'];
        }

        if (is_numeric($extracted['subtotal'] ?? null)) {
            return (float) $extracted['subtotal'];
        }

        return array_sum(array_map(
            fn ($line) => (float) ($line['amount_with_tax'] ?? $line['amount'] ?? 0),
            $extracted['lines'] ?? []
        ));
    }

    /**
     * Delete every existing InvoiceDocuments row (and its InvoiceLines) that
     * traces back to this upload, before a (re-)sync writes the current
     * extraction's rows — a changed vendor count between runs would
     * otherwise leave stale rows behind. Matches on the `upload_id` column,
     * plus `document_id` for rows written before that column existed (the
     * common single-vendor case, where document_id was the upload_id).
     */
    private function deleteInvoiceDocumentsFor(GoogleSheetService $sheet, string $uploadId): void
    {
        $rows = $sheet->getRowsAsAssoc($this->spreadsheetId(), self::INVOICE_SHEET);
        $toDelete = [];

        foreach ($rows as $index => $row) {
            if (($row['upload_id'] ?? null) === $uploadId || ($row['document_id'] ?? null) === $uploadId) {
                $toDelete[] = ['rowNumber' => $index + 2, 'documentId' => $row['document_id'] ?? null];
            }
        }

        // Delete bottom-up so earlier row numbers don't shift mid-loop.
        usort($toDelete, fn ($a, $b) => $b['rowNumber'] <=> $a['rowNumber']);

        foreach ($toDelete as $entry) {
            if ($entry['documentId'] !== null) {
                $this->deleteInvoiceLinesFor($sheet, $entry['documentId']);
            }
            $sheet->deleteRow($this->spreadsheetId(), self::INVOICE_SHEET, $entry['rowNumber']);
        }
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

    private function appendInvoiceDocument(GoogleSheetService $sheet, array $data): void
    {
        $sheet->appendRow($this->spreadsheetId(), self::INVOICE_SHEET, $this->toRow($data, self::INVOICE_HEADERS));
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
