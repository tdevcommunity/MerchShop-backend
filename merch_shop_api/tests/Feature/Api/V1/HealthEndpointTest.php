<?php

namespace Tests\Feature\Api\V1;

use App\Repositories\Contracts\HealthRepositoryInterface;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_it_reports_a_healthy_api(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'apiVersion', 'checks' => ['database']],
            ])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', 'up');
    }

    public function test_it_exposes_the_current_api_version(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.apiVersion', config('api.version'));
    }

    public function test_it_never_exposes_technical_details(): void
    {
        $payload = $this->getJson('/api/v1/health')->assertOk()->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('sqlite', $encoded);
    }

    public function test_it_answers_503_when_a_dependency_is_down(): void
    {
        $this->mock(HealthRepositoryInterface::class)
            ->shouldReceive('pingDatabase')
            ->andThrow(new \RuntimeException('connection refused: host=db.internal user=merch'));

        $response = $this->getJson('/api/v1/health');

        $response
            ->assertServiceUnavailable()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.database', 'down');
    }
}
