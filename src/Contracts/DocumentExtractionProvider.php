<?php

namespace TamirRental\DocumentExtraction\Contracts;

use TamirRental\DocumentExtraction\Models\DocumentExtraction;

interface DocumentExtractionProvider
{
    /**
     * Process a document extraction request.
     *
     * The provider is responsible for the full workflow: downloading the file,
     * uploading to the extraction service, and updating the model accordingly.
     */
    public function process(DocumentExtraction $extraction): void;
}
