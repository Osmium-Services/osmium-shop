<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Integration\DatabaseTestCase;

/**
 * A coupon with a minimum spend only applies when the basket reaches it, measured inc VAT, before the discount
 * and before delivery. Below it the coupon is held back and the totals say why.
 */
class CouponMinimumSpendTest extends DatabaseTestCase
{
    private function coupon(string $code, ?float $minSpend, float $value = 10.0): int
    {
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discounts (name, type, code, value_type, value, min_spend_inc_tax, active)
                  VALUES (:name, 'coupon', :code, 'percent', :value, :min, 1)",
            bindings: [':name' => 'zz-min-' . $code, ':code' => $code, ':value' => $value, ':min' => $minSpend],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function totals(): ShopTotalsService
    {
        return new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts')));
    }

    /** @return array<int, array<string, mixed>> one line: £50 exc VAT is £60 inc VAT */
    private function lines(int $quantity = 1): array
    {
        return [['product_id' => 1, 'quantity' => $quantity, 'unit_price_exc_tax' => 50.00]];
    }

    public function test_a_basket_at_the_minimum_gets_the_coupon(): void
    {
        $this->coupon('ZZMIN60', 60.00);

        $result = $this->totals()->calculate(lines: $this->lines(), couponCode: 'zzmin60');

        $this->assertSame('coupon', $result['discount']['type']);
        $this->assertNull($result['coupon_notice']);
        $this->assertSame(45.00, $result['lines'][0]['unit_price_exc_tax']);
    }

    public function test_a_basket_a_penny_under_the_minimum_is_held_back_and_says_by_how_much(): void
    {
        $this->coupon('ZZMIN6001', 60.01);

        $result = $this->totals()->calculate(lines: $this->lines(), couponCode: 'ZZMIN6001');

        $this->assertNull($result['discount']);
        $this->assertSame(0.0, $result['total_discount_amount']);
        $this->assertSame(50.00, $result['lines'][0]['unit_price_exc_tax']);
        $this->assertSame('min_spend', $result['coupon_notice']['type']);
        $this->assertSame('ZZMIN6001', $result['coupon_notice']['code']);
        $this->assertSame(0.01, $result['coupon_notice']['short_by_inc_tax']);
        $this->assertSame(60.01, $result['coupon_notice']['min_spend_inc_tax']);
        $this->assertStringContainsString('£0.01 more (inc. VAT)', $result['coupon_notice']['message']);
    }

    public function test_the_minimum_is_measured_inc_vat_not_exc_vat(): void
    {
        $this->coupon('ZZMIN55', 55.00); // £50 exc VAT is under 55 exc-only, but £60 inc VAT clears it

        $result = $this->totals()->calculate(lines: $this->lines(), couponCode: 'ZZMIN55');

        $this->assertSame('coupon', $result['discount']['type']);
    }

    public function test_quantity_counts_towards_the_minimum(): void
    {
        $this->coupon('ZZMIN100', 100.00);

        $one = $this->totals()->calculate(lines: $this->lines(1), couponCode: 'ZZMIN100');
        $two = $this->totals()->calculate(lines: $this->lines(2), couponCode: 'ZZMIN100');

        $this->assertSame(40.00, $one['coupon_notice']['short_by_inc_tax']);
        $this->assertSame('coupon', $two['discount']['type']);
    }

    public function test_the_basket_is_measured_before_the_coupon_takes_money_off(): void
    {
        $this->coupon('ZZMIN60B', 60.00, value: 50.0); // Takes half off, which would leave the basket under 60

        $result = $this->totals()->calculate(lines: $this->lines(), couponCode: 'ZZMIN60B');

        $this->assertSame('coupon', $result['discount']['type']);
        $this->assertNull($result['coupon_notice']);
    }

    public function test_delivery_does_not_count_towards_the_minimum(): void
    {
        $this->coupon('ZZMIN62', 62.00); // £60 of goods plus delivery would pass

        $result = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA', couponCode: 'ZZMIN62');

        $this->assertNull($result['discount']);
        $this->assertSame(2.00, $result['coupon_notice']['short_by_inc_tax']);
    }

    public function test_a_coupon_without_a_minimum_always_applies(): void
    {
        $this->coupon('ZZNOMIN', null);

        $result = $this->totals()->calculate(lines: [['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 1.00]], couponCode: 'ZZNOMIN');

        $this->assertSame('coupon', $result['discount']['type']);
        $this->assertNull($result['coupon_notice']);
    }

    public function test_no_coupon_gives_no_notice(): void
    {
        $this->assertNull($this->totals()->calculate(lines: $this->lines())['coupon_notice']);
    }
}
