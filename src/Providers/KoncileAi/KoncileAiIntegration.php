<?php

namespace TamirRental\DocumentExtraction\Providers\KoncileAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;

class KoncileAiIntegration implements DocumentExtractionProvider
{
    /**
     * @var array{url: ?string, key: ?string, webhook_secret: ?string}
     */
    private array $config;

    public function __construct()
    {
        /** @var array{url: ?string, key: ?string, webhook_secret: ?string} $config */
        $config = config('document-extraction.providers.koncile_ai');
        $this->config = $config;

        $missing = array_filter(
            ['url', 'key'],
            fn (string $key): bool => empty($this->config[$key]),
        );

        if ($missing) {
            throw new InvalidArgumentException(
                'Koncile AI config missing required key(s): '.implode(', ', $missing),
            );
        }
    }

    /**
     * Process a document extraction request.
     *
     * Downloads the file from storage, uploads it to Koncile AI,
     * and updates the extraction model with the result.
     */
    public function process(DocumentExtraction $extraction): void
    {
        $tempPath = null;

        try {
            $tempPath = sys_get_temp_dir().'/'.uniqid('extraction_').'-'.basename($extraction->filename);

            $contents = Storage::get($extraction->filename);

            if ($contents === null) {
                $this->fail($extraction, "File not found in storage: {$extraction->filename}");

                return;
            }

            file_put_contents($tempPath, $contents);

            $result = $this->upload($tempPath, $extraction->type, $extraction->metadata ?? []);

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
     * Upload a file to Koncile AI for extraction.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, external_id?: string, message: string}
     */
    private function upload(string $filePath, string $documentType, array $metadata = []): array
    {
        $templateId = $metadata['template_id'] ?? null;

        if (! $templateId) {
            Log::error('No template_id provided in metadata', [
                'document_type' => $documentType,
            ]);

            return $this->failedResponse("No template_id provided in metadata for document type: {$documentType}");
        }

        $folderId = $metadata['folder_id'] ?? null;

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            Log::error('File not accessible for extraction', [
                'file' => $filePath,
            ]);

            return $this->failedResponse("File not accessible: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            Log::error('Failed to open file for extraction', ['file' => $filePath]);

            return $this->failedResponse("Failed to open file: {$filePath}");
        }

        try {
            $queryParams = ['template_id' => $templateId];

            if ($folderId) {
                $queryParams['folder_id'] = $folderId;
            }

            $url = rtrim($this->config['url'], '/').'/v1/upload_file/?'.http_build_query($queryParams);

            $response = Http::withToken($this->config['key'])
                ->attach('files', $handle, basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Koncile AI file uploaded', [
                    'task_ids' => $data['task_ids'] ?? [],
                    'template_id' => $templateId,
                ]);

                return [
                    'status' => DocumentExtractionStatusEnum::Pending->value,
                    'external_id' => $data['task_ids'][0] ?? null,
                    'message' => 'File uploaded successfully.',
                ];
            }

            return $this->handleErrorResponse($response->status(), $response->body());
        } catch (\Throwable $e) {
            Log::error('Koncile AI upload exception', [
                'error' => $e->getMessage(),
                'file' => $filePath,
            ]);

            return $this->failedResponse("Network error: {$e->getMessage()}");
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    private function failedResponse(string $message): array
    {
        return [
            'status' => DocumentExtractionStatusEnum::Failed->value,
            'message' => $message,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function handleErrorResponse(int $statusCode, string $body): array
    {
        $message = match (true) {
            in_array($statusCode, [401, 403], true) => 'Authentication failed with Koncile AI.',
            in_array($statusCode, [400, 422], true) => "Validation error from Koncile AI: {$body}",
            $statusCode >= 500 => 'Koncile AI server error. Please try again later.',
            default => "Unexpected response from Koncile AI (HTTP {$statusCode}): {$body}",
        };

        Log::error('Koncile AI error response', [
            'status_code' => $statusCode,
            'body' => $body,
        ]);

        return $this->failedResponse($message);
    }

    /**
     * Mark an extraction as failed with the given message.
     */
    private function fail(DocumentExtraction $extraction, string $message): void
    {
        $extraction->update([
            'status' => DocumentExtractionStatusEnum::Failed,
            'error_message' => $message,
        ]);

        Log::error('Document extraction failed', [
            'extraction_id' => $extraction->id,
            'error_message' => $message,
        ]);
    }
}
