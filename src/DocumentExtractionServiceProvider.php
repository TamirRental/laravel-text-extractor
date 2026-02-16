<?php

declare(strict_types=1);

namespace Tamir\DocumentExtraction;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tamir\DocumentExtraction\Contracts\DocumentExtractionProvider;
use Tamir\DocumentExtraction\Events\DocumentExtractionRequested;
use Tamir\DocumentExtraction\Listeners\ProcessDocumentExtraction;
use Tamir\DocumentExtraction\Providers\KoncileAi\KoncileAiIntegration;
use Tamir\DocumentExtraction\Services\DocumentExtractionService;

class DocumentExtractionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('document-extraction')
            ->hasConfigFile()
            ->hasMigration('create_document_extractions_table')
            ->hasTranslations()
            ->hasRoute('api');
    }

    public function packageRegistered(): void
    {
        $this->app->bind(DocumentExtractionProvider::class, function (Application $app): DocumentExtractionProvider {
            /** @var string $default */
            $default = config('document-extraction.default', 'koncile_ai');

            return match ($default) {
                'koncile_ai' => $app->make(KoncileAiIntegration::class),
                default => throw new InvalidArgumentException("Unsupported extraction provider: {$default}"),
            };
        });

        $this->app->singleton(DocumentExtractionService::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(Dispatcher::class)->listen(DocumentExtractionRequested::class, ProcessDocumentExtraction::class);
    }
}
