# Laravel Text Extractor

A Laravel package for extracting structured data from documents (images, PDFs) via OCR APIs. Ships with a [Koncile AI](https://koncile.ai) provider out of the box.

## Features

- Extract structured data from documents using OCR providers
- Async processing via Laravel queues
- Webhook support for provider callbacks
- Config-based document types — no code changes needed to add new types
- Pluggable provider architecture — bring your own OCR provider
- Facade for clean, expressive syntax
- Built-in model scopes for querying extractions

## Requirements

- PHP 8.4+
- Laravel 11 or 12

## Installation

```bash
composer require tamir/laravel-text-extractor
```

Run the install command to publish the config files and migration:

```bash
php artisan document-extraction:install
```

Then run the migration:

```bash
php artisan migrate
```

## Configuration

The install command publishes two config files:

### `config/document-extraction.php`

Provider connection settings. Safe to republish on package updates.

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

### `config/document-extraction-types.php`

Define your document types here. Each key is a document type string used throughout the package.

```php
return [
    'car_license' => [
        'template_id' => env('KONCILE_AI_CAR_LICENSE_TEMPLATE_ID'),
        'folder_id' => env('KONCILE_AI_CAR_LICENSE_FOLDER_ID'),
        'identifier' => 'license_number',
    ],

    'invoice' => [
        'template_id' => env('KONCILE_AI_INVOICE_TEMPLATE_ID'),
        'folder_id' => env('KONCILE_AI_INVOICE_FOLDER_ID'),
        'identifier' => 'invoice_number',
    ],
];
```

| Key | Description |
|-----|-------------|
| `template_id` | The OCR template ID on the provider side |
| `folder_id` | Optional folder/organization ID on the provider side |
| `identifier` | The field name from extracted data to use as a unique identifier (e.g. license number) |

### Environment Variables

Add these to your `.env` file:

```env
KONCILE_AI_API_KEY=your-api-key
KONCILE_AI_WEBHOOK_SECRET=your-webhook-secret

# Per document type
KONCILE_AI_CAR_LICENSE_TEMPLATE_ID=12345
KONCILE_AI_CAR_LICENSE_FOLDER_ID=67890
```

## Usage

### Basic Usage with Facade

```php
use Tamir\DocumentExtraction\Facades\DocumentExtraction;

// Store the uploaded file
$path = $file->store('documents/car-licenses', 's3');

// Extract — creates a record and dispatches async processing automatically
$extraction = DocumentExtraction::extractOrRetrieve('car_license', $path);
```

That's it. The package automatically dispatches a queued job to download the file from storage, upload it to the OCR provider, and track the result.

### Force Re-extraction

If an extraction already exists for a file, pass `force: true` to create a new one:

```php
$extraction = DocumentExtraction::extractOrRetrieve('car_license', $path, force: true);
```

### Checking Extraction Status

```php
use Tamir\DocumentExtraction\Models\DocumentExtraction;

$extraction = DocumentExtraction::find($id);

if ($extraction->status === DocumentExtractionStatusEnum::Completed) {
    $data = $extraction->extracted_data;
    $identifier = $extraction->identifier; // e.g. "12-345-67"
}
```

### Querying Extractions

The `DocumentExtraction` model includes useful scopes:

```php
use Tamir\DocumentExtraction\Models\DocumentExtraction;

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
   ├─ extractOrRetrieve() ───────►│                              │
   │  (auto-dispatches event)     ├─ Download from S3            │
   │                              ├─ Upload to provider ────────►│
   │                              ├─ Save external_task_id       │
   │                              │                              ├─ OCR Processing...
   │                              │                              │
   │                              │         Webhook callback ◄───┤
   │                              │         completeExtraction()  │
   │                              │         or failExtraction()   │
   │                              │                              │
   ├─ Check status / display      │                              │
```

### Extraction Lifecycle

| Stage | Status | external_task_id | extracted_data |
|-------|--------|------------------|----------------|
| Record created | `pending` | `null` | `{}` |
| Sent to provider | `pending` | `task-abc-123` | `{}` |
| Provider succeeds | `completed` | `task-abc-123` | `{general_fields: {...}}` |
| Provider fails | `failed` | `task-abc-123` | `{}` |

## Webhook Setup

The package registers a webhook route automatically:

```
POST /webhooks/document-extraction/koncile
```

Configure this URL in your Koncile AI dashboard as the webhook callback URL.

In production, the webhook verifies the request signature using `KONCILE_AI_WEBHOOK_SECRET`. In non-production environments, signature verification is skipped if no secret is configured.

## Adding Document Types

To add a new document type, simply add an entry to `config/document-extraction-types.php`:

```php
'passport' => [
    'template_id' => env('KONCILE_AI_PASSPORT_TEMPLATE_ID'),
    'folder_id' => env('KONCILE_AI_PASSPORT_FOLDER_ID'),
    'identifier' => 'passport_number',
],
```

Then use it in your code:

```php
$extraction = DocumentExtraction::extractOrRetrieve('passport', $path);
```

No code changes required — everything is config-driven.

## Custom Providers

You can create your own extraction provider by implementing the `DocumentExtractionProvider` contract:

```php
<?php

namespace App\Services;

use Tamir\DocumentExtraction\Contracts\DocumentExtractionProvider;

class MyCustomProvider implements DocumentExtractionProvider
{
    /**
     * @return array{status: string, data?: array<string, mixed>, message: string}
     */
    public function extract(string $filePath, string $documentType): array
    {
        // Your extraction logic here...

        return [
            'status' => 'pending',
            'data' => ['task_ids' => ['your-task-id']],
            'message' => 'File uploaded successfully.',
        ];
    }
}
```

Then register it in the service provider by extending the package's binding:

```php
// AppServiceProvider.php
use Tamir\DocumentExtraction\Contracts\DocumentExtractionProvider;

public function register(): void
{
    $this->app->bind(DocumentExtractionProvider::class, MyCustomProvider::class);
}
```

## Events

| Event | Dispatched When |
|-------|----------------|
| `DocumentExtractionRequested` | Automatically dispatched when `extractOrRetrieve()` creates a new extraction |

The event is dispatched internally — you don't need to dispatch it yourself. The queued listener downloads the file from storage and uploads it to the provider.

Listen for extraction completion in your app by creating your own listener that watches for model updates, or by extending the webhook controller.

## Testing

The package ships with model factories for testing:

```php
use Tamir\DocumentExtraction\Models\DocumentExtraction;

// Default (pending, no task ID)
$extraction = DocumentExtraction::factory()->create();

// With external task ID
$extraction = DocumentExtraction::factory()->pending()->create();

// Completed with data
$extraction = DocumentExtraction::factory()->completed()->create();

// Failed with error
$extraction = DocumentExtraction::factory()->failed()->create();
```

## License

MIT
