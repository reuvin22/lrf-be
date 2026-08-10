<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\IOFactory;
use setasign\Fpdi\Fpdi;

/**
 * Thin wrapper around invoice LLM-vision extraction. Two providers are
 * supported — Claude (Anthropic) and Gemini (Google) — selected via
 * config('services.vision_provider'). isEnabled() lets callers short-circuit,
 * and every failure mode returns null instead of throwing — OCR output is
 * only a prefill, so a failed call must never block the upload.
 *
 * Was named AnthropicVisionService before Gemini support was added; renamed
 * since it's no longer Claude-specific. Everything below extractInvoice()
 * (file → vision-file conversion, Google Vision OCR pre-processing,
 * docx/xlsx/csv text extraction) is provider-agnostic and shared by both.
 */
class LlmVisionService
{
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    private const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

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

    // Neither Claude's nor Gemini's vision input accepts DOCX/Excel/CSV
    // directly — these mime types have their text extracted server-side
    // instead and sent as a plain text block (see buildVisionFile()).
    private const TEXT_EXTRACTED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
    ];

    // When GoogleVisionService is enabled, these run through Google Vision
    // OCR first — its extracted text (not the raw image) is what gets sent to
    // the LLM. application/pdf is deliberately excluded: Vision's synchronous
    // per-image API doesn't accept PDFs directly (that needs the separate
    // async batchAnnotateFiles flow, out of scope here), so PDFs keep going
    // to the LLM as native attachments.
    private const VISION_OCR_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    public function __construct(private readonly GoogleVisionService $googleVision) {}

    // Refined against the real April 2026 sample invoices — kept verbatim in
    // Japanese. Do not translate: field-name anchoring works better in the
    // source language, and the document set is entirely Japanese. Shared by
    // both providers — the extraction requirements don't change with the LLM.
    private const PROMPT = <<<'PROMPT'
あなたは建設業の経理担当者です。添付された書類(複数ページの場合、同じ取引先の書類はすべて1つの書類です)を読み取り、以下のJSON以外は出力しないでください。説明文は不要です。

取引先(発行元)が1つだけの場合は単一のJSONオブジェクトを、複数の取引先が含まれる場合はオブジェクトの配列(例: [ {...}, {...} ])を出力してください。各オブジェクトの形式は以下の通りです。

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
1. 取引先ごとの集約(最重要):
   - 添付書類全体を取引先(vendor_name)ごとにグループ化し、1取引先につきオブジェクトを1つだけ作成する。
   - 同じ取引先の書類が複数枚(発行日の異なる複数の請求書など)含まれる場合も、その取引先の分はすべて1つのオブジェクトにまとめる。lines は全書類分を合算して並べ、site_name が重複する場合は金額も合算する。subtotal・tax_amount・total_with_tax はその取引先の全書類の合計値にする。
   - 取引先が読み取れない・不明な場合は、書類ごとに別オブジェクトとして扱う(無理に統合しない)。
2. 書類種別の判定:
   - 「請求書」「御請求書」→ INVOICE
   - 「前月御請求額」「御入金額」「繰越」など前月からの繰越構造を持つ月締め請求書 → MONTHLY_STATEMENT
   - 「見積書」「御見積書」→ QUOTATION、「納品書」→ DELIVERY_NOTE、メールの印刷 → EMAIL
3. 現場(物件)ごとの金額:
   - 1枚の請求書に複数の現場が含まれる場合は、必ず現場ごとに分けて lines に出力する。
   - 現場ごとのセクションや小計(「物件計」「物件合計」など)があればそれを優先して使う。
   - 日付ごとの明細行に現場名が混ざっている形式の場合は、明細行を現場ごとに合算して lines にまとめる。
   - 現場が1つだけの場合も lines は1行として出力する。件名・工事名称・物件名を現場名として使う。
4. 月締め請求書(MONTHLY_STATEMENT)の場合:
   - 「前月繰越」「御入金」「相殺」「調整」「差引繰越」の行は金額に含めない。
   - 集計対象は「当月御買上額」(当月の取引分)のみ。現場ごとの「物件計」を lines に出力する。
   - total_with_tax には今回御請求額ではなく、当月御買上額+消費税を入れる。
5. 金額:
   - 数値はカンマ・円記号・「-」(末尾のハイフン)を除いた整数にする。
   - 税抜・消費税・税込を区別して出力する。税込しか書かれていない場合は amount_with_tax のみ埋めて amount は null にする。
   - 「経過措置」などの特殊な控除は合計値にそのまま反映し、明細としては解釈しない。
   - lines の合算と書類の合計が一致しない場合は、書類の合計を正とし、warnings にその旨を書く。
