<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Events\DocumentExtractionRequested;
use TamirRental\DocumentExtraction\Listeners\ProcessDocumentExtraction;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;
use TamirRental\DocumentExtraction\PendingExtraction;
use TamirRental\DocumentExtraction\Providers\KoncileAi\KoncileAiIntegration;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

beforeEach(function () {
    $this->mockProvider = Mockery::mock(DocumentExtractionProvider::class);
    $this->app->instance(DocumentExtractionProvider::class, $this->mockProvider);
    $this->service = $this->app->make(DocumentExtractionService::class);
});

// --- extract (fluent API) ---

it('returns a PendingExtraction instance from extract', function () {
    $pending = $this->service->extract('car_license', 'documents/test.pdf');

    expect($pending)->toBeInstanceOf(PendingExtraction::class);
});

it('creates a new pending extraction and dispatches event', function () {
    Event::fake([DocumentExtractionRequested::class]);

    $extraction = $this->service->extract('car_license', 'documents/test.pdf')->submit();

    expect($extraction)
        ->toBeInstanceOf(DocumentExtraction::class)
        ->status->toBe(DocumentExtractionStatusEnum::Pending)
        ->type->toBe('car_license')
        ->filename->toBe('documents/test.pdf')
        ->identifier->toBe('')
        ->extracted_data->toEqual((object) []);

    Event::assertDispatched(DocumentExtractionRequested::class, function ($event) use ($extraction) {
        return $event->documentExtraction->id === $extraction->id;
    });
});

it('returns existing extraction without dispatching event', function () {
    Event::fake([DocumentExtractionRequested::class]);

    $existing = DocumentExtraction::factory()->create([
        'type' => 'car_license',
        'filename' => 'documents/test.pdf',
    ]);

    $result = $this->service->extract('car_license', 'documents/test.pdf')->submit();

    expect($result->id)->toBe($existing->id);

    Event::assertNotDispatched(DocumentExtractionRequested::class);
});

it('creates new extraction with force and dispatches event', function () {
    Event::fake([DocumentExtractionRequested::class]);

    $existing = DocumentExtraction::factory()->create([
        'type' => 'car_license',
        'filename' => 'documents/test.pdf',
    ]);

    $result = $this->service->extract('car_license', 'documents/test.pdf')
        ->force()
        ->submit();

    expect($result->id)->not->toBe($existing->id);
    expect(DocumentExtraction::count())->toBe(2);

    Event::assertDispatched(DocumentExtractionRequested::class);
});

it('stores metadata via fluent method', function () {
    Event::fake([DocumentExtractionRequested::class]);

    $metadata = ['template_id' => '26790', 'identifier_field' => 'license_number'];

    $extraction = $this->service->extract('car_license', 'documents/test.pdf')
        ->metadata($metadata)
        ->submit();

    expect($extraction->metadata)->toBe($metadata);
});

it('supports conditional force with when', function () {
    Event::fake([DocumentExtractionRequested::class]);

    $existing = DocumentExtraction::factory()->create([
        'type' => 'car_license',
        'filename' => 'documents/test.pdf',
    ]);

    $shouldForce = true;

    $result = $this->service->extract('car_license', 'documents/test.pdf')
        ->when($shouldForce, fn (PendingExtraction $pending) => $pending->force())
        ->submit();

    expect($result->id)->not->toBe($existing->id);
});

it('does not force when condition is false', function () {
    Event::fake([DocumentExtractionRequested::class]);

    $existing = DocumentExtraction::factory()->create([
        'type' => 'car_license',
        'filename' => 'documents/test.pdf',
    ]);

    $shouldForce = false;

    $result = $this->service->extract('car_license', 'documents/test.pdf')
        ->when($shouldForce, fn (PendingExtraction $pending) => $pending->force())
        ->submit();

    expect($result->id)->toBe($existing->id);
    Event::assertNotDispatched(DocumentExtractionRequested::class);
});

// --- processExtraction ---

it('downloads file from storage and uploads to provider', function () {
    Storage::fake();
    Storage::put('documents/test.pdf', 'file-contents');

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
    ]);

    $this->mockProvider
        ->shouldReceive('extract')
        ->once()
        ->andReturn([
            'status' => 'pending',
            'external_id' => 'task-123',
            'message' => 'File uploaded successfully.',
        ]);

    $this->service->processExtraction($extraction);

    $extraction->refresh();
    expect($extraction->external_task_id)->toBe('task-123');
});

it('marks extraction as failed when file not found in storage', function () {
    Storage::fake();

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/missing.pdf',
    ]);

    $this->service->processExtraction($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('File not found in storage');
});

