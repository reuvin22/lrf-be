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
 * one InvoiceDocuments row after the HTTP response has already been sent
 * (dispatched with ->afterResponse() — this app has no real database/Redis,
 * so there's no persistent queue worker; Laravel runs ShouldQueue jobs
 * dispatched this way in-process via the built-in "sync" connection instead,
 * which still preserves $tries/failed() handling). The request only uploads
 * the file(s) and writes a PROCESSING row; this job does the (up to ~120s)
 * LLM call, runs vendor/site matching, appends InvoiceLines rows, and
 * updates the document row. Mirrors OcrUploadController::invoiceStore()'s
 * now-removed vision branch. Fires the same InvoiceDocumentEvent the
 * frontend already listens for via Pusher once done.
 *
 * A batch can contain more than one vendor's invoice (see LlmVisionService
 * PROMPT rule 1: one result per distinct subcontractor found), so this can
 * update the existing placeholder row (the first vendor, keeping the
 * document_id the controller already responded with) and also append
 * brand-new InvoiceDocuments rows for any additional vendors — every row
 * carries this submission's original document_id via the `upload_id`
 * column so the full set can be found together.
 */
class ExtractInvoiceDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

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
     * @param  array<int, array{mime: string, base64: string}>  $decodedFiles  bytes already in hand from the upload request — no re-fetch needed
     */
    public function __construct(
        private readonly string $documentId,
        private readonly array $decodedFiles
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

        $located = $this->locateDocument($sheet);
        if (! $located) {
            Log::warning('ExtractInvoiceDocument: row not found, skipping.', ['document_id' => $this->documentId]);

            return;
        }

        $document = $located['data'];
        $files = $vision->filesFromDecoded($this->decodedFiles);

        Log::info('ExtractInvoiceDocument: vision files prepared.', [
            'document_id' => $this->documentId,
            'file_count' => count($files),
            'page_count' => $vision->countPages($files),
        ]);

        $extractedList = empty($files) ? null : $vision->extractInvoice($files);

        Log::info('Invoice extraction result.', [
            'document_id' => $this->documentId,
            'extracted' => $extractedList,
        ]);

        $document['upload_id'] = $this->documentId;
        $document['processed_at'] = Carbon::now('Asia/Manila')->toDateTimeString();

        if ($extractedList === null) {
            $document['status'] = 'ERROR';
            $this->updateDocumentAt($sheet, $located['rowNumber'], $document);
            event(new InvoiceDocumentEvent($document, 'error'));

            Log::info('Invoice document processed.', [
                'document_id' => $this->documentId,
                'document_count' => 1,
                'status' => $document['status'],
            ]);

            return;
        }

        foreach ($extractedList as $i => $extracted) {
            // The first vendor updates the existing placeholder row in place
            // (the controller's synchronous response already handed its
            // document_id to the frontend); any additional vendor found in
            // the same batch becomes a brand-new row instead.
            $isPrimary = $i === 0;
            $row = $isPrimary ? $document : array_merge($document, ['document_id' => (string) Str::uuid()]);

            $warnings = $validator->validate($extracted);
            $vendorCandidates = $matcher->candidatesForSubcontractor($extracted['vendor_name'] ?? null);
            $topVendor = $vendorCandidates[0] ?? null;
            $vendorPreselect = $topVendor && ! empty($topVendor['preselect']);

            $row['vendor_name_raw'] = $extracted['vendor_name'] ?? '';
            $row['issue_date'] = $extracted['issue_date'] ?? '';
            $row['billing_month'] = $extracted['billing_month'] ?? '';
            $row['document_type'] = $extracted['document_type'] ?? '';
            $row['subtotal'] = $extracted['subtotal'] ?? '';
            $row['tax_amount'] = $extracted['tax_amount'] ?? '';
            $row['total_with_tax'] = $extracted['total_with_tax'] ?? '';
            $row['ocr_result_raw'] = json_encode($extracted, JSON_UNESCAPED_UNICODE);
            $row['status'] = 'NEEDS_REVIEW';
            $row['warnings'] = $warnings;
            $row['subcontractor_id'] = $vendorPreselect ? $topVendor['id'] : '';
            $row['subcontractor_name'] = $vendorPreselect ? $topVendor['name'] : '';

            if ($isPrimary) {
                $this->updateDocumentAt($sheet, $located['rowNumber'], $row);
            } else {
                $this->appendDocument($sheet, $row);
            }

            foreach (($extracted['lines'] ?? []) as $extractedLine) {
                $siteCandidates = $matcher->candidatesForSite($extractedLine['site_name'] ?? null);
                $topSite = $siteCandidates[0] ?? null;
                $sitePreselect = $topSite && ! empty($topSite['preselect']);

                $this->appendLine($sheet, [
                    'line_id' => (string) Str::uuid(),
                    'invoice_document_id' => $row['document_id'],
                    'site_id' => $sitePreselect ? $topSite['id'] : '',
                    'site_name' => $sitePreselect ? $topSite['name'] : '',
                    'site_name_raw' => $extractedLine['site_name'] ?? '',
                    'amount' => $extractedLine['amount'] ?? '',
                    'amount_with_tax' => $extractedLine['amount_with_tax'] ?? '',
                ]);
            }

            event(new InvoiceDocumentEvent($row, strtolower($row['status'])));
        }

        Log::info('Invoice document processed.', [
            'document_id' => $this->documentId,
            'document_count' => count($extractedList),
            'status' => 'NEEDS_REVIEW',
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ExtractInvoiceDocument job failed permanently.', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);

        try {
            $sheet = app(GoogleSheetService::class);
            $located = $this->locateDocument($sheet);
            if ($located) {
                $document = array_merge($located['data'], [
                    'status' => 'ERROR',
                    'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
                ]);
                $this->updateDocumentAt($sheet, $located['rowNumber'], $document);
                event(new InvoiceDocumentEvent($document, 'error'));
            }
        } catch (\Throwable $inner) {
            Log::error('ExtractInvoiceDocument: failed to mark row ERROR after job failure.', [
                'document_id' => $this->documentId,
                'error' => $inner->getMessage(),
            ]);
        }
    }

    private function spreadsheetId(): string
    {
        return config('services.google_sheets.spreadsheet_id');
    }

    /**
     * @return array{rowNumber: int, data: array<string, mixed>}|null
     */
    private function locateDocument(GoogleSheetService $sheet): ?array
    {
        $rows = $sheet->getRowsAsAssoc($this->spreadsheetId(), self::INVOICE_SHEET);
        foreach ($rows as $index => $row) {
            if (($row['document_id'] ?? null) === $this->documentId) {
                return ['rowNumber' => $index + 2, 'data' => $row];
            }
        }

        return null;
    }

    private function updateDocumentAt(GoogleSheetService $sheet, int $rowNumber, array $data): void
    {
        $data['document_id'] = $this->documentId;
        $sheet->updateRow($this->spreadsheetId(), self::INVOICE_SHEET, $rowNumber, $this->toRow($data, self::INVOICE_HEADERS));
    }

    private function appendDocument(GoogleSheetService $sheet, array $data): void
    {
        $sheet->appendRow($this->spreadsheetId(), self::INVOICE_SHEET, $this->toRow($data, self::INVOICE_HEADERS));
    }

    private function appendLine(GoogleSheetService $sheet, array $data): void
    {
        $sheet->appendRow($this->spreadsheetId(), self::LINES_SHEET, $this->toRow($data, self::LINES_HEADERS));
    }

    private function toRow(array $data, array $headers): array
    {
        return array_map(function ($h) use ($data) {
            $v = $data[$h] ?? '';

            return is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? '');
        }, $headers);
    }
}
