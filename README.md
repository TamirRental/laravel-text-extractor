# Laravel Text Extractor

A Laravel package for extracting structured data from documents (images, PDFs) via OCR APIs. Ships with a [Koncile AI](https://koncile.ai) provider out of the box.

## Features

- Extract structured data from documents using OCR providers
- Fluent API — chainable `metadata()`, `force()`, and `submit()` methods
- Async processing via Laravel queues
- Webhook support for provider callbacks
- Pluggable provider architecture — bring your own OCR provider
- Facade for clean, expressive syntax
- Built-in model scopes for querying extractions

## Requirements

- PHP 8.4+
- Laravel 11 or 12

## Installation

```bash
composer require tamirrental/laravel-text-extractor
```

Run the install command to publish the config file and migration:

```bash
php artisan document-extraction:install
```

Then run the migration:

```bash
php artisan migrate
```

## Configuration

### `config/document-extraction.php`

Provider connection settings.

```php
return [
    'default' => env('EXTRACTION_PROVIDER', 'koncile_ai'),

    'providers' => [
        'koncile_ai' => [
            'url' => env('KONCILE_AI_API_URL', 'https://api.koncile.ai'),
            'key' => env('KONCILE_AI_API_KEY'),
            'webhook_secret' => env('KONCILE_AI_WEBHOOK_SECRET'),
        ],
    ],
];
```

### Environment Variables

Add these to your `.env` file:

```env
KONCILE_AI_API_KEY=your-api-key
KONCILE_AI_WEBHOOK_SECRET=your-webhook-secret
```

## Usage

### Basic Usage with Facade

```php
use TamirRental\DocumentExtraction\Facades\DocumentExtraction;

// Store the uploaded file
$path = $file->store('documents/car-licenses', 's3');

// Extract — creates a record and dispatches async processing
$extraction = DocumentExtraction::extract('car_license', $path)
    ->metadata([
        'template_id' => 'your-koncile-template-id',
        'folder_id' => 'optional-folder-id',         // optional
        'identifier_field' => 'license_number',       // optional — used to resolve identifier from extracted data
    ])
    ->submit();
```

The package automatically dispatches a queued job to download the file from storage, upload it to the OCR provider, and track the result.

### Metadata

The `metadata()` method accepts a key-value array that gets stored on the extraction record and passed to the provider. This is how you supply provider-specific data without any config files.

| Key | Required | Description |
|-----|----------|-------------|
| `template_id` | Yes (Koncile AI) | The OCR template ID on the provider side |
| `folder_id` | No | Optional folder/organization ID on the provider side |
| `identifier_field` | No | The field name from extracted data to use as a unique identifier (e.g. `license_number`) |

### Force Re-extraction

If an extraction already exists for a file, chain `force()` to create a new one:

```php
$extraction = DocumentExtraction::extract('car_license', $path)
    ->metadata(['template_id' => 'your-template-id'])
    ->force()
    ->submit();
```

### Conditional Force

Using the `Conditionable` trait, you can conditionally chain methods:

```php
$extraction = DocumentExtraction::extract('car_license', $path)
    ->metadata(['template_id' => 'your-template-id'])
    ->when($shouldForce, fn ($pending) => $pending->force())
    ->submit();
```

### Checking Extraction Status

```php
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;

$extraction = DocumentExtraction::find($id);

if ($extraction->status === DocumentExtractionStatusEnum::Completed) {
    $data = $extraction->extracted_data;
    $identifier = $extraction->identifier; // e.g. "12-345-67"
}
```

### Querying Extractions

The `DocumentExtraction` model includes useful scopes:

```php
use TamirRental\DocumentExtraction\Models\DocumentExtraction;

// Filter by status
DocumentExtraction::pending()->get();
DocumentExtraction::completed()->get();
DocumentExtraction::failed()->get();

// Filter by type or file
DocumentExtraction::forType('car_license')->get();
DocumentExtraction::forFile('documents/license.png')->get();

// Combine scopes
DocumentExtraction::forType('car_license')->completed()->latest()->first();
```

## How It Works

```
1. Your App                    2. Queue Worker               3. Provider (Koncile AI)
   │                              │                              │
   ├─ Store file to S3            │                              │
   ├─ extract()->submit() ───────►│                              │
   │  (auto-dispatches event)     ├─ Download from S3            │
   │                              ├─ Upload to provider ────────►│
   │                              ├─ Save external_task_id       │
   │                              │                              ├─ OCR Processing...
   │                              │                              │
   │                              │         Webhook callback ◄───┤
   │                              │         complete() / fail()   │
   │                              │                              │
   ├─ Check status / display      │                              │
```

### Extraction Lifecycle

| Stage | Status | external_task_id | extracted_data |
|-------|--------|------------------|----------------|
| Record created | `pending` | `null` | `{}` |
| Sent to provider | `pending` | `task-abc-123` | `{}` |
| Provider succeeds | `completed` | `task-abc-123` | `{...provider data}` |
| Provider fails | `failed` | `task-abc-123` | `{}` |

## Webhook Setup

The package registers a webhook route automatically:

```
POST /webhooks/document-extraction/koncile
```

Configure this URL in your Koncile AI dashboard as the webhook callback URL.

In production, the webhook verifies the request signature using `KONCILE_AI_WEBHOOK_SECRET`. In non-production environments, signature verification is skipped if no secret is configured.

## Custom Providers

You can create your own extraction provider by implementing the `DocumentExtractionProvider` contract:

```php
<?php

namespace App\Services;

use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;

class MyCustomProvider implements DocumentExtractionProvider
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, external_id?: string, message: string}
     */
    public function extract(string $filePath, string $documentType, array $metadata = []): array
    {
        // Your extraction logic here...

        return [
            'status' => 'pending',
            'external_id' => 'your-task-id',
            'message' => 'File uploaded successfully.',
        ];
    }
}
```

Then register it in the service provider by extending the package's binding:

```php
// AppServiceProvider.php
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;

public function register(): void
{
    $this->app->bind(DocumentExtractionProvider::class, MyCustomProvider::class);
}
```

## Events

| Event | Dispatched When |
|-------|----------------|
| `DocumentExtractionRequested` | Automatically dispatched when `extract()->submit()` creates a new extraction |

The event is dispatched internally — you don't need to dispatch it yourself. The queued listener downloads the file from storage and uploads it to the provider.

Listen for extraction completion in your app by creating your own listener that watches for model updates, or by extending the webhook controller.

## Testing

The package ships with model factories for testing:

```php
use TamirRental\DocumentExtraction\Models\DocumentExtraction;

// Default (pending, no task ID)
$extraction = DocumentExtraction::factory()->create();

// With external task ID
$extraction = DocumentExtraction::factory()->pending()->create();

// Completed with data
$extraction = DocumentExtraction::factory()->completed()->create();

// Failed with error
$extraction = DocumentExtraction::factory()->failed()->create();

// With metadata
$extraction = DocumentExtraction::factory()->create([
    'metadata' => [
        'template_id' => 'your-template-id',
        'identifier_field' => 'license_number',
    ],
]);
```

## License

MIT
