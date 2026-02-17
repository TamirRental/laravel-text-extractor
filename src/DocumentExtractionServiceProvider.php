<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TamirRental\DocumentExtraction\Console\Commands\InstallCommand;
use TamirRental\DocumentExtraction\Contracts\DocumentExtractionProvider;
use TamirRental\DocumentExtraction\Events\DocumentExtractionRequested;
use TamirRental\DocumentExtraction\Listeners\ProcessDocumentExtraction;
use TamirRental\DocumentExtraction\Providers\KoncileAi\KoncileAiIntegration;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

class DocumentExtractionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('document-extraction')
            ->hasConfigFile(['document-extraction', 'document-extraction-types'])
            ->hasMigration('create_document_extractions_table')
            ->hasTranslations()
            ->hasRoute('api')
            ->hasCommand(InstallCommand::class);
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