6. 日付:
   - 和暦(令和8年=2026年、R8=2026年)や2桁年(26年=2026年)は西暦のYYYY-MM-DDに正規化する。
   - billing_month は「◯月分」「締め日」などから請求対象月を判断する。
7. 読み取れない・存在しない項目は null にする。推測で値を作らない。手書きで判読が難しい場合は読める範囲だけ出力し、warnings に「手書きのため要確認」と書く。
PROMPT;

    private const REQUIRED_KEYS = ['document_type', 'lines'];

    public function isEnabled(): bool
    {
        return ! empty($this->apiKey());
    }

    private function provider(): string
    {
        return config('services.vision_provider', 'gemini');
    }

    private function apiKey(): ?string
    {
        return $this->provider() === 'anthropic'
            ? config('services.anthropic.api_key')
            : config('services.gemini.api_key');
    }

    /**
     * Send every page/shot of one upload batch to the configured LLM in a
     * single request and parse the structured JSON extraction. A batch may
     * contain several distinct vendor invoices (e.g. a multi-page PDF of
     * that month's whole mail pile) — the model groups by vendor per PROMPT
     * rule 1, so the result is a list of one or more invoice extractions,
     * one per distinct vendor found, not necessarily one per input file.
     *
     * Neither provider's vision input accepts DOCX/Excel/CSV — for those, the
     * caller extracts the text server-side and passes it here as a `text`
     * entry instead of `base64`; it's sent as a plain text content block
     * alongside any image/PDF attachments in the same request.
     *
     * @param  array<int, array{media_type: string, base64?: string, text?: string}>  $files
     * @return array<int, array<string, mixed>>|null null when disabled or the call/parse fails.
     */
    public function extractInvoice(array $files): ?array
    {
        if (! $this->isEnabled() || empty($files)) {
            return null;
        }

        return $this->provider() === 'anthropic'
            ? $this->extractViaClaude($files)
            : $this->extractViaGemini($files);
    }

    /**
     * Count total pages across an already-built vision file set, purely for
     * logging so it's visible how many pages a given upload actually
     * covered. A PDF OCR'd via Google Vision counts its real page count (via
     * the "=== ページ N ===" markers buildVisionFile() joins its pages with);
     * every other file (image, DOCX/XLSX/CSV text, or a raw PDF attachment
     * that fell back because OCR was unavailable/failed) counts as 1.
     *
     * @param  array<int, array{media_type: string, base64?: string, text?: string}>  $files
     */
    public function countPages(array $files): int
    {
        return array_sum(array_map(function ($file) {
            if (isset($file['text']) && preg_match_all('/^=== ページ \d+ ===$/m', $file['text'], $matches)) {
                return count($matches[0]);
            }

            return 1;
        }, $files));
    }

    /**
     * @param  array<int, array{media_type: string, base64?: string, text?: string}>  $files
     */
    private function extractViaClaude(array $files): ?array
    {
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
                ->post(self::ANTHROPIC_API_URL, [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 4096,
                    'messages' => [
                        ['role' => 'user', 'content' => $content],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Claude invoice extraction failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            // Don't assume content[0] is the text block — with extended
            // thinking, Claude returns [thinking, text] (or more blocks), so
            // the text block can be at any index. Find it by type instead.
            $textBlock = collect($response->json('content') ?? [])->firstWhere('type', 'text');
            $text = $textBlock['text'] ?? null;

            if (! is_string($text) || trim($text) === '') {
                Log::warning('Claude invoice extraction returned no text.', [
                    'stop_reason' => $response->json('stop_reason'),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $result = $this->parseJson($text);

            if ($result === null) {
                Log::warning('Claude invoice extraction returned unparsable JSON.', ['text' => $text]);
            } else {
                Log::info('Claude invoice extraction succeeded.', ['vendor_count' => count($result), 'result' => $result]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Claude invoice extraction threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<int, array{media_type: string, base64?: string, text?: string}>  $files
     */
    private function extractViaGemini(array $files): ?array
    {
        $parts = [];

        foreach ($files as $file) {
            if (isset($file['text'])) {
                $parts[] = ['text' => $file['text']];

                continue;
            }

            $parts[] = [
                'inline_data' => [
                    'mime_type' => $file['media_type'] ?? '',
                    'data' => $file['base64'] ?? '',
                ],
            ];
        }

        $parts[] = ['text' => self::PROMPT];

        $url = sprintf(self::GEMINI_API_URL, config('services.gemini.model'));

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.api_key'),
                'content-type' => 'application/json',
            ])
                ->timeout(120)
                ->post($url, [
                    'contents' => [
                        ['role' => 'user', 'parts' => $parts],
                    ],
                    'generationConfig' => [
                        // Forces raw JSON output — no markdown fences to strip,
                        // unlike Claude. parseJson() still defensively handles
                        // fences in case a model ever wraps them anyway.
                        'responseMimeType' => 'application/json',
                        // A multi-page OCR'd document gives the model a lot to
                        // reason through before it writes the JSON answer; at
                        // 4096 that reasoning alone was hitting MAX_TOKENS and
                        // cutting the response off mid-sentence, well before
                        // any JSON was ever written (surfaced as "unparsable
                        // JSON" — it wasn't JSON at all, just truncated prose).
                        // Left generous rather than tight since the answer
                        // itself rarely exceeds a couple thousand tokens even
                        // for many line items.
                        'maxOutputTokens' => 32768,
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini invoice extraction failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            // Same caution as Claude: don't assume parts[0] is the answer —
            // Gemini 2.5's "thinking" parts are flagged with thought=true and
            // can precede the real answer part, so pick the first part that
            // has non-thought text instead of trusting index 0.
            $partsOut = collect($response->json('candidates.0.content.parts') ?? []);
            $textPart = $partsOut->first(fn ($part) => empty($part['thought']) && ! empty($part['text']));
            $text = $textPart['text'] ?? null;

            if (! is_string($text) || trim($text) === '') {
                Log::warning('Gemini invoice extraction returned no text.', [
                    'finish_reason' => $response->json('candidates.0.finishReason'),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $result = $this->parseJson($text);

            if ($result === null) {
                Log::warning('Gemini invoice extraction returned unparsable JSON.', [
                    'finish_reason' => $response->json('candidates.0.finishReason'),
                    'text' => $text,
                ]);
            } else {
                Log::info('Gemini invoice extraction succeeded.', ['vendor_count' => count($result), 'result' => $result]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Gemini invoice extraction threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Strip optional markdown code fences and decode into a non-empty list
     * of one or more invoice objects — the prompt asks for a single object
     * when the batch has one vendor, or an array of objects (one per
     * vendor) when it has several (see PROMPT rule 1). Returns null when
     * the text isn't valid JSON or no element has the required keys.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            return null;
        }

        // A single object (associative array) is normalized to a one-element
        // list; an already-array response (one or more vendors) is used as-is.
        $candidates = array_is_list($decoded) ? $decoded : [$decoded];

        $results = array_values(array_filter($candidates, function ($candidate) {
            if (! is_array($candidate)) {
                return false;
            }

            foreach (self::REQUIRED_KEYS as $key) {
                if (! array_key_exists($key, $candidate)) {
                    return false;
                }
            }

            return true;
        }));

        return empty($results) ? null : $results;
    }

    // -------------------------------------------------------------------------
    // Stored-file → vision-file conversion. Used by the queued extraction jobs
    // (ExtractOcrUpload, ExtractInvoiceDocument) to turn the Firebase URLs a
    // row already has into the format extractInvoice() expects, without
    // needing the original upload request's raw bytes. Provider-agnostic —
    // shared by both Claude and Gemini.
    // -------------------------------------------------------------------------

    /**
     * Re-download every given public file URL and convert each into a
     * vision-ready file. Skips (rather than fails on) any URL that's
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
     * upload request — no HTTP round-trip) into vision-ready files. Prefer
     * this over filesFromUrls() whenever the raw bytes are already in hand,
     * e.g. a file uploaded in the same request that's about to be extracted
     * (via afterResponse()) — re-fetching it from Firebase would just be a
     * redundant network call for data already in memory.
     *
     * A PDF entry may also carry the public URL it was already uploaded to
     * (the controller uploads every file to Firebase before dispatching the
     * extraction job) — buildVisionFile() needs that URL to run Vision's
     * async, all-pages PDF OCR, which reads directly from GCS and can't
     * accept inline bytes.
     *
     * @param  array<int, array{mime: string, base64: string, url?: string}>  $decoded
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

            return $this->buildVisionFile(['mime' => $mime, 'base64' => base64_encode($response->body()), 'url' => $url]);
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
     * Build one vision-ready file entry from a decoded file. Images and PDFs
     * go straight through as base64 attachments (what both providers accept
     * natively) unless Google Vision OCR's text is used instead (see below);
     * DOCX/Excel/CSV have no such support, so their text is extracted
     * server-side first and sent as a plain text block.
     *
     * @param  array{mime: string, base64: string, url?: string}  $decoded
     * @return array{media_type: string, base64?: string, text?: string}
     */
    private function buildVisionFile(array $decoded): array
    {
        if ($decoded['mime'] === 'application/pdf') {
            // Determine the PDF's actual page count up front, purely so it's
            // logged and can be cross-checked against how many pages Vision
            // actually returns below — this doesn't gate anything, since a
            // PDF using a compression scheme the free FPDI parser can't read
            // shouldn't block extraction on that alone.
            $expectedPageCount = $this->detectPdfPageCount(base64_decode($decoded['base64']));

            Log::info('PDF received for extraction.', [
                'url' => $decoded['url'] ?? null,
                'expected_page_count' => $expectedPageCount,
            ]);

            if (! empty($decoded['url']) && $this->googleVision->isEnabled()) {
                $pages = $this->googleVision->extractPdfPagesFromUrl($decoded['url']);

                if ($pages !== null && ! empty($pages)) {
                    $text = collect($pages)
                        ->map(fn ($pageText, $pageNumber) => "=== ページ {$pageNumber} ===\n".trim($pageText))
                        ->implode("\n\n");

                    Log::info('Vision file built from Google Vision async PDF OCR text.', [
                        'expected_page_count' => $expectedPageCount,
                        'ocr_page_count' => count($pages),
                        'text_length' => strlen($text),
                    ]);

                    return ['media_type' => 'text/plain', 'text' => $text];
                }

                // Vision disabled/failed/found nothing — fall through and send
                // the LLM the raw PDF instead, same as before this existed.
                Log::info('Google Vision PDF OCR found no usable text; falling back to raw PDF attachment.');
            }
        }

        if (in_array($decoded['mime'], self::VISION_OCR_MIME_TYPES, true) && $this->googleVision->isEnabled()) {
            $ocr = $this->googleVision->extractText(base64_decode($decoded['base64']));
            if ($ocr !== null && trim($ocr['text']) !== '') {
                Log::info('Vision file built from Google Vision OCR text.', [
                    'mime' => $decoded['mime'],
                    'text_length' => strlen($ocr['text']),
                ]);

                return ['media_type' => 'text/plain', 'text' => $ocr['text']];
            }

            // Vision disabled/failed/found nothing — fall through and send
            // the LLM the raw image instead, same as before this existed.
            Log::info('Google Vision found no usable text; falling back to raw image.', [
                'mime' => $decoded['mime'],
            ]);
        }

        if (! in_array($decoded['mime'], self::TEXT_EXTRACTED_MIME_TYPES, true)) {
            Log::info('Vision file built as a raw attachment (image/PDF sent directly to the LLM).', [
                'mime' => $decoded['mime'],
            ]);

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

        Log::info('Vision file built from local text extraction.', [
            'mime' => $decoded['mime'],
            'text_length' => strlen($text),
        ]);

        return ['media_type' => 'text/plain', 'text' => $text];
    }

    /**
     * Determine a PDF's page count via FPDI (pure PHP, no Imagick/
     * Ghostscript needed) — used only for logging/cross-checking, never to
     * gate extraction. Some PDFs (e.g. ones using compressed cross-reference
     * streams) aren't readable by the free parser FPDI ships with; rather
     * than fail the upload over that, this just logs a warning and returns
     * null so the caller proceeds to Vision OCR regardless.
     */
    private function detectPdfPageCount(string $raw): ?int
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'ocr_pdf_count_');
        unlink($tmpBase);
        $tmpPath = $tmpBase.'.pdf';
        file_put_contents($tmpPath, $raw);

        try {
            return (new Fpdi)->setSourceFile($tmpPath);
        } catch (\Throwable $e) {
            Log::warning('Could not determine PDF page count via FPDI.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($tmpPath);
        }
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

            $text = trim(implode("\n", $lines));
            Log::info('DOCX text extraction succeeded.', ['text_length' => strlen($text)]);

            return $text;
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

            $text = trim(implode("\n", $lines));
            Log::info('Spreadsheet text extraction succeeded.', ['text_length' => strlen($text)]);

            return $text;
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
