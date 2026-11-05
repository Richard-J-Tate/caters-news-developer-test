<?php

namespace App\Console\Commands;

use App\Actions\RecommendVideosToCustomers;
use App\Models\Customer;
use App\Models\Video;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('videos:recommend
    {--limit=3 : Maximum recommendations per customer}
    {--output=recommendations.json : Where to write the results, relative to the project root}')]
#[Description('Use an AI agent to recommend stored videos to each customer and dump the results to JSON')]
class RecommendVideos extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecommendVideosToCustomers $recommendVideosToCustomers): int
    {
        $videos = Video::orderBy('id')->get(['id', 'title', 'description', 'url'])->keyBy('id');
        $customers = Customer::orderBy('id')->get();

        if ($videos->isEmpty()) {
            $this->error('No videos found. Run `php artisan videos:import` first.');

            return self::FAILURE;
        }

        if ($customers->isEmpty()) {
            $this->error('No customers found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $results = $recommendVideosToCustomers->handle($videos, $customers, max(1, (int) $this->option('limit')));

        File::put(base_path($this->option('output')), json_encode([
            'generated_at' => now()->toIso8601String(),
            'video_count' => $videos->count(),
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $this->info("Wrote recommendations for {$customers->count()} customer(s) to {$this->option('output')}.");

        $failures = collect($results)->filter(fn (array $result) => isset($result['error']))->count();

        if ($failures > 0) {
            $this->error("{$failures} customer(s) failed; see the `error` field in the output.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
