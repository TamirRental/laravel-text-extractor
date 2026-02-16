<?php

declare(strict_types=1);

namespace Tamir\DocumentExtraction\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Tamir\DocumentExtraction\DocumentExtractionServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DocumentExtractionServiceProvider::class,
        ];
    }

    /** @param  Application  $app */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('document-extraction.providers.koncile_ai.url', 'https://api.koncile.ai');
        $app['config']->set('document-extraction.providers.koncile_ai.key', 'test-api-key');
        $app['config']->set('document-extraction.providers.koncile_ai.templates', [
            'car_license' => 'template-123',
        ]);
        $app['config']->set('document-extraction.providers.koncile_ai.folders', [
            'car_license' => 'folder-456',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_document_extractions_table.php.stub';
        $migration->up();

        $this->beforeApplicationDestroyed(function () use ($migration): void {
            $migration->down();
        });
    }
}
