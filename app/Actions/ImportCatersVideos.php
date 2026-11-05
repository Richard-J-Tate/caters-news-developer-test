<?php

namespace App\Actions;

use App\Http\Integrations\Caters\CatersConnector;
use App\Models\Video;
use Saloon\Exceptions\SaloonException;

class ImportCatersVideos
{
    /**
     * Whether an API token is configured for the Caters API.
     */
    public function isConfigured(): bool
    {
        return filled(config('app.testing_token'));
    }

    /**
     * Import every video from the Caters API, skipping any whose URL is already stored.
     *
     * Videos saved before a failure are kept, so the import is safe to re-run.
     *
     * @return array{created: int, skipped: int, error: string|null}
     */
    public function handle(): array
    {
        $created = 0;
        $skipped = 0;

        $connector = new CatersConnector(
            config('app.testing_token'),
            config('services.caters.url'),
        );

        try {
            foreach ($connector->videos() as $video) {
                $model = Video::firstOrCreate(
                    ['url' => $video['url']],
                    ['title' => $video['title'], 'description' => $video['description'] ?? null],
                );

                $model->wasRecentlyCreated ? $created++ : $skipped++;
            }
        } catch (SaloonException $exception) {
            return ['created' => $created, 'skipped' => $skipped, 'error' => $exception->getMessage()];
        }

        return ['created' => $created, 'skipped' => $skipped, 'error' => null];
    }
}
