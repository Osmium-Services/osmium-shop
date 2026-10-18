<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\BasketCouponCheck;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/BasketCouponCheck.php';

/**
 * When a typed coupon code is accepted and what the customer is told when it is not.
 */
class BasketCouponCheckTest extends TestCase
{
    public function test_a_known_code_has_no_error(): void
    {
        $this->assertNull(BasketCouponCheck::error(code: 'SAVE10', lookup: fn(string $c) => ['id' => 1]));
    }

    public function test_an_empty_code_asks_for_one(): void
    {
        $this->assertSame('Please enter a coupon code.', BasketCouponCheck::error(code: '', lookup: fn() => ['id' => 1]));
        $this->assertSame('Please enter a coupon code.', BasketCouponCheck::error(code: '   ', lookup: fn() => ['id' => 1]));
    }

    public function test_the_empty_code_message_wins_when_discounts_are_unsupported(): void
    {
        $this->assertSame('Please enter a coupon code.', BasketCouponCheck::error(code: '', lookup: null));
    }

    public function test_unsupported_discounts_say_coupons_are_unavailable(): void
    {
        $this->assertSame('Coupon codes are not available at the moment.', BasketCouponCheck::error(code: 'SAVE10', lookup: null));
    }

    public function test_an_unknown_or_expired_code_is_rejected(): void
    {
        $this->assertSame("That code isn't valid or has expired.", BasketCouponCheck::error(code: 'NOPE', lookup: fn() => null));
    }

    public function test_the_lookup_receives_the_trimmed_code(): void
    {
        $seen = null;
        BasketCouponCheck::error(code: "  SAVE10 \n", lookup: function (string $c) use (&$seen) {
            $seen = $c;
            return ['id' => 1];
        });

        $this->assertSame('SAVE10', $seen);
    }

    public function test_the_lookup_is_not_called_for_an_empty_code(): void
    {
        $called = false;
        BasketCouponCheck::error(code: '', lookup: function () use (&$called) {
            $called = true;
            return ['id' => 1];
        });

        $this->assertFalse($called);
    }
}
