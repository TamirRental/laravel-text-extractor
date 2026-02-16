<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tamir\DocumentExtraction\Providers\KoncileAi\KoncileAiWebhookController;

Route::middleware('api')
    ->post('/webhooks/document-extraction/koncile', [KoncileAiWebhookController::class, 'handle'])
    ->name('document-extraction.webhooks.koncile');
