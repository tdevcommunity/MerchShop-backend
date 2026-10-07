<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_api_routes_answer_429_once_the_quota_is_exceeded(): void
    {
        config()->set('api.throttle.per_minute', 2);

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertOk();

        $this->getJson('/api/v1/health')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_the_quota_is_counted_per_client_ip(): void
    {
        config()->set('api.throttle.per_minute', 1);

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertStatus(429);

        // Un autre client repart avec son propre quota.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->getJson('/api/v1/health')
            ->assertOk();
    }
}
