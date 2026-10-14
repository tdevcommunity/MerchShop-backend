<?php

namespace Tests\Unit\Support;

use App\Models\Order;
use App\Support\Payments\VeltoCheckoutEmail;
use Tests\TestCase;

class VeltoCheckoutEmailTest extends TestCase
{
    public function test_it_uses_the_customer_email_when_provided(): void
    {
        config()->set('services.velto.sandbox_unique_customer_email', false);

        $order = new Order([
            'order_number' => 'TDEV-20261014-TEST',
            'customer_email' => 'acheteur@example.com',
        ]);

        $this->assertSame('acheteur@example.com', VeltoCheckoutEmail::forOrder($order));
    }

    public function test_it_tags_the_email_per_order_in_sandbox(): void
    {
        config()->set('services.velto.secret_key', 'sk_sandbox_test');
        config()->set('services.velto.sandbox_unique_customer_email', true);

        $order = new Order([
            'order_number' => 'TDEV-20261014-ABCD',
            'customer_email' => 'acheteur@example.com',
        ]);

        $this->assertSame(
            'acheteur+tdev20261014abcd@example.com',
            VeltoCheckoutEmail::forOrder($order),
        );
    }

    public function test_it_falls_back_to_a_technical_address_without_customer_email(): void
    {
        $order = new Order([
            'order_number' => 'TDEV-20261014-TEST',
            'customer_email' => null,
        ]);

        $email = VeltoCheckoutEmail::forOrder($order);

        $this->assertStringEndsWith('@checkout.guest.ourtdev.com', $email);
        $this->assertStringContainsString('tdev.20261014.test', strtolower($email));
    }
}
