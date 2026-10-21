<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\DiscountRules;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/DiscountRules.php';

/**
 * DiscountRules validates the admin discount form and parses its inputs.
 */
class DiscountRulesTest extends TestCase
{
    private function validate(array $overrides = [], ?callable $codeInUse = null): ?string
    {
        $args = $overrides + [
            'name' => 'Spring sale', 'type' => 'sitewide', 'code' => '', 'valueType' => 'percent', 'value' => 10.0,
            'startsAt' => null, 'endsAt' => null, 'productIds' => [],
        ];
        $args['codeInUse'] = $codeInUse ?? fn(string $code): bool => false;

        return DiscountRules::validate(...$args);
    }

    public function test_valid_sitewide_discount_passes(): void
    {
        $this->assertNull($this->validate());
    }

    public function test_each_rule_has_its_message(): void
    {
        $this->assertSame('Discount name is required', $this->validate(['name' => '']));
        $this->assertSame('Invalid discount type', $this->validate(['type' => 'bogus']));
        $this->assertSame('Invalid discount value type', $this->validate(['valueType' => 'bogus']));
        $this->assertSame('Discount value must be greater than zero', $this->validate(['value' => 0.0]));
        $this->assertSame('Percentage discount cannot exceed 100%', $this->validate(['value' => 100.5]));
        $this->assertSame('End date must be after the start date', $this->validate([
            'startsAt' => '2026-02-01 00:00:00', 'endsAt' => '2026-01-01 00:00:00',
        ]));
        $this->assertSame(
            'Select at least one product, category or brand for a per-product discount',
            $this->validate(['type' => 'product']),
        );
        $this->assertNull($this->validate(['type' => 'product', 'categoryIds' => [7]]));
        $this->assertNull($this->validate(['type' => 'product', 'brandIds' => [3]]));
        $this->assertSame('A coupon code is required', $this->validate(['type' => 'coupon']));
        $this->assertSame(
            'Coupon codes may only contain letters, numbers, hyphens and underscores',
            $this->validate(['type' => 'coupon', 'code' => 'BAD CODE!']),
        );
    }

    public function test_fixed_amount_may_exceed_one_hundred(): void
    {
        $this->assertNull($this->validate(['valueType' => 'fixed', 'value' => 250.0]));
    }

    public function test_product_discount_with_products_passes(): void
    {
        $this->assertNull($this->validate(['type' => 'product', 'productIds' => [3]]));
    }

    public function test_taken_coupon_code_is_refused(): void
    {
        $result = $this->validate(['type' => 'coupon', 'code' => 'SAVE10'], fn(string $code): bool => true);

        $this->assertSame('That coupon code is already in use', $result);
    }

    public function test_code_lookup_runs_only_for_coupons_that_pass_every_other_check(): void
    {
        $calls = 0;
        $lookup = function (string $code) use (&$calls): bool {
            $calls++;
            return false;
        };

        $this->validate(['type' => 'sitewide'], $lookup);
        $this->validate(['type' => 'coupon', 'code' => 'OK1', 'value' => 0.0], $lookup);
        $this->assertSame(0, $calls);

        $this->validate(['type' => 'coupon', 'code' => 'OK1'], $lookup);
        $this->assertSame(1, $calls);
    }

    public function test_parse_date(): void
    {
        $this->assertNull(DiscountRules::parseDate(''));
        $this->assertNull(DiscountRules::parseDate('  '));
        $this->assertNull(DiscountRules::parseDate('not a date'));
        $this->assertSame('2026-03-04 09:05:00', DiscountRules::parseDate('2026-03-04T09:05'));
    }

    public function test_parse_int(): void
    {
        $this->assertNull(DiscountRules::parseInt(''));
        $this->assertSame(7, DiscountRules::parseInt(' 7 '));
        $this->assertSame(0, DiscountRules::parseInt('-5'));
    }

    public function test_a_minimum_spend_must_be_a_sensible_amount(): void
    {
        $this->assertNull($this->validate(['minSpendIncTax' => 50.0]));
        $this->assertNull($this->validate(['minSpendIncTax' => null]));
        $this->assertNotNull($this->validate(['minSpendIncTax' => 1000000.01]));
    }

    public function test_money_is_parsed_to_pence_and_blank_or_nonsense_means_none(): void
    {
        $this->assertSame(50.0, DiscountRules::parseMoney('50'));
        $this->assertSame(49.99, DiscountRules::parseMoney(' £49.99 '));
        $this->assertSame(1234.5, DiscountRules::parseMoney('1,234.50'));
        $this->assertSame(10.01, DiscountRules::parseMoney('10.005')); // Half a penny rounds up
        foreach (['', '  ', '0', '0.00', '-5', 'abc', '12abc'] as $none) {
            $this->assertNull(DiscountRules::parseMoney($none), "'{$none}'");
        }
    }

    public function test_free_delivery_needs_no_amount_but_only_works_as_a_coupon(): void
    {
        $this->assertNull($this->validate(['type' => 'coupon', 'code' => 'FREEDEL', 'valueType' => 'free_delivery', 'value' => 0.0]));
        $this->assertSame('Free delivery is only available as a coupon code', $this->validate(['type' => 'sitewide', 'valueType' => 'free_delivery', 'value' => 0.0]));
        $this->assertSame('Free delivery is only available as a coupon code', $this->validate(['type' => 'product', 'valueType' => 'free_delivery', 'value' => 0.0, 'productIds' => [1]]));
    }

    public function test_the_other_types_still_need_an_amount_above_zero(): void
    {
        $this->assertSame('Discount value must be greater than zero', $this->validate(['valueType' => 'fixed', 'value' => 0.0]));
        $this->assertSame('Discount value must be greater than zero', $this->validate(['valueType' => 'percent', 'value' => 0.0]));
    }
}
