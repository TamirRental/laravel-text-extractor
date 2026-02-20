<?php

namespace TamirRental\DocumentExtraction\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use TamirRental\DocumentExtraction\Events\DocumentExtractionRequested;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

class ProcessDocumentExtraction implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public function __construct(
        private DocumentExtractionService $service,
    ) {}

    public function handle(DocumentExtractionRequested $event): void
    {
        $this->service->processExtraction($event->documentExtraction);
    }
}
