<?php

namespace Tests\Unit\Services;

use App\Repositories\Contracts\HealthRepositoryInterface;
use App\Services\SystemHealthService;
use RuntimeException;
use Tests\TestCase;

class SystemHealthServiceTest extends TestCase
{
    public function test_it_reports_ok_when_every_dependency_answers(): void
    {
        $report = $this->service(new class implements HealthRepositoryInterface
        {
            public function pingDatabase(): void {}
        })->readiness();

        $this->assertSame(['status' => 'ok', 'checks' => ['database' => 'up']], $report);
    }

    public function test_it_reports_degraded_when_a_dependency_fails(): void
    {
        $report = $this->service(new class implements HealthRepositoryInterface
        {
            public function pingDatabase(): void
            {
                throw new RuntimeException('connection refused');
            }
        })->readiness();

        $this->assertSame(['status' => 'degraded', 'checks' => ['database' => 'down']], $report);
    }

    public function test_it_queries_each_dependency_only_once_per_call(): void
    {
        $repository = new class implements HealthRepositoryInterface
        {
            public int $calls = 0;

            public function pingDatabase(): void
            {
                $this->calls++;
            }
        };

        $this->service($repository)->readiness();

        $this->assertSame(1, $repository->calls);
    }

    private function service(HealthRepositoryInterface $repository): SystemHealthService
    {
        return new SystemHealthService($repository);
    }
}
