<?php

namespace TamirRental\DocumentExtraction\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Events\DocumentExtractionRequested;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;
use TamirRental\DocumentExtraction\PendingExtraction;

class DocumentExtractionService
{
    public function __construct(
        private DocumentExtractionProvider $provider,
    ) {}

    /**
     * Begin a fluent extraction request.
     *
     * Returns a PendingExtraction that can be configured with
     * metadata and force options before calling submit().
     */
    public function extract(string $type, string $filename): PendingExtraction
    {
        return new PendingExtraction($this, $type, $filename);
    }

    /**
     * Find an existing extraction or create a new pending one.
     *
     * Called internally by PendingExtraction::submit().
     *
     * @param  array<string, mixed>  $metadata
     *
     * @internal
     */
    public function execute(string $type, string $filename, array $metadata = [], bool $force = false): DocumentExtraction
    {
        if (! $force) {
            $existing = DocumentExtraction::query()
                ->forType($type)
                ->forFile($filename)
                ->latest()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $extraction = DocumentExtraction::create([
            'type' => $type,
            'filename' => $filename,
            'identifier' => '',
            'extracted_data' => (object) [],
            'metadata' => $metadata,
            'status' => DocumentExtractionStatusEnum::Pending,
        ]);

        DocumentExtractionRequested::dispatch($extraction);

        return $extraction;
    }

    /**
     * Download the file from storage, upload to provider, and save the external ID.
     */
    public function processExtraction(DocumentExtraction $extraction): void
    {
        $tempPath = null;

        try {
            $tempPath = sys_get_temp_dir().'/'.uniqid('extraction_').'-'.basename($extraction->filename);

            $contents = Storage::get($extraction->filename);

            if ($contents === null) {
                $this->fail(
                    $extraction,
                    "File not found in storage: {$extraction->filename}"
                );

                return;
            }

            file_put_contents($tempPath, $contents);

            $result = $this->provider->extract($tempPath, $extraction->type, $extraction->metadata ?? []);

            if ($result['status'] === DocumentExtractionStatusEnum::Pending->value && ! empty($result['external_id'])) {
                $extraction->update([
                    'external_task_id' => $result['external_id'],
                ]);

                Log::info('Document extraction submitted', [
                    'extraction_id' => $extraction->id,
                    'external_task_id' => $result['external_id'],
                ]);
            } else {
                $this->fail($extraction, $result['message'] ?? 'Unexpected provider response.');
            }
        } catch (\Throwable $e) {
            Log::error('Document extraction failed', [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($extraction, $e->getMessage());
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Mark an extraction as completed with the extracted data.
     */
    public function complete(string $taskId, object $extractedData, string $identifier = ''): ?DocumentExtraction
    {
        $extraction = $this->findByTaskId($taskId);

        if (! $extraction) {
            Log::warning('No extraction found for task ID', ['task_id' => $taskId]);

            return null;
        }

        $extraction->update([
            'status' => DocumentExtractionStatusEnum::Completed,
            'identifier' => $identifier,
            'extracted_data' => $extractedData,
        ]);

        Log::info('Document extraction completed', [
            'extraction_id' => $extraction->id,
            'external_task_id' => $taskId,
        ]);

        return $extraction;
    }

    /**
     * Mark an extraction as failed.
     */
    public function fail(DocumentExtraction|string $extractionOrTaskId, string $message): ?DocumentExtraction
    {
        $extraction = $extractionOrTaskId instanceof DocumentExtraction
            ? $extractionOrTaskId
            : $this->findByTaskId($extractionOrTaskId);

        if (! $extraction) {
            Log::warning('No extraction found for failure', [
                'identifier' => $extractionOrTaskId,
                'message' => $message,
            ]);

            return null;
        }

        $extraction->update([
            'status' => DocumentExtractionStatusEnum::Failed,
            'error_message' => $message,
        ]);

        Log::error('Document extraction failed', [
            'extraction_id' => $extraction->id,
            'error_message' => $message,
        ]);

        return $extraction;
    }

    /**
     * Find a document extraction by its external task ID.
     */
    private function findByTaskId(string $taskId): ?DocumentExtraction
    {
        return DocumentExtraction::query()
            ->where('external_task_id', $taskId)
            ->first();
    }
}
