<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Providers\KoncileAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;

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
     * @return array{status: string, data?: array<string, mixed>, message: string}
     */
    public function extract(string $filePath, string $documentType): array
    {
        $templateId = $this->resolveTemplateId($documentType);

        if (!$templateId) {
            Log::error('No template configured for document type', [
                'document_type' => $documentType,
            ]);

            return $this->failedResponse("No template configured for document type: {$documentType}");
        }

        $folderId = $this->resolveFolderId($documentType);

        if (!file_exists($filePath) || !is_readable($filePath)) {
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
                    'data' => $data,
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

    private function resolveTemplateId(string $documentType): ?string
    {
        /** @var array<string, array{template_id: ?string, folder_id: ?string, identifier: string}> $types */
        $types = config('document-extraction-types', []);

        return $types[$documentType]['template_id'] ?? null;
    }

    private function resolveFolderId(string $documentType): ?string
    {
        /** @var array<string, array{template_id: ?string, folder_id: ?string, identifier: string}> $types */
        $types = config('document-extraction-types', []);

        return $types[$documentType]['folder_id'] ?? null;
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
}
