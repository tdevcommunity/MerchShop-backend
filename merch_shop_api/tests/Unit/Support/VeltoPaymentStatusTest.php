<?php

namespace Tests\Unit\Support;

use App\Enums\PaymentStatus;
use App\Support\Payments\VeltoPaymentStatus;
use PHPUnit\Framework\TestCase;

class VeltoPaymentStatusTest extends TestCase
{
    public function test_it_maps_settled_statuses(): void
    {
        $this->assertSame(PaymentStatus::SUCCESS, VeltoPaymentStatus::fromOperator('APPROVED'));
    }

    public function test_it_maps_failed_and_expired_statuses(): void
    {
        foreach (['DECLINED', 'CANCELLED', 'EXPIRED', 'FAILED', 'REJECTED'] as $status) {
            $this->assertSame(
                PaymentStatus::FAILED,
                VeltoPaymentStatus::fromOperator($status),
                $status,
            );
        }
    }

    public function test_it_returns_null_for_in_progress_statuses(): void
    {
        $this->assertNull(VeltoPaymentStatus::fromOperator('PENDING'));
        $this->assertNull(VeltoPaymentStatus::fromOperator('PROCESSING'));
    }
}
