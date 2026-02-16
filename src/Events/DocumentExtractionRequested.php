<?php

declare(strict_types=1);

namespace Tamir\DocumentExtraction\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Tamir\DocumentExtraction\Models\DocumentExtraction;

class DocumentExtractionRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public DocumentExtraction $documentExtraction,
    ) {}
}
