<?php

namespace App\Services;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\AsyncAnnotateFileRequest;
use Google\Cloud\Vision\V1\AsyncBatchAnnotateFilesRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\GcsDestination;
use Google\Cloud\Vision\V1\GcsSource;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\InputConfig;
use Google\Cloud\Vision\V1\OutputConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin wrapper around Google Cloud Vision's OCR (DOCUMENT_TEXT_DETECTION).
 * Used as a pre-processing step ahead of the LLM (Claude or Gemini): raw
 * image bytes / PDF pages are OCR'd here first, and the resulting plain text
 * — not the image/PDF itself — is what gets sent for structured extraction
 * (see LlmVisionService::buildVisionFile()). Every failure mode returns null
 * instead of throwing, so callers can fall back to sending the raw
 * image/PDF directly.
 *
 * Single images go through the synchronous per-image API (extractText()).
 * PDFs go through the separate async file API (extractPdfPagesFromUrl()):
 * Vision's synchronous per-image endpoint doesn't accept PDFs at all, and
 * even the synchronous *file* endpoint caps out at 5 pages per call — the
 * async endpoint is the only one that reads an arbitrary-length PDF (up to
 * 2000 pages) in one pass, but it requires the file to already live in GCS
 * and writes its per-page results back to GCS too, which the sync APIs
 * don't need.
 */
class GoogleVisionService
{
    public function __construct(private readonly FirebaseService $firebase) {}

    /**
     * Whether Vision is configured and turned on. Callers can short-circuit on
     * this before doing any work (decoding images, etc.).
     */
    public function isEnabled(): bool
    {
        if (! config('services.google_vision.enabled')) {
            return false;
        }

        $credentials = config('services.google_sheets.credentials');

        return is_string($credentials) && file_exists($credentials);
    }

    /**
     * Run document/text OCR on raw image bytes.
     *
     * @return array{text: string, blocks: array<int, string>}|null null when Vision is disabled or the call fails.
     */
    public function extractText(string $imageBytes): ?array
    {
        if (! $this->isEnabled() || $imageBytes === '') {
            return null;
        }

        $client = null;

        try {
            $client = new ImageAnnotatorClient([
                'credentials' => config('services.google_sheets.credentials'),
                // Force the pure-PHP REST transport. gRPC is a native extension
                // that isn't installed here and can segfault / exhaust memory
                // on constrained hosts — a native crash can't be caught below,
                // taking the whole request down. REST avoids that entirely.
                'transport' => 'rest',
            ]);

            $image = (new Image)->setContent($imageBytes);
            $feature = (new Feature)->setType(Type::DOCUMENT_TEXT_DETECTION);

            $imageRequest = (new AnnotateImageRequest)
                ->setImage($image)
                ->setFeatures([$feature]);

            $batch = (new BatchAnnotateImagesRequest)
                ->setRequests([$imageRequest]);

            $response = $client->batchAnnotateImages($batch);
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
            $text = $full ? $full->getText() : '';

            // textAnnotations[0] is the whole block; the rest are individual
            // words/lines — keep them for the raw JSON payload.
            $blocks = [];
            foreach ($annotation->getTextAnnotations() as $i => $ann) {
                if ($i === 0) {
                    continue;
                }
                $blocks[] = $ann->getDescription();
            }

            Log::info('Google Vision OCR succeeded.', [
                'text_length' => strlen($text),
                'block_count' => count($blocks),
                'text' => $text,
            ]);

            return [
                'text' => $text,
                'blocks' => $blocks,
            ];
        } catch (\Throwable $e) {
            Log::warning('Google Vision OCR failed.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $client?->close();
        }
    }

    /**
     * Run document-text OCR on every page of a PDF already sitting in the
     * Firebase/GCS bucket (identified by its public storage.googleapis.com
     * URL), via Vision's async file-annotation flow:
     *
     *   1. Point Vision at the file in GCS (it reads it directly — no bytes
     *      are uploaded in this request).
     *   2. Wait for the async operation to finish.
     *   3. Vision writes one JSON "shard" per up-to-20-page batch to a
     *      scratch GCS prefix; read every shard back and pull each page's
     *      text out by its pageNumber (shard order isn't page order).
     *   4. Delete the scratch shards — they're not meant to persist.
     *
     * @return array<int, string>|null  page number => page text, in page order; null when Vision is disabled, the file can't be resolved to a GCS object, or the call fails.
     */
    public function extractPdfPagesFromUrl(string $publicUrl): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $bucket = $this->firebase->storage()->getBucket();
        $objectPath = $this->objectPathFromUrl($bucket, $publicUrl);

        if ($objectPath === null) {
            Log::warning('Could not resolve a GCS object path for PDF OCR.', ['url' => $publicUrl]);

            return null;
        }

        $gcsUri = 'gs://'.$bucket->name().'/'.$objectPath;
        $outputPrefix = 'vision_ocr_tmp/'.(string) Str::uuid().'/';
        $outputUri = 'gs://'.$bucket->name().'/'.$outputPrefix;

        $client = null;

        try {
            $client = new ImageAnnotatorClient([
                'credentials' => config('services.google_sheets.credentials'),
                'transport' => 'rest',
            ]);

            $inputConfig = (new InputConfig)
                ->setGcsSource((new GcsSource)->setUri($gcsUri))
                ->setMimeType('application/pdf');

            $outputConfig = (new OutputConfig)
                ->setGcsDestination((new GcsDestination)->setUri($outputUri))
                ->setBatchSize(20);

            $fileRequest = (new AsyncAnnotateFileRequest)
                ->setInputConfig($inputConfig)
                ->setFeatures([(new Feature)->setType(Type::DOCUMENT_TEXT_DETECTION)])
                ->setOutputConfig($outputConfig);

            $operation = $client->asyncBatchAnnotateFiles(
                AsyncBatchAnnotateFilesRequest::build([$fileRequest])
            );

            // Sync calls elsewhere in this app cap at ~120s; the queued jobs
            // that call this have a 180s job timeout, so leave headroom for
            // the Gemini/Claude call that follows.
            $operation->pollUntilComplete(['totalPollTimeoutMillis' => 120000]);

            if (! $operation->operationSucceeded()) {
                Log::warning('Google Vision async PDF OCR did not succeed.', [
                    'gcs_uri' => $gcsUri,
                    'error' => $operation->getError()?->getMessage(),
                ]);

                return null;
            }

            return $this->collectAndCleanUpPages($bucket, $outputPrefix, $gcsUri);
        } catch (\Throwable $e) {
            Log::warning('Google Vision async PDF OCR threw.', ['gcs_uri' => $gcsUri, 'error' => $e->getMessage()]);

            return null;
        } finally {
            $client?->close();
        }
    }

