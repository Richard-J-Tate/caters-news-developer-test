<?php

namespace App\Console\Commands;

use App\Actions\ImportCatersVideos;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('videos:import')]
#[Description('Import all videos from the Caters listVideos API, skipping any that already exist')]
class ImportVideos extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImportCatersVideos $importCatersVideos): int
    {
        if (! $importCatersVideos->isConfigured()) {
            $this->error('No API token configured. Set TESTING_TOKEN in your .env file.');

            return self::FAILURE;
        }

        $result = $importCatersVideos->handle();

        if ($result['error'] !== null) {
            $this->error("Import failed: {$result['error']}");
            $this->line("Imported {$result['created']} video(s) before the failure.");

            return self::FAILURE;
        }

        $this->info("Imported {$result['created']} new video(s), skipped {$result['skipped']} existing.");

        return self::SUCCESS;
    }
}