it('marks extraction as failed when provider returns non-pending status', function () {
    Storage::fake();
    Storage::put('documents/test.pdf', 'file-contents');

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
    ]);

    $this->mockProvider
        ->shouldReceive('extract')
        ->once()
        ->andReturn([
            'status' => 'failed',
            'message' => 'Template not found.',
        ]);

    $this->service->processExtraction($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toBe('Template not found.');
});

it('marks extraction as failed when provider throws exception', function () {
    Storage::fake();
    Storage::put('documents/test.pdf', 'file-contents');

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
    ]);

    $this->mockProvider
        ->shouldReceive('extract')
        ->once()
        ->andThrow(new RuntimeException('Connection timeout'));

    $this->service->processExtraction($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toBe('Connection timeout');
});

it('overwrites external_task_id when re-processing an extraction', function () {
    Storage::fake();
    Storage::put('documents/test.pdf', 'file-contents');

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'external_task_id' => 'old-task-id',
    ]);

    $this->mockProvider
        ->shouldReceive('extract')
        ->once()
        ->andReturn([
            'status' => 'pending',
            'external_id' => 'new-task-id',
            'message' => 'File uploaded successfully.',
        ]);

    $this->service->processExtraction($extraction);

    $extraction->refresh();
    expect($extraction->external_task_id)->toBe('new-task-id');
});

// --- complete ---

it('completes extraction with extracted data', function () {
    $extraction = DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-456',
    ]);

    $extractedData = (object) [
        'General_fields' => [
            'license_number' => ['value' => '12-345-67', 'confidence_score' => 0.95],
        ],
        'Line_fields' => [],
    ];

    $result = $this->service->complete('task-456', $extractedData, '12-345-67');

    expect($result)
        ->not->toBeNull()
        ->status->toBe(DocumentExtractionStatusEnum::Completed)
        ->identifier->toBe('12-345-67');

    expect($result->extracted_data->General_fields)->toBeObject();
});

it('returns null when completing extraction with unknown task id', function () {
    $result = $this->service->complete('unknown-task', (object) []);

    expect($result)->toBeNull();
});

it('completes extraction with empty identifier when not provided', function () {
    $extraction = DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-789',
    ]);

    $result = $this->service->complete('task-789', (object) []);

    expect($result->identifier)->toBe('');
});

// --- fail ---

it('fails extraction by model instance', function () {
    $extraction = DocumentExtraction::factory()->pending()->create();

    $result = $this->service->fail($extraction, 'Something went wrong');

    expect($result)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toBe('Something went wrong');
});

it('fails extraction by external task id', function () {
    $extraction = DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-fail-1',
    ]);

    $result = $this->service->fail('task-fail-1', 'Provider error');

    expect($result)
        ->not->toBeNull()
        ->id->toBe($extraction->id)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toBe('Provider error');
});

it('returns null when failing extraction with unknown task id', function () {
    $result = $this->service->fail('unknown-task', 'Some error');

    expect($result)->toBeNull();
});

// --- Event + Listener ---

it('listener calls processExtraction on the service', function () {
    Storage::fake();
    Storage::put('documents/test.pdf', 'file-contents');

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
    ]);

    $this->mockProvider
        ->shouldReceive('extract')
        ->once()
        ->andReturn([
            'status' => 'pending',
            'external_id' => 'task-listener',
            'message' => 'Uploaded.',
        ]);

    $listener = $this->app->make(ProcessDocumentExtraction::class);
    $listener->handle(new DocumentExtractionRequested($extraction));

    $extraction->refresh();
    expect($extraction->external_task_id)->toBe('task-listener');
});

// --- Service Provider ---

it('binds DocumentExtractionProvider from config', function () {
    $this->app->forgetInstance(DocumentExtractionProvider::class);

    $provider = $this->app->make(DocumentExtractionProvider::class);

    expect($provider)->toBeInstanceOf(KoncileAiIntegration::class);
});

it('registers DocumentExtractionService as singleton', function () {
    $serviceA = $this->app->make(DocumentExtractionService::class);
    $serviceB = $this->app->make(DocumentExtractionService::class);

    expect($serviceA)->toBe($serviceB);
});

it('throws exception for unsupported provider', function () {
    config(['document-extraction.default' => 'unsupported_provider']);

    $this->app->forgetInstance(DocumentExtractionProvider::class);

    $this->app->make(DocumentExtractionProvider::class);
})->throws(\InvalidArgumentException::class, 'Unsupported extraction provider: unsupported_provider');
