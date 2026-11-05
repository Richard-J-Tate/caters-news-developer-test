<?php

namespace App\Actions;

use App\Ai\Agents\VideoRecommender;
use App\Models\Customer;
use App\Models\Video;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

class RecommendVideosToCustomers
{
    /**
     * Ask the agent to recommend videos for each customer.
     *
     * A failed call is recorded against that customer and the rest carry on.
     *
     * @param  Collection<int, Video>  $videos  Keyed by video id
     * @param  Collection<int, Customer>  $customers
     * @return list<array<string, mixed>>
     */
    public function handle(Collection $videos, Collection $customers, int $limit = 3): array
    {
        $agent = new VideoRecommender($videos->values(), $limit);

        return $customers->map(function (Customer $customer) use ($agent, $videos, $limit) {
            $result = [
                'customer' => $customer->only(['id', 'name', 'email', 'description']),
                'recommendations' => [],
                'discarded' => [],
            ];

            try {
                $response = $agent->prompt("Customer: {$customer->name}\nInterests: {$customer->description}");
            } catch (AiException|RequestException $exception) {
                return [...$result, 'error' => $exception->getMessage()];
            }

            [$result['recommendations'], $result['discarded']] = $this->partitionRecommendations($response['recommendations'] ?? [], $videos, $limit);

            $this->logInventedVideos($customer, $result['discarded']);

            return $result;
        })->values()->all();
    }

    /**
     * Split the model's picks into the ones we keep and the ones we drop.
     *
     * A pick is dropped when the model invented the video, repeated it, or went over the limit.
     * Kept picks get the stored video details attached.
     *
     * @param  array<int, array{video_id: int, relevance: int, reason: string}>  $recommendations
     * @param  Collection<int, Video>  $videos
     * @return array{
     *     0: list<array{video_id: int, title: string, url: string, relevance: int, reason: string}>,
     *     1: list<array{video_id: mixed, reason: 'unknown_video'|'duplicate'|'over_limit'}>
     * }
     */
    protected function partitionRecommendations(array $recommendations, Collection $videos, int $limit): array
    {
        $kept = [];
        $discarded = [];

        foreach (collect($recommendations)->sortByDesc('relevance') as $recommendation) {
            $videoId = $recommendation['video_id'] ?? null;

            $reason = match (true) {
                ! $videos->has($videoId) => 'unknown_video',
                isset($kept[$videoId]) => 'duplicate',
                count($kept) >= $limit => 'over_limit',
                default => null,
            };

            if ($reason !== null) {
                $discarded[] = ['video_id' => $videoId, 'reason' => $reason];

                continue;
            }

            $kept[$videoId] = [
                'video_id' => $videoId,
                'title' => $videos[$videoId]->title,
                'url' => $videos[$videoId]->url,
                'relevance' => $recommendation['relevance'],
                'reason' => $recommendation['reason'],
            ];
        }

        return [array_values($kept), $discarded];
    }

    /**
     * Log each video the model made up, so it can be tracked across runs.
     *
     * @param  list<array{video_id: mixed, reason: string}>  $discarded
     */
    protected function logInventedVideos(Customer $customer, array $discarded): void
    {
        foreach ($discarded as $pick) {
            if ($pick['reason'] === 'unknown_video') {
                Log::warning('Video recommender returned an unknown video.', [
                    'customer_id' => $customer->id,
                    'video_id' => $pick['video_id'],
                ]);
            }
        }
    }
}
