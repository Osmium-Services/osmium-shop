<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\ShopCaptureCheck;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/ShopCaptureCheck.php';

/**
 * What a provider's confirmation means for an order: the decision that tells a
 * customer "paid", "pending" or "do not pay twice".
 */
class ShopCaptureCheckTest extends TestCase
{
    private function order(string $total = '245.99', string $currency = 'GBP'): array
    {
        return ['total_inc_tax' => $total, 'currency' => $currency];
    }

    private function payment(array $overrides = []): array
    {
        return $overrides + ['amount' => 245.99, 'currency' => 'GBP', 'status' => 'completed'];
    }

    public function test_an_exact_completed_payment_is_completed(): void
    {
        $this->assertSame(ShopCaptureCheck::COMPLETED, ShopCaptureCheck::outcome($this->order(), $this->payment()));
    }

    public function test_the_amount_is_compared_as_a_number_not_a_string(): void
    {
        $this->assertSame(
            ShopCaptureCheck::COMPLETED,
            ShopCaptureCheck::outcome($this->order('245.90'), $this->payment(['amount' => '245.9'])),
        );
    }

    public function test_float_noise_under_half_a_penny_is_not_a_mismatch(): void
    {
        $this->assertSame(
            ShopCaptureCheck::COMPLETED,
            ShopCaptureCheck::outcome($this->order(), $this->payment(['amount' => 245.9949])),
        );
    }

    public function test_a_penny_out_is_a_mismatch(): void
    {
        $this->assertSame(
            ShopCaptureCheck::MISMATCH,
            ShopCaptureCheck::outcome($this->order('100.00'), $this->payment(['amount' => 100.01])),
        );
    }

    public function test_a_penny_short_is_a_mismatch(): void
    {
        $this->assertSame(
            ShopCaptureCheck::MISMATCH,
            ShopCaptureCheck::outcome($this->order('100.00'), $this->payment(['amount' => 99.99])),
        );
    }

    public function test_an_underpayment_is_a_mismatch(): void
    {
        $this->assertSame(ShopCaptureCheck::MISMATCH, ShopCaptureCheck::outcome($this->order(), $this->payment(['amount' => 24.599])));
    }

    public function test_an_overpayment_is_a_mismatch(): void
    {
        $this->assertSame(ShopCaptureCheck::MISMATCH, ShopCaptureCheck::outcome($this->order(), $this->payment(['amount' => 2459.90])));
    }

    public function test_the_right_amount_in_the_wrong_currency_is_a_mismatch(): void
    {
        $this->assertSame(ShopCaptureCheck::MISMATCH, ShopCaptureCheck::outcome($this->order(), $this->payment(['currency' => 'USD'])));
    }

    public function test_the_currency_comparison_is_exact(): void
    {
        $this->assertSame(ShopCaptureCheck::MISMATCH, ShopCaptureCheck::outcome($this->order(), $this->payment(['currency' => 'gbp'])));
    }

    public function test_a_missing_amount_is_a_mismatch_not_a_zero_payment_that_passes(): void
    {
        $payment = ['currency' => 'GBP', 'status' => 'completed'];

        $this->assertSame(ShopCaptureCheck::MISMATCH, ShopCaptureCheck::outcome($this->order(), $payment));
    }

    public function test_a_free_order_matches_a_zero_amount(): void
    {
        $this->assertSame(
            ShopCaptureCheck::COMPLETED,
            ShopCaptureCheck::outcome($this->order('0.00'), $this->payment(['amount' => 0.0])),
        );
    }

    public function test_pending_with_the_right_amount_is_pending(): void
    {
        $this->assertSame(ShopCaptureCheck::PENDING, ShopCaptureCheck::outcome($this->order(), $this->payment(['status' => 'pending'])));
    }

    public function test_a_wrong_amount_is_a_mismatch_even_when_the_provider_also_says_pending(): void
    {
        $this->assertSame(
            ShopCaptureCheck::MISMATCH,
            ShopCaptureCheck::outcome($this->order(), $this->payment(['status' => 'pending', 'amount' => 1.00])),
        );
    }

    /**
     * @dataProvider failureStatuses
     */
    public function test_any_other_status_is_a_failure(string $status): void
    {
        $this->assertSame(ShopCaptureCheck::FAILED, ShopCaptureCheck::outcome($this->order(), $this->payment(['status' => $status])));
    }

    /** @return array<string, array{string}> */
    public static function failureStatuses(): array
    {
        return [
            'failed' => ['failed'],
            'declined' => ['declined'],
            'canceled' => ['canceled'],
            'refunded' => ['refunded'],
            'empty' => [''],
            'wrong case' => ['Completed'],
        ];
    }

    public function test_a_missing_status_is_a_failure(): void
    {
        $payment = ['amount' => 245.99, 'currency' => 'GBP'];

        $this->assertSame(ShopCaptureCheck::FAILED, ShopCaptureCheck::outcome($this->order(), $payment));
    }
}
