<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;
use TamirRental\DocumentExtraction\Providers\KoncileAi\KoncileAiIntegration;

beforeEach(function () {
    config([
        'document-extraction.providers.koncile_ai' => [
            'url' => 'https://api.koncile.ai',
            'key' => 'test-api-key',
            'webhook_secret' => 'test-secret',
        ],
    ]);

    Storage::fake();

    $this->integration = new KoncileAiIntegration;

    $this->metadata = [
        'template_id' => 'template-car-123',
        'folder_id' => 'folder-car-456',
        'identifier_field' => 'license_number',
    ];
});

it('processes extraction successfully and stores external task id', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response([
            'task_ids' => ['task-abc-123'],
        ], 200),
    ]);

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'type' => 'car_license',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction->external_task_id)->toBe('task-abc-123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'v1/upload_file')
            && str_contains($request->url(), 'template_id=template-car-123')
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

it('fails extraction when file not found in storage', function () {
    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/missing.pdf',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('File not found in storage');
});

it('fails extraction when no template_id in metadata', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'type' => 'car_license',
        'metadata' => [],
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('No template_id provided in metadata');
});

it('fails extraction on authentication error from koncile api', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response('Unauthorized', 401),
    ]);

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('Authentication failed');
});

it('fails extraction on validation error from koncile api', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response('Invalid file format', 422),
    ]);

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('Validation error');
});

it('fails extraction on server error from koncile api', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response('Internal Server Error', 500),
    ]);

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('server error');
});

it('fails extraction on network exception', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        },
    ]);

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toContain('Connection refused');
});

it('sends folder_id as query param when provided in metadata', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    $capturedUrl = null;

    Http::fake(function ($request) use (&$capturedUrl) {
        $capturedUrl = $request->url();

        return Http::response(['task_ids' => ['task-folder-123']], 200);
    });

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'metadata' => $this->metadata,
    ]);

    $this->integration->process($extraction);

    expect($capturedUrl)->toContain('folder_id=folder-car-456');
});

it('does not send folder_id query param when not in metadata', function () {
    Storage::put('documents/test.pdf', 'fake-pdf-contents');

    $capturedUrl = null;

    Http::fake(function ($request) use (&$capturedUrl) {
        $capturedUrl = $request->url();

        return Http::response(['task_ids' => ['task-no-folder']], 200);
    });

    $extraction = DocumentExtraction::factory()->create([
        'filename' => 'documents/test.pdf',
        'metadata' => ['template_id' => 'template-car-123'],
    ]);

    $this->integration->process($extraction);

    expect($capturedUrl)->not->toContain('folder_id');
});
