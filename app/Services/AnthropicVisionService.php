<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;

/**
 * Thin wrapper around the Anthropic Messages API for invoice LLM-vision
 * extraction. isEnabled() lets callers short-circuit, and every failure mode
 * returns null instead of throwing — OCR output is only a prefill, so a
 * failed call must never block the upload.
 */
class AnthropicVisionService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    // The superset of file types either upload flow (ocr-uploads or
    // invoice-documents) may have stored. A URL that was validated as
    // image/pdf at upload time will never actually resolve to a docx/xlsx/csv
    // body, so sharing one broad allowlist across both callers is harmless.
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
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

    // Refined against the real April 2026 sample invoices — kept verbatim in
    // Japanese. Do not translate: field-name anchoring works better in the
    // source language, and the document set is entirely Japanese.
    private const PROMPT = <<<'PROMPT'
あなたは建設業の経理担当者です。添付された書類(複数ページの場合はすべてが1つの書類です)を読み取り、以下のJSONだけを出力してください。説明文は不要です。

{
  "document_type": "INVOICE | MONTHLY_STATEMENT | QUOTATION | DELIVERY_NOTE | EMAIL | OTHER",
  "issue_date": "YYYY-MM-DD または null",
  "billing_month": "YYYY-MM または null",
  "vendor_name": "発行元の会社名・屋号 または null",
  "lines": [
    { "site_name": "現場名・物件名・件名", "amount": 税抜金額の数値, "amount_with_tax": 税込金額の数値または null }
  ],
  "subtotal": 税抜合計の数値または null,
  "tax_amount": 消費税額の数値または null,
  "total_with_tax": 税込合計の数値または null,
  "notes": "特記事項があれば簡潔に",
  "warnings": ["確信が持てない点や、合計と明細の不一致など"]
}

ルール:
1. 書類種別の判定:
   - 「請求書」「御請求書」→ INVOICE
   - 「前月御請求額」「御入金額」「繰越」など前月からの繰越構造を持つ月締め請求書 → MONTHLY_STATEMENT
   - 「見積書」「御見積書」→ QUOTATION、「納品書」→ DELIVERY_NOTE、メールの印刷 → EMAIL
2. 現場(物件)ごとの金額:
   - 1枚の請求書に複数の現場が含まれる場合は、必ず現場ごとに分けて lines に出力する。
   - 現場ごとのセクションや小計(「物件計」「物件合計」など)があればそれを優先して使う。
   - 日付ごとの明細行に現場名が混ざっている形式の場合は、明細行を現場ごとに合算して lines にまとめる。
   - 現場が1つだけの場合も lines は1行として出力する。件名・工事名称・物件名を現場名として使う。
3. 月締め請求書(MONTHLY_STATEMENT)の場合:
   - 「前月繰越」「御入金」「相殺」「調整」「差引繰越」の行は金額に含めない。
   - 集計対象は「当月御買上額」(当月の取引分)のみ。現場ごとの「物件計」を lines に出力する。
   - total_with_tax には今回御請求額ではなく、当月御買上額+消費税を入れる。
4. 金額:
   - 数値はカンマ・円記号・「-」(末尾のハイフン)を除いた整数にする。
   - 税抜・消費税・税込を区別して出力する。税込しか書かれていない場合は amount_with_tax のみ埋めて amount は null にする。
   - 「経過措置」などの特殊な控除は合計値にそのまま反映し、明細としては解釈しない。
   - lines の合算と書類の合計が一致しない場合は、書類の合計を正とし、warnings にその旨を書く。
5. 日付:
   - 和暦(令和8年=2026年、R8=2026年)や2桁年(26年=2026年)は西暦のYYYY-MM-DDに正規化する。
   - billing_month は「◯月分」「締め日」などから請求対象月を判断する。
