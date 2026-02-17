<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Facades;

use Illuminate\Support\Facades\Facade;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

/**
 * @method static \TamirRental\DocumentExtraction\Models\DocumentExtraction extractOrRetrieve(string $type, string $filename, bool $force = false)
 * @method static void processExtraction(\TamirRental\DocumentExtraction\Models\DocumentExtraction $extraction)
 * @method static \TamirRental\DocumentExtraction\Models\DocumentExtraction|null completeExtraction(string $taskId, array $generalFields, array $lineFields, array $fullPayload = [])
 * @method static \TamirRental\DocumentExtraction\Models\DocumentExtraction|null failExtraction(\TamirRental\DocumentExtraction\Models\DocumentExtraction|string $extractionOrTaskId, string $message)
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
