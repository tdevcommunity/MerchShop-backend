<?php

namespace Tests\Unit\Support;

use App\Support\Payments\VeltoEnvironment;
use Tests\TestCase;

class VeltoEnvironmentTest extends TestCase
{
    public function test_live_secret_key_is_never_treated_as_sandbox(): void
    {
        config()->set('services.velto.secret_key', 'sk_live_prod_abc');

        $this->assertFalse(VeltoEnvironment::isSandbox());
    }

    public function test_sandbox_secret_key_is_sandbox(): void
    {
        config()->set('services.velto.secret_key', 'sk_sandbox_dev');

        $this->assertTrue(VeltoEnvironment::isSandbox());
    }
}
