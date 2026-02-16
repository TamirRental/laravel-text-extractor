<?php

declare(strict_types=1);

namespace Tamir\DocumentExtraction\Enums;

enum DocumentTypeEnum: string
{
    case CarLicense = 'car_license';

    public function label(): string
    {
        return __("document-extraction::document_extractions.type.{$this->value}");
    }
}
