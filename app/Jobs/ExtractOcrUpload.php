<?php

namespace App\Jobs;

use App\Services\AnthropicVisionService;
use App\Services\GoogleSheetService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Claude Vision extraction for one OcrUploads row off the request
 * thread — the HTTP request only uploads the file(s) and writes a PROCESSING
 * row; this job does the (up to ~120s) Claude call and updates that row with
 * the result. Mirrors OcrUploadController's now-removed extractOcr()/
 * deriveAmount() logic.
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

    /**
     * @param  array<int, string>  $filePaths
     */
    public function __construct(
        private readonly string $uploadId,
        private readonly array $filePaths
    ) {}

    public function handle(AnthropicVisionService $vision, GoogleSheetService $sheet): void
    {
        $located = $this->locate($sheet);
        if (! $located) {
            Log::warning('ExtractOcrUpload: row not found, skipping.', ['upload_id' => $this->uploadId]);

            return;
        }

        $files = $vision->filesFromUrls($this->filePaths);
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
            }
        } catch (\Throwable $inner) {
            Log::error('ExtractOcrUpload: failed to mark row ERROR after job failure.', [
                'upload_id' => $this->uploadId,
                'error' => $inner->getMessage(),
            ]);
        }
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

        $row = array_map(function ($h) use ($merged) {
            $v = $merged[$h] ?? '';

            return is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? '');
        }, self::HEADERS);

        $sheet->updateRow($this->spreadsheetId(), self::SHEET, $located['rowNumber'], $row);
    }
}
