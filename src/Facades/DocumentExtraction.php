<?php

declare(strict_types=1);

namespace Tamir\DocumentExtraction\Facades;

use Illuminate\Support\Facades\Facade;
use Tamir\DocumentExtraction\Services\DocumentExtractionService;

/**
 * @method static \Tamir\DocumentExtraction\Models\DocumentExtraction extractOrRetrieve(string $type, string $filename, bool $force = false)
 * @method static void processExtraction(\Tamir\DocumentExtraction\Models\DocumentExtraction $extraction)
 * @method static \Tamir\DocumentExtraction\Models\DocumentExtraction|null completeExtraction(string $taskId, array $generalFields, array $lineFields, array $fullPayload = [])
 * @method static \Tamir\DocumentExtraction\Models\DocumentExtraction|null failExtraction(\Tamir\DocumentExtraction\Models\DocumentExtraction|string $extractionOrTaskId, string $message)
 *
 * @see \Tamir\DocumentExtraction\Services\DocumentExtractionService
 */
class DocumentExtraction extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocumentExtractionService::class;
    }
}
