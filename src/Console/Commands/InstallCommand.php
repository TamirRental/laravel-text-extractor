<?php

namespace TamirRental\DocumentExtraction\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'document-extraction:install';

    protected $description = 'Install the Document Extraction package';

    public function handle(): int
    {
        $this->info('Installing Document Extraction package...');

        $configResult = $this->call('vendor:publish', [
            '--tag' => 'document-extraction-config',
        ]);

        $migrationsResult = $this->call('vendor:publish', [
            '--tag' => 'document-extraction-migrations',
        ]);

        if ($configResult !== Command::SUCCESS || $migrationsResult !== Command::SUCCESS) {
            $this->error('Document Extraction package installation failed.');

            return Command::FAILURE;
        }

        $this->info('Document Extraction package installed successfully.');

        return Command::SUCCESS;
    }
}
