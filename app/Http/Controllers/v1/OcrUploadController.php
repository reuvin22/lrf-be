<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\OcrUploadRequest;
use App\Services\FirebaseService;
use App\Services\GoogleVisionService;
use Carbon\Carbon;
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
        $base64            = $request->input('image_base64');

        if (!empty($base64)) {
            $imagePath = $this->uploadImage($firebase, $base64, $data['uploaded_by'] ?? 'unknown');
            if ($imagePath instanceof JsonResponse) return $imagePath;
            $data['image_path'] = $imagePath;

            $data = array_merge($data, $this->runVisionOcr($vision, $imagePath));
        }

        unset($data['image_base64'], $data['use_vision']);

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
        $base64   = $request->input('image_base64');
        $bucket   = $firebase->storage()->getBucket();

        try {
            $previousPath = $request->input('previous_image_path');

            if (($base64 === '' || $base64 === null) && $previousPath) {
                try {
                    $bucket->object('ocr_uploads/' . ltrim($previousPath, '/'))->delete();
                } catch (\Throwable $e) {
                    Log::warning('Firebase delete failed.', ['error' => $e->getMessage()]);
                }
                $data['image_path'] = null;
            } elseif (!empty($base64)) {
                $oldPath = $previousPath ?? ($existing['image_path'] ?? null);
                if ($oldPath) {
                    $parsed   = parse_url($existing['image_path'] ?? '');
                    $filePath = ltrim(str_replace('/' . $bucket->name() . '/', '', $parsed['path'] ?? ''), '/');
                    if ($filePath) {
                        $obj = $bucket->object($filePath);
                        if ($obj->exists()) $obj->delete();
                    }
                }

                $imagePath = $this->uploadImage($firebase, $base64, $data['uploaded_by'] ?? ($existing['uploaded_by'] ?? 'unknown'));
                if ($imagePath instanceof JsonResponse) return $imagePath;
                $data['image_path'] = $imagePath;

                $data = array_merge($data, $this->runVisionOcr($vision, $imagePath));
            } else {
                unset($data['image_path']);
            }

            unset($data['image_base64'], $data['use_vision']);

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

    public function destroy(Request $request, ?string $id = null, ?FirebaseService $firebase = null): JsonResponse
    {
        if ($id === null) return $this->notFound('Id is required.');
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $imagePath = $located['data']['image_path'] ?? null;
        if ($imagePath && $firebase) {
            try {
                $bucket   = $firebase->storage()->getBucket();
                $filePath = 'ocr_uploads/' . basename($imagePath);
                $object   = $bucket->object($filePath);
                if ($object->exists()) $object->delete();
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
     * Run Google Vision OCR on the uploaded image whenever Vision is enabled
     * (GOOGLE_VISION_ENABLED) and there is an image. image_path is the public
     * Firebase link (not a local file), so Vision fetches the URL directly.
     * Returns the OCR result columns to merge into the row, or an empty array
     * when Vision is skipped (so the upload still succeeds).
     *
     * Fills three columns:
     *   - ocr_result_raw    → the full Vision payload as JSON (text + blocks)
     *   - ocr_result_amount → the largest number found (receipt total heuristic)
     *   - ocr_result_date   → the first date found, normalised to Y-m-d
     *
     * @return array<string, mixed>
     */
    private function runVisionOcr(GoogleVisionService $vision, ?string $imageUrl): array
    {
        if (!$vision->isEnabled() || empty($imageUrl)) {
            return [];
        }
    
        try {
            $result = $vision->extractTextFromUri($imageUrl);
    
            if ($result === null) {
                return [
                    'status' => 'error',
                    'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
                ];
            }
    
            $text = trim((string) ($result['text'] ?? ''));
    
            $amount = $this->extractAmount($text);
            $date   = $this->extractDate($text);
    
            return [
                'ocr_result_raw'    => json_encode($result, JSON_UNESCAPED_UNICODE),
                'ocr_result_amount' => $amount ?: null,
                'ocr_result_date'   => $date ?: null,
                'status'            => 'completed',
                'processed_at'      => Carbon::now('Asia/Manila')->toDateTimeString(),
            ];
    
        } catch (\Throwable $e) {
            Log::error('OCR failed', [
                'error' => $e->getMessage(),
            ]);
    
            return [
                'status' => 'error',
                'processed_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
            ];
        }
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
     * Upload a base64 image to Firebase. Returns the public URL or a JsonResponse on error.
     */
    private function uploadImage(FirebaseService $firebase, string $base64, string $uploadedBy): JsonResponse|string
    {
        $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64));

        if ($image === false || strlen($image) < 100) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid base64 image.',
            ], 422);
        }

        $fileName = 'ocr_uploads/' . $uploadedBy . '_' . Carbon::now('Asia/Manila')->format('Ymd_His_u') . '.png';

        try {
            $bucket = $firebase->storage()->getBucket();
            $object = $bucket->upload($image, ['name' => $fileName]);

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
}