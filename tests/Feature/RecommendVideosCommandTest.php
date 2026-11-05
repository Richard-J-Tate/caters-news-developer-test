<?php

use App\Ai\Agents\VideoRecommender;
use App\Models\Customer;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

const OUTPUT = 'storage/framework/testing/recommendations.json';

beforeEach(function () {
    File::ensureDirectoryExists(dirname(base_path(OUTPUT)));

    $this->football = Video::create(['title' => 'Cup Final Goal', 'description' => 'Late winner in a football cup final.', 'url' => 'https://example.test/football.mp4']);
    $this->tiger = Video::create(['title' => 'Tiger Stalks Prey', 'description' => 'A big cat hunts in the jungle.', 'url' => 'https://example.test/tiger.mp4']);
    $this->dolphins = Video::create(['title' => 'Dolphins Leap', 'description' => 'Dolphins leap beside a boat.', 'url' => 'https://example.test/dolphins.mp4']);

    $this->ben = Customer::create(['name' => 'Ben', 'email' => 'ben@example.test', 'description' => 'Loves wildlife, especially big cats and marine life.']);
    $this->chloe = Customer::create(['name' => 'Chloe', 'email' => 'chloe@example.test', 'description' => 'Enjoys football highlights.']);
});

afterEach(function () {
    File::delete(base_path(OUTPUT));
});

/**
 * @return array<string, mixed>
 */
function recommendationsOutput(): array
{
    return json_decode(File::get(base_path(OUTPUT)), true);
}

it('recommends videos to each customer and writes them to json', function () {
    VideoRecommender::fake(fn (string $prompt) => str_contains($prompt, 'big cats')
        ? ['recommendations' => [
            ['video_id' => $this->dolphins->id, 'relevance' => 7, 'reason' => 'Marine life.'],
            ['video_id' => $this->tiger->id, 'relevance' => 10, 'reason' => 'A big cat.'],
        ]]
        : ['recommendations' => [
            ['video_id' => $this->football->id, 'relevance' => 9, 'reason' => 'Football.'],
        ]]);

    $this->artisan('videos:recommend', ['--output' => OUTPUT])
        ->expectsOutputToContain('Wrote recommendations for 2 customer(s)')
        ->assertSuccessful();

    VideoRecommender::assertPromptedTimes(2);
    VideoRecommender::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Interests: Enjoys football highlights.'));

    $results = recommendationsOutput()['results'];

    expect($results)->toHaveCount(2)
        ->and($results[0]['customer']['name'])->toBe('Ben')
        ->and(array_column($results[0]['recommendations'], 'title'))->toBe(['Tiger Stalks Prey', 'Dolphins Leap'])
        ->and($results[0]['recommendations'][0])->toMatchArray([
            'video_id' => $this->tiger->id,
            'url' => 'https://example.test/tiger.mp4',
            'relevance' => 10,
            'reason' => 'A big cat.',
        ])
        ->and(array_column($results[1]['recommendations'], 'video_id'))->toBe([$this->football->id]);
});

it('drops invented and duplicate videos and respects the limit', function () {
    VideoRecommender::fake(fn () => ['recommendations' => [
        ['video_id' => 9999, 'relevance' => 10, 'reason' => 'Does not exist.'],
        ['video_id' => $this->tiger->id, 'relevance' => 9, 'reason' => 'First.'],
        ['video_id' => $this->tiger->id, 'relevance' => 8, 'reason' => 'Duplicate.'],
        ['video_id' => $this->dolphins->id, 'relevance' => 6, 'reason' => 'Second.'],
        ['video_id' => $this->football->id, 'relevance' => 2, 'reason' => 'Over the limit.'],
    ]]);

    $this->artisan('videos:recommend', ['--output' => OUTPUT, '--limit' => 2])->assertSuccessful();

    expect(array_column(recommendationsOutput()['results'][0]['recommendations'], 'reason'))
        ->toBe(['First.', 'Second.']);
});

it('records why each dropped video was discarded and logs invented ones', function () {
    Log::spy();

    VideoRecommender::fake(fn (string $prompt) => str_contains($prompt, 'big cats')
        ? ['recommendations' => [
            ['video_id' => 9999, 'relevance' => 10, 'reason' => 'Does not exist.'],
            ['video_id' => $this->tiger->id, 'relevance' => 9, 'reason' => 'First.'],
            ['video_id' => $this->tiger->id, 'relevance' => 8, 'reason' => 'Duplicate.'],
            ['video_id' => $this->dolphins->id, 'relevance' => 6, 'reason' => 'Second.'],
            ['video_id' => $this->football->id, 'relevance' => 2, 'reason' => 'Over the limit.'],
        ]]
        : ['recommendations' => []]);

    $this->artisan('videos:recommend', ['--output' => OUTPUT, '--limit' => 2])->assertSuccessful();

    [$ben, $chloe] = recommendationsOutput()['results'];

    expect($ben['discarded'])->toBe([
        ['video_id' => 9999, 'reason' => 'unknown_video'],
        ['video_id' => $this->tiger->id, 'reason' => 'duplicate'],
        ['video_id' => $this->football->id, 'reason' => 'over_limit'],
    ])
        ->and($chloe['recommendations'])->toBe([])
        ->and($chloe['discarded'])->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Video recommender returned an unknown video.', ['customer_id' => $this->ben->id, 'video_id' => 9999]);
});

it('gives the agent the full video catalogue', function () {
    $instructions = (string) (new VideoRecommender(Video::all()))->instructions();

    expect($instructions)
        ->toContain('"id": '.$this->tiger->id)
        ->toContain('Tiger Stalks Prey')
        ->toContain('Dolphins leap beside a boat.');
});

it('records a failed customer and carries on with the rest', function () {
    VideoRecommender::fake(fn (string $prompt) => str_contains($prompt, 'big cats')
        ? throw RateLimitedException::forProvider('gemini')
        : ['recommendations' => [['video_id' => $this->football->id, 'relevance' => 9, 'reason' => 'Football.']]]);

    $this->artisan('videos:recommend', ['--output' => OUTPUT])
        ->expectsOutputToContain('1 customer(s) failed')
        ->assertFailed();

    $results = recommendationsOutput()['results'];

    expect($results[0])->toHaveKey('error')
        ->and($results[0]['recommendations'])->toBe([])
        ->and($results[1]['recommendations'])->toHaveCount(1);
});

it('fails when there are no videos', function () {
    Video::query()->delete();
    VideoRecommender::fake();

    $this->artisan('videos:recommend', ['--output' => OUTPUT])
        ->expectsOutputToContain('No videos found')
        ->assertFailed();

    VideoRecommender::assertNeverPrompted();
});

it('fails when there are no customers', function () {
    Customer::query()->delete();
    VideoRecommender::fake();

    $this->artisan('videos:recommend', ['--output' => OUTPUT])
        ->expectsOutputToContain('No customers found')
        ->assertFailed();

    VideoRecommender::assertNeverPrompted();
});
