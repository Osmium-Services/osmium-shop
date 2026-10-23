<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Integration\DatabaseTestCase;

/**
 * The free delivery switch waives the delivery charge on top of whatever the discount takes off. A coupon still
 * respects its minimum spend.
 */
class FreeDeliveryCouponTest extends DatabaseTestCase
{
    private function discount(array $fields): int
    {
        $row = $fields + ['name' => 'zz-fd-' . \uniqid(), 'type' => 'coupon', 'code' => null, 'value_type' => 'percent', 'value' => 0, 'free_delivery' => 1, 'min_spend_inc_tax' => null];
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discounts (name, type, code, value_type, value, free_delivery, min_spend_inc_tax, active)
                  VALUES (:name, :type, :code, :value_type, :value, :fd, :min, 1)",
            bindings: [':name' => $row['name'], ':type' => $row['type'], ':code' => $row['code'], ':value_type' => $row['value_type'], ':value' => $row['value'], ':fd' => $row['free_delivery'], ':min' => $row['min_spend_inc_tax']],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function totals(?float $freeDeliveryThreshold = null): ShopTotalsService
    {
        return new ShopTotalsService(
            taxRatePercent: 20.0,
            deliveryCharge: 4.99,
            highlandsSurcharge: 3.00,
            surchargePostcodes: ['IV'],
            freeDeliveryThreshold: $freeDeliveryThreshold,
            discounts: new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts')),
        );
    }

    /** @return array<int, array<string, mixed>> £50 exc VAT, £60 inc VAT */
    private function lines(int $quantity = 1): array
    {
        return [['product_id' => 1, 'quantity' => $quantity, 'unit_price_exc_tax' => 50.00]];
    }

    public function test_the_coupon_waives_the_delivery_charge_and_takes_nothing_off_the_goods(): void
    {
        $this->discount(['code' => 'ZZFREEDEL']);

        $without = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA');
        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA', couponCode: 'zzfreedel');

        $this->assertSame(4.99, $without['delivery_exc_tax']);
        $this->assertFalse($without['free_delivery']);
        $this->assertSame(0.0, $with['delivery_exc_tax']);
        $this->assertTrue($with['free_delivery']);
        $this->assertSame(0.0, $with['total_discount_amount']);
        $this->assertSame(50.00, $with['lines'][0]['unit_price_exc_tax']);
        $this->assertSame(50.00, $with['subtotal_exc_tax']);
        $this->assertEqualsWithDelta(60.00, $with['total_inc_tax'], 0.001); // Goods only, with VAT, no delivery
    }

    public function test_it_waives_the_highlands_surcharge_too(): void
    {
        $this->discount(['code' => 'ZZFREEDEL']);

        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'IV1 1AA', couponCode: 'ZZFREEDEL');

        $this->assertSame(ShopTotalsService::ZONE_HIGHLANDS, $with['delivery_zone']);
        $this->assertSame(0.0, $with['delivery_exc_tax']);
    }

    public function test_the_summary_names_the_coupon_so_the_order_records_it(): void
    {
        $id = $this->discount(['code' => 'ZZFREEDEL']);

        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA', couponCode: 'ZZFREEDEL');

        $this->assertSame('coupon', $with['discount']['type']);
        $this->assertSame($id, $with['discount']['id']);
        $this->assertSame('ZZFREEDEL', $with['discount']['code']);
    }

    public function test_it_respects_a_minimum_spend(): void
    {
        $this->discount(['code' => 'ZZFREEDEL100', 'min_spend_inc_tax' => 100.00]);

        $under = $this->totals()->calculate(lines: $this->lines(1), postcode: 'SW1A 1AA', couponCode: 'ZZFREEDEL100');
        $over = $this->totals()->calculate(lines: $this->lines(2), postcode: 'SW1A 1AA', couponCode: 'ZZFREEDEL100');

        $this->assertSame(4.99, $under['delivery_exc_tax']);
        $this->assertFalse($under['free_delivery']);
        $this->assertSame(40.00, $under['coupon_notice']['short_by_inc_tax']);
        $this->assertSame(0.0, $over['delivery_exc_tax']);
        $this->assertTrue($over['free_delivery']);
    }

    public function test_a_per_product_discount_with_the_switch_on_waives_delivery(): void
    {
        $productDiscount = $this->discount(['type' => 'product', 'value' => 10, 'free_delivery' => 1]);
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discount_products (discount_id, product_id) VALUES (:d, 1)",
            bindings: [':d' => $productDiscount],
        )->execute();

        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA');

        $this->assertSame(45.00, $with['lines'][0]['unit_price_exc_tax']); // 10% off the product
        $this->assertSame(0.0, $with['delivery_exc_tax']);
        $this->assertTrue($with['free_delivery']);
    }

    public function test_a_per_product_discount_with_the_switch_off_leaves_delivery_alone(): void
    {
        $productDiscount = $this->discount(['type' => 'product', 'value' => 10, 'free_delivery' => 0]);
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discount_products (discount_id, product_id) VALUES (:d, 1)",
            bindings: [':d' => $productDiscount],
        )->execute();

        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA');

        $this->assertSame(4.99, $with['delivery_exc_tax']);
        $this->assertFalse($with['free_delivery']);
    }

    public function test_a_sitewide_discount_with_the_switch_on_waives_delivery(): void
    {
        $this->discount(['type' => 'sitewide', 'value' => 10, 'free_delivery' => 1]);

        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA');

        $this->assertSame(0.0, $with['delivery_exc_tax']);
        $this->assertTrue($with['free_delivery']);
    }

    public function test_a_sitewide_discount_still_wins_over_any_coupon(): void
    {
        $this->discount(['code' => 'ZZFREEDEL']);
        $this->discount(['type' => 'sitewide', 'value_type' => 'percent', 'value' => 10, 'free_delivery' => 0]);

        $with = $this->totals()->calculate(lines: $this->lines(), postcode: 'SW1A 1AA', couponCode: 'ZZFREEDEL');

        $this->assertSame('sitewide', $with['discount']['type']);
        $this->assertSame(4.99, $with['delivery_exc_tax']);
        $this->assertFalse($with['free_delivery']);
    }

    public function test_an_undeliverable_postcode_stays_undeliverable(): void
    {
        $this->discount(['code' => 'ZZFREEDEL']);
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, excludedPostcodes: ['GY'], discounts: new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts')));

        $with = $totals->calculate(lines: $this->lines(), postcode: 'GY1 1AA', couponCode: 'ZZFREEDEL');

        $this->assertFalse($with['deliverable']);
    }
}
