<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\CheckoutOrderPaymentState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/CheckoutOrderPaymentState.php';

/**
 * Which orders may be told "already paid" when a customer confirms again.
 */
class CheckoutOrderPaymentStateTest extends TestCase
{
    public function test_a_pending_order_is_still_awaiting_payment(): void
    {
        $this->assertSame(CheckoutOrderPaymentState::AWAITING, CheckoutOrderPaymentState::of('pending'));
    }

    public function test_paid_and_dispatched_orders_are_paid(): void
    {
        $this->assertSame(CheckoutOrderPaymentState::PAID, CheckoutOrderPaymentState::of('paid'));
        $this->assertSame(CheckoutOrderPaymentState::PAID, CheckoutOrderPaymentState::of('dispatched'));
    }

    public function test_failed_cancelled_and_refunded_orders_are_never_reported_as_paid(): void
    {
        foreach (['failed', 'cancelled', 'refunded'] as $status) {
            $this->assertSame(CheckoutOrderPaymentState::CLOSED, CheckoutOrderPaymentState::of($status), $status);
        }
    }

    public function test_an_unknown_status_is_closed_not_paid(): void
    {
        $this->assertSame(CheckoutOrderPaymentState::CLOSED, CheckoutOrderPaymentState::of(''));
        $this->assertSame(CheckoutOrderPaymentState::CLOSED, CheckoutOrderPaymentState::of('PAID'));
    }
}
