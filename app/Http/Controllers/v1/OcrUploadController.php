<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\OcrUploadRequest;
use App\Http\Requests\v1\OcrUploadReviewRequest;
use App\Services\AnthropicVisionService;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Google\Cloud\Storage\Bucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OcrUploadController extends SheetResourceController
{
    protected string $sheetName = 'OcrUploads';
    protected string $idColumn  = 'upload_id';
    protected array $headers    = [
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

    public function index(Request $request): JsonResponse
    {
        $rows = $this->all();
        usort($rows, fn ($a, $b) => strcmp((string)($b['uploaded_at'] ?? ''), (string)($a['uploaded_at'] ?? '')));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(OcrUploadRequest $request, FirebaseService $firebase, AnthropicVisionService $vision): JsonResponse
    {
        $data              = $request->validated();
        $data['upload_id'] = (string) Str::uuid();
        $rawImages         = $this->collectNewImages($data);
        $visionFiles       = [];

        if (!empty($rawImages)) {
            $result = $this->processNewImages($firebase, $rawImages, $data['uploaded_by'] ?? 'unknown');
            if ($result instanceof JsonResponse) return $result;

            $data['image_path'] = $this->normalizePaths($result['paths']);
            $visionFiles = $result['files'];
        }

        unset($data['image_base64'], $data['images_base64'], $data['previous_image_paths'], $data['use_vision']);

        // Write the row as PROCESSING before calling the LLM, then update it
        // once extraction finishes, so other clients polling the list see the
        // in-progress state rather than the row appearing only after OCR completes.
        $runsVision = !empty($visionFiles) && $vision->isEnabled();
        if ($runsVision) {
            $data['status'] = 'PROCESSING';
        }

        $data = $this->resolveNames($data);
        $this->appendRow($data);

        if ($runsVision) {
            $located = $this->locate($data['upload_id']);
            $data    = array_merge($data, $this->extractOcr($vision, $visionFiles));
            $data    = $this->resolveNames($data);

            if ($located) {
                $this->updateRowAt($located['rowNumber'], $data);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'OCR upload created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(OcrUploadRequest $request, string $id, FirebaseService $firebase, AnthropicVisionService $vision): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $existing = $located['data'];
        $data     = $request->validated();
        $bucket   = $firebase->storage()->getBucket();

        // The frontend always resubmits the full desired image set on edit:
        // `previous_image_paths` is the subset of existing images the user
        // kept, and `images_base64` is whatever new files they added. Any
        // existing image not present in `previous_image_paths` was removed
        // by the user and must be deleted from Firebase.
        $touchesImages = array_key_exists('images_base64', $data) || array_key_exists('previous_image_paths', $data);
        $finalPaths    = [];
        $visionFiles   = [];

        try {
            if ($touchesImages) {
                $rawImages = $this->collectNewImages($data);
                $keepPaths = array_values(array_filter((array) ($data['previous_image_paths'] ?? [])));
                $existingPaths = $this->resolvePaths($existing['image_path'] ?? null);

                $this->deleteStoredImages($bucket, array_diff($existingPaths, $keepPaths));

                $newPaths = [];
                if (!empty($rawImages)) {
                    $result = $this->processNewImages($firebase, $rawImages, $data['uploaded_by'] ?? ($existing['uploaded_by'] ?? 'unknown'));
                    if ($result instanceof JsonResponse) return $result;

                    $newPaths    = $result['paths'];
                    $visionFiles = $result['files'];
                }

                $finalPaths = array_values(array_merge($keepPaths, $newPaths));

                if (empty($finalPaths)) {
                    $data['image_path']       = null;
                    $data['ocr_result_amount'] = null;
                    $data['ocr_result_date']   = null;
                    $data['ocr_result_raw']    = null;
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
            $runsVision = !empty($visionFiles) && $vision->isEnabled();
            if ($runsVision) {
                $data['status'] = 'PROCESSING';
            }

            $merged              = array_merge($existing, $data);
            $merged['upload_id'] = $id;
            $merged              = $this->resolveNames($merged);

            $this->updateRowAt($located['rowNumber'], $merged);

            if ($runsVision) {
                $merged = array_merge($merged, $this->extractOcr($vision, $visionFiles));
                $merged = $this->resolveNames($merged);
                $this->updateRowAt($located['rowNumber'], $merged);
            }

            return response()->json([
                'success' => true,
                'message' => 'OCR upload updated successfully.',
                'data'    => $merged,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Update failed.',
                'error'   => $e->getMessage(),
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
        if (!$located) return $this->notFound();

        $data = $request->validated();
        $now  = Carbon::now('Asia/Manila')->toDateTimeString();

        $merged = array_merge($located['data'], [
            'upload_id'    => $id,
            'confirmed'    => $data['action'] === 'approve',
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
            'data'    => $merged,
        ]);
    }

    public function destroy(Request $request, ?string $id = null, ?FirebaseService $firebase = null): JsonResponse
    {
        if ($id === null) return $this->notFound('Id is required.');
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $paths = $this->resolvePaths($located['data']['image_path'] ?? null);
        if (!empty($paths) && $firebase) {
            try {
                $this->deleteStoredImages($firebase->storage()->getBucket(), $paths);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete image from Firebase.',
                    'error'   => $e->getMessage(),
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
     * Fill site_name / subcontractor_name from the source sheets whenever the
     * matching id is present. The sheet's onEdit script only fires on manual
     * edits, so backend-created rows would otherwise leave these blank.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveNames(array $data): array
    {
        if (!empty($data['site_id'])) {
            $data['site_name'] = $this->lookupName(
                'ConstructionSites',
                'site_id',
                $data['site_id'],
                'site_name'
            );
        }

        if (!empty($data['subcontractor_id'])) {
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

        if (!$vision->isEnabled() || empty($files)) {
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
            'ocr_result_raw'    => json_encode($extracted, JSON_UNESCAPED_UNICODE),
            'ocr_result_amount' => $this->deriveAmount($extracted) ?: null,
            'ocr_result_date'   => $extracted['issue_date'] ?? null,
            // PENDING here means "extraction finished, awaiting human review"
            // — see the review() action above for the approve/reject step.
            'status'            => 'PENDING',
            'processed_at'      => Carbon::now('Asia/Manila')->toDateTimeString(),
        ];
    }

    /**
     * ocr_uploads only has a single amount column (unlike invoice_documents'
     * subtotal/tax/total split), so collapse the richer extraction down to
     * one number: prefer the tax-inclusive total, then the tax-exclusive
     * subtotal, then fall back to summing the extracted lines.
     */
    private function deriveAmount(array $extracted): int|null
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
        if (!empty($data['images_base64']) && is_array($data['images_base64'])) {
            return array_values(array_filter($data['images_base64']));
        }

        return !empty($data['image_base64']) ? [$data['image_base64']] : [];
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
                $parsed   = parse_url($path);
                $filePath = ltrim(str_replace('/' . $bucket->name() . '/', '', $parsed['path'] ?? ''), '/');
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
        $paths  = [];
        $files  = [];

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
        if (!in_array($decoded['mime'], self::TEXT_EXTRACTED_MIME_TYPES, true)) {
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
        $tmpPath = $tmpBase . '.docx';
        file_put_contents($tmpPath, $raw);

        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($tmpPath);
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
        if (!method_exists($container, 'getElements')) {
            return;
        }

        foreach ($container->getElements() as $element) {
            if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $lines[] = $element->getText();
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
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
        $tmpPath = $tmpBase . '.' . $extension;
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
        if (!mb_check_encoding($raw, 'UTF-8')) {
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
        if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $dataUri, $m)) {
            return null;
        }

        $mime = strtolower($m[1]);
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
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
        $fileName = 'ocr_uploads/' . $uploadedBy . '_' . Carbon::now('Asia/Manila')->format('Ymd_His_u') . '_' . $index . '.' . $extension;

        try {
            $object = $bucket->upload($raw, ['name' => $fileName]);

            if (!$object) throw new \Exception('Firebase upload returned null.');

            $object->update([], ['predefinedAcl' => 'PUBLICREAD']);

            $uploaded = $bucket->object($fileName);
            if (!$uploaded->exists() || ($uploaded->info()['size'] ?? 0) == 0) {
                throw new \Exception('Uploaded file is empty or missing.');
            }

            return 'https://storage.googleapis.com/' . $bucket->name() . '/' . $fileName;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Firebase upload failed.',
                'error'   => $e->getMessage(),
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
            if (!$response->successful()) {
                return null;
            }

            $mime = strtolower((string) $response->header('Content-Type')) ?: $this->guessMimeFromUrl($url);
            $mime = strtok($mime, ';'); // strip a "; charset=..." suffix if present

            if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
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
}
