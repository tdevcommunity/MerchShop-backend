<?php

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Fixtures\FakeApiResource;
use Tests\TestCase;

class ApiResourceTest extends TestCase
{
    public function test_it_normalizes_the_final_response_payload_under_the_data_envelope(): void
    {
        $resource = new FakeApiResource(['api_version' => 'v1', 'checks' => ['database' => 'up']]);

        $response = $resource->response(Request::create('/api/v1/health'));

        $this->assertSame(
            ['data' => ['apiVersion' => 'v1', 'checks' => ['database' => 'up']]],
            $response->getData(true),
        );
    }

    public function test_it_normalizes_pagination_metadata_and_links(): void
    {
        $paginator = new LengthAwarePaginator(
            [['unit_price' => 1500], ['unit_price' => 2500]],
            total: 42,
            perPage: 15,
            currentPage: 1,
            options: ['path' => 'http://localhost/api/v1/products'],
        );

        $response = FakeApiResource::collection($paginator)->response(Request::create('/api/v1/products'));

        $payload = $response->getData(true);

        $this->assertSame(['data', 'links', 'meta'], array_keys($payload));
        $this->assertSame(['unitPrice' => 1500], $payload['data'][0]);
        $this->assertSame(['first', 'last', 'prev', 'next'], array_keys($payload['links']));
        $this->assertSame(1, $payload['meta']['currentPage']);
        $this->assertSame(3, $payload['meta']['lastPage']);
        $this->assertSame(15, $payload['meta']['perPage']);
        $this->assertSame(42, $payload['meta']['total']);
    }
}
