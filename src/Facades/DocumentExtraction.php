<?php

namespace TamirRental\DocumentExtraction\Facades;

use Illuminate\Support\Facades\Facade;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

/**
 * @method static \TamirRental\DocumentExtraction\PendingExtraction extract(string $type, string $filename)
 * @method static \TamirRental\DocumentExtraction\Models\DocumentExtraction|null complete(string $taskId, object $extractedData, string $identifier = '')
 * @method static \TamirRental\DocumentExtraction\Models\DocumentExtraction|null fail(\TamirRental\DocumentExtraction\Models\DocumentExtraction|string $extractionOrTaskId, string $message)
 *
 * @see \TamirRental\DocumentExtraction\Services\DocumentExtractionService
 */
class DocumentExtraction extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocumentExtractionService::class;
    }
}
