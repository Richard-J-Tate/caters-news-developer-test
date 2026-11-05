<?php

use App\Http\Integrations\Caters\Requests\ListVideosRequest;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::preventStrayRequests();
    config(['app.testing_token' => 'test-token']);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

/**
 * Build one page in the shape the live listVideos API returns.
 *
 * @param  list<array<string, mixed>>  $results
 * @return array<string, mixed>
 */
function catersPage(array $results, int $page, ?int $nextPage): array
{
    return [
        'results' => $results,
        'total_results' => 3,
        'pagination' => [
            'current_page' => $page,
            'next_page' => $nextPage,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'has_next_page' => $nextPage !== null,
            'has_previous_page' => $page > 1,
        ],
        'success' => true,
    ];
}

function fakeTwoPagesOfVideos(): MockClient
{
    return MockClient::global([
        ListVideosRequest::class => fn (PendingRequest $request) => MockResponse::make(match ((int) $request->query()->get('page')) {
            1 => catersPage([
                ['id' => 1, 'title' => 'Video One', 'description' => 'First', 'url' => 'https://example.test/1.mp4'],
                ['id' => 2, 'title' => 'Video Two', 'description' => 'Second', 'url' => 'https://example.test/2.mp4'],
            ], page: 1, nextPage: 2),
            2 => catersPage([
                ['id' => 3, 'title' => 'Video Three', 'description' => 'Third', 'url' => 'https://example.test/3.mp4'],
            ], page: 2, nextPage: null),
        }),
    ]);
}

it('authenticates and imports videos from every page', function () {
    $mockClient = fakeTwoPagesOfVideos();

    $this->artisan('videos:import')
        ->expectsOutputToContain('Imported 3 new video(s), skipped 0 existing.')
        ->assertSuccessful();

    expect(Video::orderBy('id')->pluck('title')->all())->toBe(['Video One', 'Video Two', 'Video Three']);

    $mockClient->assertSentCount(2);
    $mockClient->assertSent(fn (ListVideosRequest $request, $response) => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer test-token');
});

it('stops on an empty page even if the API claims there is another', function () {
    $mockClient = MockClient::global([
        ListVideosRequest::class => MockResponse::make(catersPage([], page: 1, nextPage: 2)),
    ]);

    $this->artisan('videos:import')->assertSuccessful();

    $mockClient->assertSentCount(1);
    expect(Video::count())->toBe(0);
});

it('does not duplicate videos on subsequent runs', function () {
    fakeTwoPagesOfVideos();

    $this->artisan('videos:import')->assertSuccessful();
    $this->artisan('videos:import')
        ->expectsOutputToContain('Imported 0 new video(s), skipped 3 existing.')
        ->assertSuccessful();

    expect(Video::count())->toBe(3);
});

it('does not overwrite existing videos', function () {
    $existing = Video::create([
        'title' => 'Edited locally',
        'description' => 'Keep me',
        'url' => 'https://example.test/1.mp4',
    ]);

    fakeTwoPagesOfVideos();

    $this->artisan('videos:import')->assertSuccessful();

    expect($existing->fresh())
        ->title->toBe('Edited locally')
        ->description->toBe('Keep me');
});

it('fails when no token is configured', function () {
    config(['app.testing_token' => null]);

    $this->artisan('videos:import')
        ->expectsOutputToContain('No API token configured')
        ->assertFailed();
});

it('fails gracefully when the API rejects the request', function () {
    MockClient::global([
        ListVideosRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401),
    ]);

    $this->artisan('videos:import')
        ->expectsOutputToContain('Import failed')
        ->assertFailed();

    expect(Video::count())->toBe(0);
});
