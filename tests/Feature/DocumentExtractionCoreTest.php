<?php

declare(strict_types=1);

use Tamir\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use Tamir\DocumentExtraction\Enums\DocumentTypeEnum;
use Tamir\DocumentExtraction\Models\DocumentExtraction;
use Tamir\DocumentExtraction\Tests\TestCase;

uses(TestCase::class);

it('creates a document extraction with factory defaults', function () {
    $extraction = DocumentExtraction::factory()->create();

    expect($extraction)
        ->type->toBe(DocumentTypeEnum::CarLicense)
        ->status->toBe(DocumentExtractionStatusEnum::Pending)
        ->identifier->toBe('')
        ->extracted_data->toEqual((object) [])
        ->error_message->toBeNull()
        ->external_task_id->toBeNull();
});

it('creates a pending extraction with external task id', function () {
    $extraction = DocumentExtraction::factory()->pending()->create();

    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Pending)
        ->external_task_id->not->toBeNull();
});

it('creates a completed extraction with data', function () {
    $extraction = DocumentExtraction::factory()->completed()->create();

    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Completed)
        ->identifier->not->toBeEmpty()
        ->extracted_data->not->toBeNull()
        ->external_task_id->not->toBeNull();
});

it('creates a failed extraction with error message', function () {
    $extraction = DocumentExtraction::factory()->failed()->create();

    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->not->toBeNull()
        ->external_task_id->not->toBeNull();
});

it('casts extracted_data as object', function () {
    $extraction = DocumentExtraction::factory()->completed()->create();

    expect($extraction->extracted_data)->toBeObject();
});

it('filters by pending scope', function () {
    DocumentExtraction::factory()->pending()->create();
    DocumentExtraction::factory()->completed()->create();
    DocumentExtraction::factory()->failed()->create();

    expect(DocumentExtraction::pending()->count())->toBe(1);
});

it('filters by completed scope', function () {
    DocumentExtraction::factory()->pending()->create();
    DocumentExtraction::factory()->completed()->count(2)->create();

    expect(DocumentExtraction::completed()->count())->toBe(2);
});

it('filters by failed scope', function () {
    DocumentExtraction::factory()->pending()->create();
    DocumentExtraction::factory()->failed()->create();

    expect(DocumentExtraction::failed()->count())->toBe(1);
});

it('filters by type scope', function () {
    DocumentExtraction::factory()->create(['type' => DocumentTypeEnum::CarLicense]);

    expect(DocumentExtraction::forType(DocumentTypeEnum::CarLicense)->count())->toBe(1);
});

it('filters by file scope', function () {
    DocumentExtraction::factory()->create(['filename' => 'test-doc.pdf']);
    DocumentExtraction::factory()->create(['filename' => 'other-doc.pdf']);

    expect(DocumentExtraction::forFile('test-doc.pdf')->count())->toBe(1);
});

it('resolves enum labels via translation keys', function () {
    expect(DocumentTypeEnum::CarLicense->label())->toBe('Car License');
    expect(DocumentExtractionStatusEnum::Pending->label())->toBe('Pending');
    expect(DocumentExtractionStatusEnum::Completed->label())->toBe('Completed');
    expect(DocumentExtractionStatusEnum::Failed->label())->toBe('Failed');
});
