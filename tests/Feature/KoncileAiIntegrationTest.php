<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use TamirRental\DocumentExtraction\Providers\KoncileAi\KoncileAiIntegration;

beforeEach(function () {
    config([
        'document-extraction.providers.koncile_ai' => [
            'url' => 'https://api.koncile.ai',
            'key' => 'test-api-key',
            'webhook_secret' => 'test-secret',
        ],
    ]);

    $this->integration = new KoncileAiIntegration;

    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($this->tempFile, 'fake-pdf-contents');

    $this->metadata = [
        'template_id' => 'template-car-123',
        'folder_id' => 'folder-car-456',
        'identifier_field' => 'license_number',
    ];
});

afterEach(function () {
    if (isset($this->tempFile) && file_exists($this->tempFile)) {
        unlink($this->tempFile);
    }
});

it('uploads file successfully and returns pending status', function () {
    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response([
            'task_ids' => ['task-abc-123'],
        ], 200),
    ]);

    $result = $this->integration->extract($this->tempFile, 'car_license', $this->metadata);

    expect($result)
        ->toHaveKey('status', 'pending')
        ->toHaveKey('message', 'File uploaded successfully.')
        ->toHaveKey('external_id', 'task-abc-123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'v1/upload_file')
            && str_contains($request->url(), 'template_id=template-car-123')
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

it('returns failed when file is not accessible', function () {
    $result = $this->integration->extract('/tmp/nonexistent-file.pdf', 'car_license', $this->metadata);

    expect($result)
        ->toHaveKey('status', 'failed')
        ->toHaveKey('message');

    expect($result['message'])->toContain('File not accessible');
});

it('returns failed when no template_id in metadata', function () {
    $result = $this->integration->extract($this->tempFile, 'car_license', []);

    expect($result)
        ->toHaveKey('status', 'failed')
        ->toHaveKey('message');

    expect($result['message'])->toContain('No template_id provided in metadata');
});

it('handles authentication error from koncile api', function () {
    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response('Unauthorized', 401),
    ]);

    $result = $this->integration->extract($this->tempFile, 'car_license', $this->metadata);

    expect($result)
        ->toHaveKey('status', 'failed')
        ->toHaveKey('message');

    expect($result['message'])->toContain('Authentication failed');
});

it('handles validation error from koncile api', function () {
    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response('Invalid file format', 422),
    ]);

    $result = $this->integration->extract($this->tempFile, 'car_license', $this->metadata);

    expect($result)
        ->toHaveKey('status', 'failed');

    expect($result['message'])->toContain('Validation error');
});

it('handles server error from koncile api', function () {
    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => Http::response('Internal Server Error', 500),
    ]);

    $result = $this->integration->extract($this->tempFile, 'car_license', $this->metadata);

    expect($result)
        ->toHaveKey('status', 'failed');

    expect($result['message'])->toContain('server error');
});

it('handles network exception', function () {
    Http::fake([
        'api.koncile.ai/v1/upload_file/*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        },
    ]);

    $result = $this->integration->extract($this->tempFile, 'car_license', $this->metadata);

    expect($result)
        ->toHaveKey('status', 'failed');

    expect($result['message'])->toContain('Network error');
});

it('sends folder_id as query param when provided in metadata', function () {
    $capturedUrl = null;

    Http::fake(function ($request) use (&$capturedUrl) {
        $capturedUrl = $request->url();

        return Http::response(['task_ids' => ['task-folder-123']], 200);
    });

    $result = $this->integration->extract($this->tempFile, 'car_license', $this->metadata);

    expect($result)->toHaveKey('status', 'pending');
    expect($capturedUrl)->toContain('folder_id=folder-car-456');
});

it('does not send folder_id query param when not in metadata', function () {
    $capturedUrl = null;

    Http::fake(function ($request) use (&$capturedUrl) {
        $capturedUrl = $request->url();

        return Http::response(['task_ids' => ['task-no-folder']], 200);
    });

    $metadata = [
        'template_id' => 'template-car-123',
    ];

    $result = $this->integration->extract($this->tempFile, 'car_license', $metadata);

    expect($result)->toHaveKey('status', 'pending');
    expect($capturedUrl)->not->toContain('folder_id');
});
