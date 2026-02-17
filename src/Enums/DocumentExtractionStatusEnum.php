<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Enums;

enum DocumentExtractionStatusEnum: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return __("document-extraction::document_extractions.status.{$this->value}");
    }
}
