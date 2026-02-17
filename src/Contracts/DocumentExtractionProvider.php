<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Contracts;

interface DocumentExtractionProvider
{
    /**
     * Upload a file for extraction and return the provider's response.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, external_id?: string, message: string}
     */
    public function extract(string $filePath, string $documentType, array $metadata = []): array;
}
