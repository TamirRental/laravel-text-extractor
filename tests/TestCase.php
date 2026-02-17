<?php

namespace TamirRental\DocumentExtraction\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use TamirRental\DocumentExtraction\DocumentExtractionServiceProvider;

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
