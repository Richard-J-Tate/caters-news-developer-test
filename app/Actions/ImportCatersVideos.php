<?php

namespace App\Actions;

use App\Http\Intergrations\Caters\CaterConnector;
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
     * Import every video from the Caters API, skipping any whose URL already exists.
     *
     * @return array{created: int, skipped: int, error: ?string}
     */
    public function handle(): array
    {
        $created = 0;
        $skipped = 0;

        $connector = new CaterConnector(
            config('app.testing_token'),
            config('app.caters_endpoint'),
        );

        try {
            foreach ($connector->videos() as $video) {
                $model = Video::firstOrCreate(
                    ['url' => $video['url']],
                    [
                        'title' => $video['title'],
                        'description' => $video['description'] ?? null,
                    ],
                );

                $model->wasRecentlyCreated ? $created++ : $skipped++;
            }
        } catch (SaloonException $e) {
            return ['created' => $created, 'skipped' => $skipped, 'error' => $e->getMessage()];
        }

        return ['created' => $created, 'skipped' => $skipped, 'error' => null];
    }
}
