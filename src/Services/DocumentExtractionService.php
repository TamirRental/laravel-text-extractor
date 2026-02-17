<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Events\DocumentExtractionRequested;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;

class DocumentExtractionService
{
    public function __construct(
        private DocumentExtractionProvider $provider,
    ) {}

    /**
     * Find an existing extraction or create a new pending one.
     *
     * When a new extraction is created, the DocumentExtractionRequested
     * event is automatically dispatched to trigger async processing.
     *
     * @param  array<string, mixed>  $metadata  Provider-specific data (e.g. template_id, folder_id, identifier_field)
     */
    public function extractOrRetrieve(string $type, string $filename, array $metadata = [], bool $force = false): DocumentExtraction
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
     * Download the file from S3, upload to provider, and save the external task ID.
     */
    public function processExtraction(DocumentExtraction $extraction): void
    {
        $tempPath = null;

        try {
            $tempPath = sys_get_temp_dir().'/'.uniqid('extraction_').'-'.basename($extraction->filename);

            $contents = Storage::get($extraction->filename);

            if ($contents === null) {
                $this->failExtraction(
                    $extraction,
                    "File not found in storage: {$extraction->filename}"
                );

                return;
            }

            file_put_contents($tempPath, $contents);

            $result = $this->provider->extract($tempPath, $extraction->type, $extraction->metadata ?? []);

            if ($result['status'] === DocumentExtractionStatusEnum::Pending->value && !empty($result['data']['task_ids'])) {
                $extraction->update([
                    'external_task_id' => $result['data']['task_ids'][0],
                ]);

                Log::info('Document extraction submitted', [
                    'extraction_id' => $extraction->id,
                    'external_task_id' => $result['data']['task_ids'][0],
                ]);
            } else {
                $this->failExtraction($extraction, $result['message'] ?? 'Unexpected provider response.');
            }
        } catch (\Throwable $e) {
            Log::error('Document extraction failed', [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
            ]);

            $this->failExtraction($extraction, $e->getMessage());
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Mark an extraction as completed with the extracted data.
     *
     * @param  array<string, mixed>  $generalFields
     * @param  array<string, mixed>  $lineFields
     * @param  array<string, mixed>  $fullPayload
     */
    public function completeExtraction(string $taskId, array $generalFields, array $lineFields, array $fullPayload = []): ?DocumentExtraction
    {
        $extraction = $this->findByTaskId($taskId);

        if (!$extraction) {
            Log::warning('No extraction found for task ID', ['task_id' => $taskId]);

            return null;
        }

        $extractedData = (object) ($fullPayload ?: [
            'general_fields' => $generalFields,
            'line_fields' => $lineFields,
        ]);

        $extraction->update([
            'status' => DocumentExtractionStatusEnum::Completed,
            'identifier' => $this->resolveIdentifier($extraction, $generalFields),
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
    public function failExtraction(DocumentExtraction|string $extractionOrTaskId, string $message): ?DocumentExtraction
    {
        $extraction = $extractionOrTaskId instanceof DocumentExtraction
            ? $extractionOrTaskId
            : $this->findByTaskId($extractionOrTaskId);

        if (!$extraction) {
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

    /**
     * Resolve the identifier from extracted data using metadata's identifier_field.
     *
     * @param  array<string, mixed>  $generalFields
     */
    private function resolveIdentifier(DocumentExtraction $extraction, array $generalFields): string
    {
        $identifierField = $extraction->metadata['identifier_field'] ?? null;

        if (! $identifierField) {
            return '';
        }

        return $this->extractFieldValue($generalFields, $identifierField);
    }

    /**
     * Extract a field value from general fields structure.
     *
     * @param  array<string, mixed>  $fields
     */
    private function extractFieldValue(array $fields, string $fieldName): string
    {
        if (isset($fields[$fieldName]['value'])) {
            return (string) $fields[$fieldName]['value'];
        }

        return '';
    }
}
