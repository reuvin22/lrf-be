<?php

namespace App\Services;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\ImageSource;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Google Cloud Vision. Used by callers (e.g. OCR uploads)
 * that want text extraction only when a given file opts into Vision — the
 * controller decides "if this file will use Vision", this service performs it.
 */
class GoogleVisionService
{
    /**
     * Whether Vision is configured and turned on. Callers can short-circuit on
     * this before doing any work (decoding images, etc.).
     */
    public function isEnabled(): bool
    {
        if (!config('services.google_vision.enabled')) {
            return false;
        }

        $credentials = config('services.google_vision.credentials');

        return is_string($credentials) && file_exists($credentials);
    }

    /**
     * Run document/text OCR on a publicly accessible image URL (e.g. the
     * Firebase public link stored in image_path). Vision fetches the URL itself
     * — no need to download the file first.
     *
     * @return array{text: string, blocks: array<int, string>}|null  null when Vision is disabled or the call fails.
     */
    public function extractTextFromUri(string $imageUri): ?array
    {
        if ($imageUri === '') {
            return null;
        }

        $source = (new ImageSource())->setImageUri($imageUri);

        return $this->detect((new Image())->setSource($source));
    }

    /**
     * Run document/text OCR on raw image bytes.
     *
     * @return array{text: string, blocks: array<int, string>}|null  null when Vision is disabled or the call fails.
     */
    public function extractText(string $imageBytes): ?array
    {
        if ($imageBytes === '') {
            return null;
        }

        return $this->detect((new Image())->setContent($imageBytes));
    }

    /**
     * Shared annotate call — sends one image (URL- or bytes-backed) through
     * DOCUMENT_TEXT_DETECTION and returns the full text plus the individual
     * detected word/line blocks (used to build the raw OCR JSON).
     *
     * @return array{text: string, blocks: array<int, string>}|null
     */
    private function detect(Image $image): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $client = null;

        try {
            $client = new ImageAnnotatorClient([
                'credentials' => config('services.google_vision.credentials'),
                // Force the pure-PHP REST transport. gRPC is a native extension
                // that can segfault / exhaust memory on constrained hosts (e.g.
                // App Engine) — and a native crash can't be caught below, taking
                // the whole request down as a 502. REST avoids that entirely.
                'transport'   => 'rest',
            ]);

            $feature = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);

            $imageRequest = (new AnnotateImageRequest())
                ->setImage($image)
                ->setFeatures([$feature]);

            $batch = (new BatchAnnotateImagesRequest())
                ->setRequests([$imageRequest]);

            $response   = $client->batchAnnotateImages($batch);
            $annotation = $response->getResponses()[0] ?? null;

            if ($annotation === null) {
                return null;
            }

            $error = $annotation->getError();
            if ($error && $error->getCode() !== 0) {
                Log::warning('Google Vision returned an error.', ['message' => $error->getMessage()]);
                return null;
            }

            $full = $annotation->getFullTextAnnotation();

            // textAnnotations[0] is the whole block; the rest are individual
            // words/lines — keep them for the raw JSON payload.
            $blocks = [];
            foreach ($annotation->getTextAnnotations() as $i => $ann) {
                if ($i === 0) continue;
                $blocks[] = $ann->getDescription();
            }

            return [
                'text'   => $full ? $full->getText() : '',
                'blocks' => $blocks,
            ];
        } catch (\Throwable $e) {
            Log::warning('Google Vision OCR failed.', ['error' => $e->getMessage()]);
            return null;
        } finally {
            if ($client !== null) {
                $client->close();
            }
        }
    }
}
