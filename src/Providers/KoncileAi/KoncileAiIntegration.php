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
    protected array $config;

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
        try {
            $contents = Storage::get($extraction->filename);

            if ($contents === null) {
                $this->fail($extraction, "File not found in storage: {$extraction->filename}");

                return;
            }

            $result = $this->upload($contents, basename($extraction->filename), $extraction->type, $extraction->metadata ?? []);

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
        }
    }

    /**
     * Upload file contents to Koncile AI for extraction.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, external_id?: string, message: string}
     */
    protected function upload(string $contents, string $filename, string $documentType, array $metadata = []): array
    {
        $templateId = $metadata['template_id'] ?? null;

        if (! $templateId) {
            Log::error('No template_id provided in metadata', [
                'document_type' => $documentType,
            ]);

            return $this->failedResponse("No template_id provided in metadata for document type: {$documentType}");
        }

        $folderId = $metadata['folder_id'] ?? null;

        try {
            $queryParams = ['template_id' => $templateId];

            if ($folderId) {
                $queryParams['folder_id'] = $folderId;
            }

            $url = rtrim($this->config['url'], '/').'/v1/upload_file/?'.http_build_query($queryParams);

            $response = Http::withToken($this->config['key'])
                ->attach('files', $contents, $filename)
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
                'filename' => $filename,
            ]);

            return $this->failedResponse("Network error: {$e->getMessage()}");
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    protected function failedResponse(string $message): array
    {
        return [
            'status' => DocumentExtractionStatusEnum::Failed->value,
            'message' => $message,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    protected function handleErrorResponse(int $statusCode, string $body): array
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
    protected function fail(DocumentExtraction $extraction, string $message): void
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
