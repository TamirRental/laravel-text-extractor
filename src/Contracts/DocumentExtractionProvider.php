<?php

declare(strict_types=1);

namespace Tamir\DocumentExtraction\Contracts;

use Tamir\DocumentExtraction\Enums\DocumentTypeEnum;

interface DocumentExtractionProvider
{
    /**
     * Upload a file for extraction and return the provider's response.
     *
     * @return array{status: string, data?: array<string, mixed>, message: string}
     */
    public function extract(string $filePath, DocumentTypeEnum $documentType): array;
}
