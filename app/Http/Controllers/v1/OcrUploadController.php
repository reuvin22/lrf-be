<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\OcrUploadRequest;
use App\Http\Requests\v1\OcrUploadReviewRequest;
use App\Services\FirebaseService;
use App\Services\GoogleVisionService;
use Carbon\Carbon;
use Google\Cloud\Storage\Bucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function index(Request $request): JsonResponse
    {
        $rows = $this->all();
        usort($rows, fn ($a, $b) => strcmp((string)($b['uploaded_at'] ?? ''), (string)($a['uploaded_at'] ?? '')));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(OcrUploadRequest $request, FirebaseService $firebase, GoogleVisionService $vision): JsonResponse
    {
        $data              = $request->validated();
        $data['upload_id'] = (string) Str::uuid();
        $images            = $this->collectImagesInput($data);

        if (!empty($images)) {
            $imagePaths = $this->uploadImages($firebase, $images, $data['uploaded_by'] ?? 'unknown');
            if ($imagePaths instanceof JsonResponse) return $imagePaths;
            $data['image_path'] = $this->normalizePaths($imagePaths);

            $data = array_merge($data, $this->runVisionOcr($vision, $imagePaths));
        }

        unset($data['image_base64'], $data['files'], $data['use_vision']);

        $data = $this->resolveNames($data);

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'OCR upload created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(OcrUploadRequest $request, string $id, FirebaseService $firebase, GoogleVisionService $vision): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $existing = $located['data'];
        $data     = $request->validated();
        $images   = $this->collectImagesInput($data);
        $previousPath = $request->input('previous_image_path');
        $bucket   = $firebase->storage()->getBucket();

        try {
            if (empty($images) && $previousPath) {
                $this->deleteStoredImages($bucket, $this->resolvePaths($existing['image_path'] ?? null));
                $data['image_path'] = null;
            } elseif (!empty($images)) {
                $this->deleteStoredImages($bucket, $this->resolvePaths($existing['image_path'] ?? null));

                $imagePaths = $this->uploadImages($firebase, $images, $data['uploaded_by'] ?? ($existing['uploaded_by'] ?? 'unknown'));
                if ($imagePaths instanceof JsonResponse) return $imagePaths;
                $data['image_path'] = $this->normalizePaths($imagePaths);

                $data = array_merge($data, $this->runVisionOcr($vision, $imagePaths));
            } else {
                unset($data['image_path']);
            }

            unset($data['image_base64'], $data['files'], $data['use_vision']);

            $merged              = array_merge($existing, $data);
            $merged['upload_id'] = $id;
            $merged              = $this->resolveNames($merged);

            $this->updateRowAt($located['rowNumber'], $merged);

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
     * it's distinguishable from an unreviewed COMPLETED/ERROR upload.
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
     * Run Google Vision OCR on every uploaded image (all pages/shots of one
     * document) whenever Vision is enabled (GOOGLE_VISION_ENABLED). image_path
     * entries are public Firebase links (not local files), so Vision fetches
     * each URL directly. Text and word/line blocks from every image are
     * concatenated before the amount/date heuristics run, so a multi-page
     * upload is still treated as a single document. Returns the OCR result
     * columns to merge into the row, or an empty array when Vision is skipped
     * (so the upload still succeeds).
     *
     * Fills three columns:
     *   - ocr_result_raw    → the merged Vision payload as JSON (text + blocks)
     *   - ocr_result_amount → the largest number found (receipt total heuristic)
     *   - ocr_result_date   → the first date found, normalised to Y-m-d
     *
     * @param  array<int, string>  $imageUrls
     * @return array<string, mixed>
     */
    private function runVisionOcr(GoogleVisionService $vision, array $imageUrls): array
    {
        $imageUrls = array_values(array_filter($imageUrls));

        if (!$vision->isEnabled() || empty($imageUrls)) {
            return [];
        }

        $texts  = [];
        $blocks = [];
        $failures = 0;

        foreach ($imageUrls as $imageUrl) {
            try {
                $result = $vision->extractTextFromUri($imageUrl);
            } catch (\Throwable $e) {
                Log::error('OCR failed', ['error' => $e->getMessage()]);
                $result = null;
            }

            if ($result === null) {
                $failures++;
                continue;
            }

            $text = trim((string) ($result['text'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }
            $blocks = array_merge($blocks, $result['blocks'] ?? []);
        }

        if ($failures === count($imageUrls)) {
            return [
                'status' => 'error',
                'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
            ];
        }

        $mergedText = implode("\n\n", $texts);

        return [
            'ocr_result_raw'    => json_encode(['text' => $mergedText, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE),
            'ocr_result_amount' => $this->extractAmount($mergedText) ?: null,
            'ocr_result_date'   => $this->extractDate($mergedText) ?: null,
            'status'            => 'completed',
            'processed_at'      => Carbon::now('Asia/Manila')->toDateTimeString(),
        ];
    }

    /**
     * Heuristic amount extraction: the largest number in the OCR text — on a
     * receipt/invoice the total is almost always the biggest figure. Handles
     * thousands separators (1,234) and a leading ¥. Returns '' when none found.
     */
    private function extractAmount(string $text): int|string
    {
        if (!preg_match_all('/\d[\d,]*/', $text, $matches)) {
            return '';
        }

        $amounts = array_filter(array_map(
            fn ($n) => (int) str_replace(',', '', $n),
            $matches[0]
        ));

        return empty($amounts) ? '' : max($amounts);
    }

    /**
     * Extract the first date from the OCR text and normalise to Y-m-d.
     * Handles YYYY/MM/DD, YYYY-MM-DD, YYYY.MM.DD and Japanese YYYY年M月D日.
     * Returns '' when no date is found.
     */
    private function extractDate(string $text): string
    {
        if (preg_match('/(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})/', $text, $m)
            || preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return '';
    }

    /**
     * Pull the images to upload out of the validated request data. Accepts
     * either the new `files` array (one or many, {data: <base64>} each) or
     * the legacy single `image_base64` field — `files` takes priority when
     * both are present.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function collectImagesInput(array $data): array
    {
        if (!empty($data['files']) && is_array($data['files'])) {
            return array_values(array_filter(array_map(
                fn ($f) => is_array($f) ? ($f['data'] ?? null) : null,
                $data['files']
            )));
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
     * Upload one or more base64 images to Firebase (all pages/shots of one
     * upload). Returns the list of public URLs in the same order as $images,
     * or a JsonResponse on the first decode/upload failure.
     *
     * @param  array<int, string>  $images
     * @return array<int, string>|JsonResponse
     */
    private function uploadImages(FirebaseService $firebase, array $images, string $uploadedBy): array|JsonResponse
    {
        $bucket = $firebase->storage()->getBucket();
        $multiple = count($images) > 1;
        $paths = [];

        foreach ($images as $index => $base64) {
            $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64));

            if ($image === false || strlen($image) < 100) {
                return response()->json([
                    'success' => false,
                    'message' => $multiple ? "File #{$index} is not a valid base64 image." : 'Invalid base64 image.',
                ], 422);
            }

            $suffix   = $multiple ? '_' . $index : '';
            $fileName = 'ocr_uploads/' . $uploadedBy . '_' . Carbon::now('Asia/Manila')->format('Ymd_His_u') . $suffix . '.png';

            try {
                $object = $bucket->upload($image, ['name' => $fileName]);

                if (!$object) throw new \Exception('Firebase upload returned null.');

                $object->update([], ['predefinedAcl' => 'PUBLICREAD']);

                $uploaded = $bucket->object($fileName);
                if (!$uploaded->exists() || ($uploaded->info()['size'] ?? 0) == 0) {
                    throw new \Exception('Uploaded file is empty or missing.');
                }

                $paths[] = 'https://storage.googleapis.com/' . $bucket->name() . '/' . $fileName;
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Firebase upload failed.',
                    'error'   => $e->getMessage(),
                ], 500);
            }
        }

        return $paths;
    }
}