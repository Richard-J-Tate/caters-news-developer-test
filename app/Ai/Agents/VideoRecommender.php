<?php

namespace App\Ai\Agents;

use App\Models\Video;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Gemini)]
#[Temperature(0.2)]
class VideoRecommender implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  Collection<int, Video>  $videos
     */
    public function __construct(
        public Collection $videos,
        public int $maxRecommendations = 3,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $catalogue = $this->videos
            ->map(fn (Video $video) => [
                'id' => $video->id,
                'title' => $video->title,
                'description' => $video->description,
            ])
            ->values()
            ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<INSTRUCTIONS
        You are a video curator for a news and video licensing agency. You recommend videos
        from the catalogue below to a single customer, based on the interests they describe.

        Rules:
        - Only recommend videos that appear in the catalogue, using their exact `id`.
        - Recommend at most {$this->maxRecommendations} videos, best match first.
        - Only include a video if it genuinely matches the customer's interests. If nothing
          fits, return an empty list rather than padding it with weak matches.
        - `relevance` is 1 to 10, where 10 is exactly what the customer asked for.
        - `reason` is one short sentence linking the video to the customer's interests.

        Catalogue (JSON):
        {$catalogue}
        INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'recommendations' => $schema->array()
                ->items(
                    $schema->object(fn (JsonSchema $schema) => [
                        'video_id' => $schema->integer()->required(),
                        'relevance' => $schema->integer()->min(1)->max(10)->required(),
                        'reason' => $schema->string()->required(),
                    ])
                )
                ->required(),
        ];
    }
}
