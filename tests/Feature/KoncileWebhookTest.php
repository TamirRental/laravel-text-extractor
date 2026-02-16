<?php

declare(strict_types=1);

use Tamir\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use Tamir\DocumentExtraction\Models\DocumentExtraction;

const WEBHOOK_TEST_SECRET = 'test-webhook-secret';

beforeEach(function () {
    config([
        'document-extraction.providers.koncile_ai.webhook_secret' => WEBHOOK_TEST_SECRET,
    ]);
});

/**
 * Generate valid HMAC signature headers for a given payload.
 *
 * @param  array<string, mixed>  $payload
 * @return array{X-Koncile-Signature: string, X-Koncile-Timestamp: string}
 */
function signedHeaders(array $payload, string $secret = WEBHOOK_TEST_SECRET): array
{
    $timestamp = (string) time();
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return [
        'X-Koncile-Signature' => $signature,
        'X-Koncile-Timestamp' => $timestamp,
    ];
}

it('processes a successful webhook with DONE status', function () {
    $extraction = DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-webhook-1',
    ]);

    $payload = [
        'task_id' => 'task-webhook-1',
        'status' => 'DONE',
        'General_fields' => [
            'license_number' => ['value' => '99-888-77', 'confidence_score' => 0.98],
        ],
        'Line_fields' => [],
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, signedHeaders($payload));

    $response->assertOk()
        ->assertJson(['message' => 'Webhook processed']);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Completed)
        ->identifier->toBe('99-888-77');
});

it('processes a FAILED webhook', function () {
    $extraction = DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-webhook-2',
    ]);

    $payload = [
        'task_id' => 'task-webhook-2',
        'status' => 'FAILED',
        'error_message' => 'OCR processing failed.',
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, signedHeaders($payload));

    $response->assertOk()
        ->assertJson(['message' => 'Webhook processed']);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Failed)
        ->error_message->toBe('OCR processing failed.');
});

it('returns 400 when task_id is missing', function () {
    $payload = [
        'status' => 'DONE',
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, signedHeaders($payload));

    $response->assertStatus(400)
        ->assertJson(['error' => 'Missing task_id']);
});

it('ignores unknown webhook statuses gracefully', function () {
    DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-webhook-3',
    ]);

    $payload = [
        'task_id' => 'task-webhook-3',
        'status' => 'IN PROGRESS',
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, signedHeaders($payload));

    $response->assertOk()
        ->assertJson(['message' => 'Webhook processed']);
});

it('rejects webhook with invalid signature', function () {
    config(['document-extraction.providers.koncile_ai.webhook_secret' => 'my-secret']);

    $payload = [
        'task_id' => 'task-webhook-4',
        'status' => 'DONE',
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, [
        'X-Koncile-Signature' => 'invalid-signature',
        'X-Koncile-Timestamp' => (string) time(),
    ]);

    $response->assertForbidden()
        ->assertJson(['error' => 'Invalid signature']);
});

it('rejects webhook with missing signature headers when secret is configured', function () {
    config(['document-extraction.providers.koncile_ai.webhook_secret' => 'my-secret']);

    $payload = [
        'task_id' => 'task-webhook-5',
        'status' => 'DONE',
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload);

    $response->assertForbidden();
});

it('accepts webhook with valid signature', function () {
    $secret = 'my-webhook-secret';
    config(['document-extraction.providers.koncile_ai.webhook_secret' => $secret]);

    $extraction = DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-webhook-6',
    ]);

    $payload = [
        'task_id' => 'task-webhook-6',
        'status' => 'DONE',
        'General_fields' => [
            'license_number' => ['value' => '11-222-33', 'confidence_score' => 0.99],
        ],
        'Line_fields' => [],
    ];

    $timestamp = (string) time();
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, [
        'X-Koncile-Signature' => $signature,
        'X-Koncile-Timestamp' => $timestamp,
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Webhook processed']);

    $extraction->refresh();
    expect($extraction)
        ->status->toBe(DocumentExtractionStatusEnum::Completed)
        ->identifier->toBe('11-222-33');
});

it('rejects webhook with expired timestamp', function () {
    $secret = 'my-webhook-secret';
    config(['document-extraction.providers.koncile_ai.webhook_secret' => $secret]);

    $payload = [
        'task_id' => 'task-webhook-7',
        'status' => 'DONE',
    ];

    $expiredTimestamp = (string) (time() - 600);
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', "{$expiredTimestamp}.{$body}", $secret);

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload, [
        'X-Koncile-Signature' => $signature,
        'X-Koncile-Timestamp' => $expiredTimestamp,
    ]);

    $response->assertForbidden();
});

it('rejects webhook when no secret is configured in production', function () {
    config(['document-extraction.providers.koncile_ai.webhook_secret' => null]);
    $this->app->detectEnvironment(fn (): string => 'production');

    $payload = [
        'task_id' => 'task-webhook-8',
        'status' => 'DONE',
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload);

    $response->assertForbidden();
});

it('allows webhook without secret in non-production environment', function () {
    config(['document-extraction.providers.koncile_ai.webhook_secret' => null]);

    DocumentExtraction::factory()->pending()->create([
        'external_task_id' => 'task-webhook-9',
    ]);

    $payload = [
        'task_id' => 'task-webhook-9',
        'status' => 'DONE',
        'General_fields' => [
            'license_number' => ['value' => '55-666-77', 'confidence_score' => 0.97],
        ],
        'Line_fields' => [],
    ];

    $response = $this->postJson('/webhooks/document-extraction/koncile', $payload);

    $response->assertOk()
        ->assertJson(['message' => 'Webhook processed']);
});