6. 読み取れない・存在しない項目は null にする。推測で値を作らない。手書きで判読が難しい場合は読める範囲だけ出力し、warnings に「手書きのため要確認」と書く。
PROMPT;

    private const REQUIRED_KEYS = ['document_type', 'lines'];

    public function isEnabled(): bool
    {
        return ! empty(config('services.anthropic.api_key'));
    }

    /**
     * Send every page/shot of one invoice document to Claude in a single
     * request (multi-page/multi-shot = one document) and parse the
     * structured JSON extraction.
     *
     * Claude's vision API only accepts images and PDFs as attachments — it
     * has no concept of DOCX/Excel/CSV. For those, the caller extracts the
     * text server-side and passes it here as a `text` entry instead of
     * `base64`; it's sent as a plain text content block alongside any
     * image/PDF attachments in the same request.
     *
     * @param  array<int, array{media_type: string, base64?: string, text?: string}>  $files
     * @return array<string, mixed>|null null when disabled or the call/parse fails.
     */
    public function extractInvoice(array $files): ?array
    {
        if (! $this->isEnabled() || empty($files)) {
            return null;
        }

        $content = [];
        $hasPdf = false;

        foreach ($files as $file) {
            if (isset($file['text'])) {
                $content[] = ['type' => 'text', 'text' => $file['text']];

                continue;
            }

            $mediaType = $file['media_type'] ?? '';
            $hasPdf = $hasPdf || $mediaType === 'application/pdf';

            $content[] = [
                'type' => $mediaType === 'application/pdf' ? 'document' : 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mediaType,
                    'data' => $file['base64'] ?? '',
                ],
            ];
        }

        $content[] = ['type' => 'text', 'text' => self::PROMPT];

        $headers = [
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];

        if ($hasPdf) {
            $headers['anthropic-beta'] = 'pdfs-2024-09-25';
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(120)
                ->post(self::API_URL, [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 4096,
                    'messages' => [
                        ['role' => 'user', 'content' => $content],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Anthropic invoice extraction failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $text = $response->json('content.0.text');
            if (! is_string($text) || trim($text) === '') {
                Log::warning('Anthropic invoice extraction returned no text.', [
                    'stop_reason' => $response->json('stop_reason'),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $result = $this->parseJson($text);

            if ($result === null) {
                Log::warning('Anthropic invoice extraction returned unparsable JSON.', ['text' => $text]);
            } else {
                Log::info('Anthropic invoice extraction succeeded.', ['result' => $result]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Anthropic invoice extraction threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Strip optional markdown code fences and decode. Returns null when the
     * result isn't valid JSON or is missing required keys.
     */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $decoded)) {
                return null;
            }
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Stored-file → vision-file conversion. Used by the queued extraction jobs
    // (ExtractOcrUpload, ExtractInvoiceDocument) to turn the Firebase URLs a
    // row already has into the format extractInvoice() expects, without
    // needing the original upload request's raw bytes.
    // -------------------------------------------------------------------------

    /**
     * Re-download every given public file URL and convert each into a
     * Claude-ready vision file. Skips (rather than fails on) any URL that's
     * unreachable or not a supported type — one bad page shouldn't sink the
     * whole document.
     *
     * @param  array<int, string>  $urls
     * @return array<int, array{media_type: string, base64?: string, text?: string}>
     */
    public function filesFromUrls(array $urls): array
    {
        return array_values(array_filter(array_map(
            fn ($url) => $this->fetchAsVisionFile($url),
            $urls
        )));
    }

    /**
     * Convert already-decoded file data (mime + base64, straight from the
     * upload request — no HTTP round-trip) into Claude-ready vision files.
     * Prefer this over filesFromUrls() whenever the raw bytes are already in
     * hand, e.g. a file uploaded in the same request that's about to be
     * extracted (via afterResponse()) — re-fetching it from Firebase would
     * just be a redundant network call for data already in memory.
     *
     * @param  array<int, array{mime: string, base64: string}>  $decoded
     * @return array<int, array{media_type: string, base64?: string, text?: string}>
     */
    public function filesFromDecoded(array $decoded): array
    {
        return array_values(array_filter(array_map(
            fn ($file) => in_array($file['mime'] ?? '', self::ALLOWED_MIME_TYPES, true) ? $this->buildVisionFile($file) : null,
            $decoded
        )));
    }

    /**
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
            Log::warning('Failed to re-fetch stored file for extraction.', ['url' => $url, 'error' => $e->getMessage()]);

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

    /**
     * Build one Claude-ready file entry from a decoded file. Images and PDFs
     * go straight through as base64 attachments (what Claude's vision API
     * accepts natively); DOCX/Excel/CSV have no such support, so their text
     * is extracted server-side first and sent as a plain text block.
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
}
