<?php

namespace TamirRental\DocumentExtraction;

use Illuminate\Support\Traits\Conditionable;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

class PendingExtraction
{
    use Conditionable;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private bool $force = false;

    public function __construct(
        private DocumentExtractionService $service,
        private string $type,
        private string $filename,
    ) {}

    /**
     * Set provider-specific metadata for this extraction.
     *
     * @param  array<string, mixed>  $metadata
     * @return $this
     */
    public function metadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Force a new extraction even if one already exists.
     *
     * @return $this
     */
    public function force(bool $force = true): static
    {
        $this->force = $force;

        return $this;
    }

    /**
     * Execute the extraction and return the model.
     */
    public function submit(): DocumentExtraction
    {
        return $this->service->execute(
            $this->type,
            $this->filename,
            $this->metadata,
            $this->force,
        );
    }
}
