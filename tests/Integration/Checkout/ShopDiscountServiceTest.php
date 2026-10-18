<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Integration\DatabaseTestCase;

class ShopDiscountServiceTest extends DatabaseTestCase
{
    private function createDiscount(array $overrides = []): int
    {
        $defaults = [
            'name' => 'Test discount ' . \uniqid(),
            'type' => 'sitewide',
            'code' => null,
            'value_type' => 'percent',
            'value' => 10.00,
            'active' => 1,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'usage_limit_per_customer' => null,
            'deleted_at' => null,
        ];

        $data = \array_merge($defaults, $overrides);

        $sql = "INSERT INTO {$this->db->tablePrefix()}discounts
                (name, type, code, value_type, value, active, starts_at, ends_at, usage_limit, usage_limit_per_customer, deleted_at)
                VALUES (:name, :type, :code, :value_type, :value, :active, :starts_at, :ends_at, :usage_limit, :usage_limit_per_customer, :deleted_at)";

        $this->db->prepare(sql: $sql, bindings: [
            ':name' => $data['name'],
            ':type' => $data['type'],
            ':code' => $data['code'],
            ':value_type' => $data['value_type'],
            ':value' => $data['value'],
            ':active' => $data['active'],
            ':starts_at' => $data['starts_at'],
            ':ends_at' => $data['ends_at'],
            ':usage_limit' => $data['usage_limit'],
            ':usage_limit_per_customer' => $data['usage_limit_per_customer'],
            ':deleted_at' => $data['deleted_at'],
        ])->execute();

        return (int)$this->db->lastInsertId();
    }

    private function linkProduct(int $discountId, int $productId): void
    {
        $sql = "INSERT INTO {$this->db->tablePrefix()}discount_products (discount_id, product_id)
                VALUES (:discount_id, :product_id)";

        $this->db->prepare(sql: $sql, bindings: [
            ':discount_id' => $discountId,
            ':product_id' => $productId,
        ])->execute();
    }

    public function test_active_sitewide_discount_reduces_unit_price(): void
    {
        $this->createDiscount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 15.00]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate([
            ['product_id' => 1, 'quantity' => 2, 'unit_price_exc_tax' => 100.00],
        ]);