    /**
     * Read every JSON output shard Vision wrote under $prefix, pull each
     * page's text out keyed by its actual page number (shards aren't
     * necessarily in page order), then delete the shards.
     *
     * @return array<int, string>|null
     */
    private function collectAndCleanUpPages(Bucket $bucket, string $prefix, string $gcsUri): ?array
    {
        $pages = [];

        foreach ($bucket->objects(['prefix' => $prefix]) as $object) {
            $shard = json_decode($object->downloadAsString(), true);

            foreach (($shard['responses'] ?? []) as $response) {
                $pageNumber = $response['context']['pageNumber'] ?? (count($pages) + 1);
                $pages[$pageNumber] = $response['fullTextAnnotation']['text'] ?? '';
            }

            try {
                $object->delete();
            } catch (\Throwable $e) {
                Log::warning('Failed to delete Vision OCR scratch output.', ['object' => $object->name(), 'error' => $e->getMessage()]);
            }
        }

        if (empty($pages)) {
            Log::warning('Google Vision async PDF OCR produced no pages.', ['gcs_uri' => $gcsUri]);

            return null;
        }

        ksort($pages);

        foreach ($pages as $pageNumber => $text) {
            Log::info('Google Vision OCR page extracted.', [
                'gcs_uri' => $gcsUri,
                'page' => $pageNumber,
                'text_length' => strlen($text),
            ]);
        }

        return $pages;
    }

    /**
     * Extract the bucket-relative object path from a public
     * storage.googleapis.com URL, e.g.
     * "https://storage.googleapis.com/{bucket}/ocr_uploads/foo.pdf" -> "ocr_uploads/foo.pdf".
     */
    private function objectPathFromUrl(Bucket $bucket, string $url): ?string
    {
        $parsed = parse_url($url);
        $path = ltrim(str_replace('/'.$bucket->name().'/', '', $parsed['path'] ?? ''), '/');

        return $path !== '' ? $path : null;
    }
}
