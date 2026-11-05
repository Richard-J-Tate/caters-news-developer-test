<?php

namespace App\Http\Intergrations\Caters;

use App\Http\Intergrations\Caters\Requests\ListVideoRequest;
use Illuminate\Support\LazyCollection;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class CaterConnector extends Connector
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;

    public function __construct(
        protected string $token,
        protected string $baseUrl,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }

    /**
     * Lazily iterate every video across all pages of the listVideos endpoint.
     *
     * @return LazyCollection<int, array{id: int, title: string, description: ?string, url: string}>
     */
    public function videos(): LazyCollection
    {
        return LazyCollection::make(function () {
            $page = 1;

            do {
                $response = $this->send(new ListVideoRequest($page));
                $videos = $response->json('results', []);

                // Yield individually so keys stay sequential across pages;
                // `yield from` would reuse 0..n per page and collide on ->all().
                foreach ($videos as $video) {
                    yield $video;
                }

                $page = $this->nextPage($response);
            } while ($videos !== [] && $page !== null);
        });
    }

    /**
     * The API nests pagination metadata under "pagination", e.g.
     * {"pagination": {"current_page": 10, "next_page": null, "has_next_page": false}}
     */
    protected function nextPage(Response $response): ?int
    {
        if (! $response->json('pagination.has_next_page', false)) {
            return null;
        }

        $next = $response->json('pagination.next_page');

        return filled($next) ? (int) $next : null;
    }
}
