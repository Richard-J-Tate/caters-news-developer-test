<?php

namespace App\Http\Intergrations\Caters\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListVideoRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected int $page = 1) {}

    public function resolveEndpoint(): string
    {
        return '/test/listVideos';
    }

    /**
     * @return array {page: int}
     */
    protected function defaultQuery(): array
    {

        return ['page' => $this->page];
    }
}
