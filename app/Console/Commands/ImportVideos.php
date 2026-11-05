<?php

namespace App\Console\Commands;

use App\Actions\ImportCatersVideos;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('videos:import')]
#[Description('Import videos from the Caters API')]
class ImportVideos extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImportCatersVideos $importCatersVideos): int
    {
        if (! $importCatersVideos->isConfigured()) {
            $this->error('You need to configure the Caters connector first (TESTING_TOKEN).');

            return self::FAILURE;
        }

        ['created' => $created, 'skipped' => $skipped, 'error' => $error] = $importCatersVideos->handle();

        $this->info("Imported {$created} new video(s), skipped {$skipped} existing.");

        if ($error !== null) {
            $this->error("Import stopped early: {$error}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
