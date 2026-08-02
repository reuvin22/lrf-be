<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Anthropic Messages API for invoice LLM-vision
 * extraction. isEnabled() lets callers short-circuit, and every failure mode
 * returns null instead of throwing — OCR output is only a prefill, so a
 * failed call must never block the upload.
 */
class AnthropicVisionService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

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
                return null;
            }

            return $this->parseJson($text);
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
}
