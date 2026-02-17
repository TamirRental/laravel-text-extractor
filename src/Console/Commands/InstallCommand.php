<?php

namespace TamirRental\DocumentExtraction\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'document-extraction:install';

    protected $description = 'Install the Document Extraction package';

    public function handle(): void
    {
        $this->info('Installing Document Extraction package...');

        $this->call('vendor:publish', [
            '--tag' => 'document-extraction-config',
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'document-extraction-migrations',
        ]);

        $this->info('Document Extraction package installed successfully.');
    }
}
