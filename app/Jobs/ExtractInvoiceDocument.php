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
 * Runs the Claude Vision extraction for one InvoiceDocuments row off the
 * request thread — the HTTP request only uploads the file(s) and writes a
 * PROCESSING row; this job does the (up to ~120s) Claude call, runs
 * vendor/site matching, appends InvoiceLines rows, and updates the document
 * row. Mirrors OcrUploadController::invoiceStore()'s now-removed vision
 * branch. Fires the same InvoiceDocumentEvent the frontend already listens
 * for via Pusher once done.
 */
class ExtractInvoiceDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

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
     * @param  array<int, string>  $filePaths
     */
    public function __construct(
        private readonly string $documentId,
        private readonly array $filePaths
    ) {}

    public function handle(
        AnthropicVisionService $vision,
        InvoiceNameMatchingService $matcher,
        InvoiceExtractionValidator $validator,
        GoogleSheetService $sheet
    ): void {
        $located = $this->locateDocument($sheet);
        if (! $located) {
            Log::warning('ExtractInvoiceDocument: row not found, skipping.', ['document_id' => $this->documentId]);

            return;
        }

        $document = $located['data'];
        $files = $vision->filesFromUrls($this->filePaths);
        $extracted = empty($files) ? null : $vision->extractInvoice($files);

        Log::info('Invoice extraction result.', [
            'document_id' => $this->documentId,
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

                $this->appendLine($sheet, [
                    'line_id' => (string) Str::uuid(),
                    'invoice_document_id' => $this->documentId,
                    'site_id' => $sitePreselect ? $topSite['id'] : '',
                    'site_name' => $sitePreselect ? $topSite['name'] : '',
                    'site_name_raw' => $extractedLine['site_name'] ?? '',
                    'amount' => $extractedLine['amount'] ?? '',
                    'amount_with_tax' => $extractedLine['amount_with_tax'] ?? '',
                ]);
            }
        }

        $document['processed_at'] = Carbon::now('Asia/Manila')->toDateTimeString();
        $this->updateDocumentAt($sheet, $located['rowNumber'], $document);

        event(new InvoiceDocumentEvent($document, strtolower($document['status'])));

        Log::info('Invoice document processed.', [
            'document_id' => $this->documentId,
            'status' => $document['status'],
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
