<?php

namespace App\Http\Controllers\v1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only aggregation over InvoiceDocuments/InvoiceLines. Per the
 * aggregation policy: only CONFIRMED INVOICE/MONTHLY_STATEMENT documents
 * count, both tax bases are always exposed, and totals are computed on
 * demand from line-level data rather than baked into a summary table.
 */
class InvoiceSummaryController extends SheetResourceController
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

    private const AGGREGATABLE_TYPES = ['INVOICE', 'MONTHLY_STATEMENT'];

    /**
     * GET /invoice-summary?month=YYYY-MM
     * Per-site totals (both tax bases) across confirmed invoice-type
     * documents. Omit `month` for a running cumulative total across all
     * billing months.
     */
    public function index(Request $request): JsonResponse
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
    public function sites(string $siteId, Request $request): JsonResponse
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
     * @param  array<string, array<string, mixed>>  $totals mutated in place
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
        foreach ($this->all() as $document) {
            if (($document['status'] ?? '') !== 'CONFIRMED') {
                continue;
            }
            if (!in_array($document['document_type'] ?? '', self::AGGREGATABLE_TYPES, true)) {
                continue;
            }
            if ($month !== null && ($document['billing_month'] ?? '') !== $month) {
                continue;
            }

            $documentsById[$document['document_id'] ?? ''] = $document;
        }

        $lines = [];
        foreach ($this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::LINES_SHEET) as $line) {
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