        $line = $result['lines'][0];
        $this->assertSame(85.00, $line['unit_price_exc_tax']);
        $this->assertSame(100.00, $line['unit_price_before_discount_exc_tax']);
        $this->assertSame(170.00, $result['subtotal_exc_tax']);
    }

    public function test_product_discount_applies_only_to_its_product(): void
    {
        $discountId = $this->createDiscount(['type' => 'product', 'value_type' => 'fixed', 'value' => 20.00]);
        $this->linkProduct($discountId, 42);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate([
            ['product_id' => 42, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
            ['product_id' => 99, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ]);

        $this->assertSame(80.00, $result['lines'][0]['unit_price_exc_tax']);
        $this->assertSame(100.00, $result['lines'][1]['unit_price_exc_tax']);
    }

    public function test_sitewide_discount_wins_over_product_discount_on_the_same_line(): void
    {
        $this->createDiscount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 15.00]);
        $productDiscountId = $this->createDiscount(['type' => 'product', 'value_type' => 'percent', 'value' => 50.00]);
        $this->linkProduct($productDiscountId, 42);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate([
            ['product_id' => 42, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ]);

        // Sitewide (15% off) must win, not the larger 50% product discount
        $this->assertSame(85.00, $result['lines'][0]['unit_price_exc_tax']);
    }

    public function test_expired_discount_does_not_apply(): void
    {
        $this->createDiscount([
            'type' => 'sitewide',
            'value_type' => 'percent',
            'value' => 15.00,
            'starts_at' => '2020-01-01 00:00:00',
            'ends_at' => '2020-01-31 23:59:59',
        ]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate([
            ['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ]);

        $this->assertSame(100.00, $result['lines'][0]['unit_price_exc_tax']);
    }

    public function test_inactive_discount_does_not_apply(): void
    {
        $this->createDiscount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 15.00, 'active' => 0]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate([
            ['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ]);

        $this->assertSame(100.00, $result['lines'][0]['unit_price_exc_tax']);
    }

    public function test_no_discount_service_means_no_discount_applied(): void
    {
        $this->createDiscount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 15.00]);

        // Mimics ShopTotalsService constructed with discounts: null, as happens
        // when no supported payment provider is configured — discounts must have no effect.
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99);

        $result = $totals->calculate([
            ['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ]);

        $this->assertSame(100.00, $result['lines'][0]['unit_price_exc_tax']);
    }

    public function test_valid_coupon_reduces_every_line_in_the_basket(): void
    {
        $this->createDiscount(['type' => 'coupon', 'code' => 'SAVE10', 'value_type' => 'percent', 'value' => 10.00]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate(lines: [
            ['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
            ['product_id' => 2, 'quantity' => 1, 'unit_price_exc_tax' => 50.00],
        ], couponCode: 'save10');

        $this->assertSame(90.00, $result['lines'][0]['unit_price_exc_tax']);
        $this->assertSame(45.00, $result['lines'][1]['unit_price_exc_tax']);
        $this->assertSame('coupon', $result['discount']['type']);
        $this->assertSame('SAVE10', $result['discount']['code']);
        $this->assertSame(15.00, $result['total_discount_amount']);
    }

    public function test_sitewide_discount_wins_over_coupon(): void
    {
        $this->createDiscount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 15.00]);
        $this->createDiscount(['type' => 'coupon', 'code' => 'SAVE50', 'value_type' => 'percent', 'value' => 50.00]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate(lines: [
            ['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ], couponCode: 'SAVE50');

        $this->assertSame(85.00, $result['lines'][0]['unit_price_exc_tax']);
        $this->assertSame('sitewide', $result['discount']['type']);
    }

    public function test_a_valid_code_during_a_sitewide_sale_says_the_sale_takes_its_place(): void
    {
        $this->createDiscount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 20.00]);
        $this->createDiscount(['type' => 'coupon', 'code' => 'SAVE50', 'value_type' => 'percent', 'value' => 50.00]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);
        $lines = [['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00]];

        $withCode = $totals->calculate(lines: $lines, couponCode: ' save50 ');
        $badCode = $totals->calculate(lines: $lines, couponCode: 'NOPE');
        $noCode = $totals->calculate(lines: $lines);

        $this->assertSame('sitewide', $withCode['discount']['type']);
        $this->assertSame('sitewide_sale', $withCode['coupon_notice']['type']);
        $this->assertSame('SAVE50', $withCode['coupon_notice']['code']);
        $this->assertSame('A sale is already running, so coupon codes cannot be used with it.', $withCode['coupon_notice']['message']);
        $this->assertNull($badCode['coupon_notice']);
        $this->assertNull($noCode['coupon_notice']);
    }

    public function test_coupon_wins_over_product_discount_on_the_same_line(): void
    {
        $productDiscountId = $this->createDiscount(['type' => 'product', 'value_type' => 'percent', 'value' => 80.00]);
        $this->linkProduct($productDiscountId, 42);
        $this->createDiscount(['type' => 'coupon', 'code' => 'SAVE10', 'value_type' => 'percent', 'value' => 10.00]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate(lines: [
            ['product_id' => 42, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ], couponCode: 'SAVE10');

        $this->assertSame(90.00, $result['lines'][0]['unit_price_exc_tax']);
    }

    public function test_invalid_coupon_code_applies_no_discount(): void
    {
        $this->createDiscount(['type' => 'coupon', 'code' => 'REAL10', 'value_type' => 'percent', 'value' => 10.00]);

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts);

        $result = $totals->calculate(lines: [
            ['product_id' => 1, 'quantity' => 1, 'unit_price_exc_tax' => 100.00],
        ], couponCode: 'FAKECODE');

        $this->assertSame(100.00, $result['lines'][0]['unit_price_exc_tax']);
        $this->assertNull($result['discount']);
    }

    public function test_coupon_at_its_usage_limit_is_inert(): void
    {
        $this->createDiscount([
            'type' => 'coupon',
            'code' => 'MAXEDOUT',
            'value_type' => 'percent',
            'value' => 10.00,
            'usage_limit' => 2,
        ]);
        $this->db->prepare(
            sql: "UPDATE {$this->db->tablePrefix()}discounts SET times_used = 2 WHERE code = 'MAXEDOUT'",
        )->execute();

        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));

        $this->assertNull($discounts->activeCouponByCode('MAXEDOUT'));
    }
}
