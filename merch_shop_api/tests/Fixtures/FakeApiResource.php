<?php

namespace Tests\Fixtures;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Minimal resource used to exercise the base class contract in isolation.
 */
final class FakeApiResource extends ApiResource
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload)
    {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->payload;
    }
}
