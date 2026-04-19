<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\OcrUploadRequest;
use App\Http\Resources\v1\OcrUploadResource;
use App\Models\OcrUpload;
use App\Models\OcrUploads;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OcrUploadController extends Controller
{
    public function index()
    {
        $uploads = OcrUploads::latest()->paginate(10);

        return OcrUploadResource::collection($uploads);
    }

    public function store(OcrUploadRequest $request, FirebaseService $firebase)
    {
        $data = $request->validated();
        $data['upload_id'] = (string) Str::uuid();
        $base64 = $request->input('image_base64');

        if (!empty($base64)) {
            $image = preg_replace('#^data:image/\w+;base64,#i', '', $base64);
            $image = base64_decode($image);

            if ($image === false || strlen($image) < 100) {
                return response()->json([
                    'message' => 'Invalid base64 image'
                ], 422);
            }

            $employeeId = $data['uploaded_by'] ?? 'unknown';
            $timestamp = Carbon::now('Asia/Manila')->format('Ymd_His_u');
            $fileName = "ocr_uploads/{$employeeId}_{$timestamp}.png";

            $storage = $firebase->storage();
            $bucket = $storage->getBucket();

            try {
                $object = $bucket->upload($image, [
                    'name' => $fileName,
                ]);

                if (!$object) {
                    throw new \Exception('Firebase upload returned null');
                }

                $object->update([], ['predefinedAcl' => 'PUBLICREAD']);
                $uploadedObject = $bucket->object($fileName);

                if (!$uploadedObject->exists()) {
                    throw new \Exception('File not found in Firebase after upload');
                }

                $info = $uploadedObject->info();
                if (($info['size'] ?? 0) == 0) {
                    throw new \Exception('Uploaded file is empty');
                }

                // Generate URL
                $bucketName = $bucket->name();
                $imageUrl = "https://storage.googleapis.com/{$bucketName}/{$fileName}";

                $data['image_path'] = $imageUrl;

            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Firebase upload failed',
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        unset($data['image_base64']);
        $upload = OcrUploads::create($data);

        return new OcrUploadResource($upload);
    }

    public function show(string $id)
    {
        $upload = OcrUploads::findOrFail($id);

        return new OcrUploadResource($upload);
    }

    public function update(OcrUploadRequest $request, string $id, FirebaseService $firebase)
    {
        $upload = OcrUploads::findOrFail($id);
        $data = $request->validated();

        $base64 = $request->input('image_base64');

        $storage = $firebase->storage();
        $bucket = $storage->getBucket();

        try {
            $previousPath = $request->input('previous_image_path');
            Log::info('THIS IS OLD Path', ['old_path' => $previousPath]);
            if (($base64 === "" || $base64 === null) && $previousPath) {

                $filePath = "ocr_uploads/" . ltrim($previousPath, '/');

                Log::info('File path', ['file' => $filePath]);
                try {
                    $bucket->object($filePath)->delete();
                } catch (\Throwable $e) {
                    Log::warning('Firebase delete failed:', [
                        'error' => $e->getMessage()
                    ]);
                }

                $data['image_path'] = null;
            }
            elseif (!empty($base64)) {
                Log::info('BASE64 is empty');
                $oldPath = $previousPath ?? $upload->image_path;
                if ($oldPath) {
                    $parsedUrl = parse_url($upload->image_path);
                    $path = $parsedUrl['path'] ?? '';

                    $bucketName = $bucket->name();
                    $filePath = str_replace("/{$bucketName}/", '', $path);
                    $filePath = ltrim($filePath, '/');

                    $object = $bucket->object($filePath);

                    if ($object->exists()) {
                        $object->delete();
                    }
                }

                $image = preg_replace('#^data:image/\w+;base64,#i', '', $base64);
                $image = base64_decode($image);

                if ($image === false || strlen($image) < 100) {
                    return response()->json([
                        'message' => 'Invalid base64 image'
                    ], 422);
                }

                $employeeId = $data['uploaded_by'] ?? 'unknown';
                $timestamp = Carbon::now('Asia/Manila')->format('Ymd_His_u');
                $fileName = "ocr_uploads/{$employeeId}_{$timestamp}.png";

                $object = $bucket->upload($image, [
                    'name' => $fileName,
                ]);

                $object->update([], ['predefinedAcl' => 'PUBLICREAD']);

                $bucketName = $bucket->name();
                $data['image_path'] = "https://storage.googleapis.com/{$bucketName}/{$fileName}";
            }

            else {
                unset($data['image_path']);
            }

            unset($data['image_base64']);

            $upload->update($data);

            return new OcrUploadResource($upload);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id, FirebaseService $firebase)
    {
        $upload = OcrUploads::findOrFail($id);

        try {
            if ($upload->image_path) {
                $storage = $firebase->storage();
                $bucket = $storage->getBucket();

                $oldFileName = basename($upload->image_path); // extract filename from stored URL
                $filePath = "ocr_uploads/" . $oldFileName;
                $object = $bucket->object($filePath);

                if ($object->exists()) {
                    $object->delete();
                    Log::info('File deleted successfully', ['file' => $filePath]);
                } else {
                    Log::warning('File NOT found in Firebase', ['file' => $filePath]);
                }
            }

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete image from Firebase',
                'error' => $e->getMessage()
            ], 500);
        }
        $upload->delete();

        return response()->json([
            'message' => 'Deleted successfully'
        ]);
    }

    private function deleteFromFirebase($imageUrl, FirebaseService $firebase)
    {
        $bucket = $firebase->storage()->getBucket();

        $parsedUrl = parse_url($imageUrl);
        $path = $parsedUrl['path'] ?? '';

        $bucketName = $bucket->name();
        $filePath = str_replace("/{$bucketName}/", '', $path);
        $filePath = ltrim($filePath, '/');

        $object = $bucket->object($filePath);

        if ($object->exists()) {
            $object->delete();
        }
    }
}
