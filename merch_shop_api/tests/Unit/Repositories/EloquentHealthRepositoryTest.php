<?php

namespace Tests\Unit\Repositories;

use App\Repositories\Contracts\HealthRepositoryInterface;
use App\Repositories\Eloquent\EloquentHealthRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\TestCase;

class EloquentHealthRepositoryTest extends TestCase
{
    public function test_the_contract_is_bound_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentHealthRepository::class,
            $this->app->make(HealthRepositoryInterface::class),
        );
    }

    public function test_pinging_the_database_succeeds_on_a_reachable_connection(): void
    {
        $this->app->make(HealthRepositoryInterface::class)->pingDatabase();

        $this->addToAssertionCount(1);
    }

    public function test_pinging_surfaces_the_underlying_failure_instead_of_swallowing_it(): void
    {
        $repository = new EloquentHealthRepository(new class implements ConnectionResolverInterface
        {
            public function connection($name = null): Connection
            {
                throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }

            public function getDefaultConnection(): string
            {
                return 'mysql';
            }

            public function setDefaultConnection($name): void {}
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $repository->pingDatabase();
    }
}
