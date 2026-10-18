<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\DiscountMcp;
use PHPUnit\Framework\TestCase;

final class DiscountMcpTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => '7', 'name' => 'Spring', 'type' => 'coupon', 'code' => 'SPRING10',
            'value_type' => 'percent', 'value' => '10.00', 'starts_at' => null, 'ends_at' => null,
            'usage_limit' => null, 'times_used' => '3', 'active' => '1', 'product_count' => '0',
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-11 12:00:00');
    }

    public function test_the_list_is_read_only_and_delete_and_restore_are_writes(): void
    {
        $tools = \array_column(DiscountMcp::tools([]), null, 'name');

        $this->assertSame(['discounts_list', 'discount_create', 'discount_update', 'discount_delete', 'discount_restore'], \array_keys($tools));
        $this->assertArrayNotHasKey('write', $tools['discounts_list']);
        $this->assertTrue($tools['discount_create']['write']);
        $this->assertTrue($tools['discount_update']['write']);
        $this->assertTrue($tools['discount_delete']['write']);
        $this->assertTrue($tools['discount_restore']['write']);
    }

    public function test_neither_create_nor_update_offers_a_way_to_switch_a_discount_on_or_change_its_type(): void
    {
        $tools = \array_column(DiscountMcp::tools([]), null, 'name');

        $this->assertArrayNotHasKey('active', $tools['discount_create']['inputSchema']['properties']);
        $this->assertArrayNotHasKey('active', $tools['discount_update']['inputSchema']['properties']);
        $this->assertArrayNotHasKey('type', $tools['discount_update']['inputSchema']['properties']);
        $this->assertTrue($tools['discount_update']['destructive']);
        $this->assertArrayNotHasKey('destructive', $tools['discount_create']);
    }

    public function test_only_delete_is_marked_destructive(): void
    {
        $tools = \array_column(DiscountMcp::tools([]), null, 'name');

        $this->assertTrue($tools['discount_delete']['destructive']);
        $this->assertArrayNotHasKey('destructive', $tools['discount_restore']);
    }

    public function test_the_id_and_name_must_both_be_given_properly(): void
    {
        $this->assertSame([5, 'Spring'], DiscountMcp::idAndName(['id' => 5, 'expected_name' => ' Spring ']));

        foreach ([[], ['id' => 5], ['expected_name' => 'x'], ['id' => '5', 'expected_name' => 'x'], ['id' => 0, 'expected_name' => 'x'], ['id' => 5, 'expected_name' => '  ']] as $bad) {
            try {
                DiscountMcp::idAndName($bad);
                $this->fail('Expected a refusal for ' . \json_encode($bad));
            } catch (\Osmium\Core\Library\McpToolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_status_follows_the_switch_the_dates_and_the_usage_limit(): void
    {
        $now = $this->now();

        $this->assertSame('live', DiscountMcp::status($this->row(), $now));
        $this->assertSame('disabled', DiscountMcp::status($this->row(['active' => '0']), $now));
        $this->assertSame('scheduled', DiscountMcp::status($this->row(['starts_at' => '2026-11-01 00:00:00']), $now));
        $this->assertSame('expired', DiscountMcp::status($this->row(['ends_at' => '2026-10-01 00:00:00']), $now));
        $this->assertSame('live', DiscountMcp::status($this->row(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-12-01 00:00:00']), $now));
        $this->assertSame('used up', DiscountMcp::status($this->row(['usage_limit' => '3', 'times_used' => '3']), $now));
        $this->assertSame('live', DiscountMcp::status($this->row(['usage_limit' => '4', 'times_used' => '3']), $now));
    }

    public function test_a_disabled_discount_stays_disabled_even_when_used_up(): void
    {
        $this->assertSame('disabled', DiscountMcp::status($this->row(['active' => '0', 'usage_limit' => '1', 'times_used' => '5']), $this->now()));
    }

    public function test_values_read_as_a_percentage_or_pounds(): void
    {
        $percent = DiscountMcp::shapeList([$this->row(['value' => '12.50'])], $this->now())[0];
        $whole = DiscountMcp::shapeList([$this->row(['value' => '10.00'])], $this->now())[0];
        $fixed = DiscountMcp::shapeList([$this->row(['value_type' => 'fixed', 'value' => '5'])], $this->now())[0];

        $this->assertSame('12.5%', $percent['value']);
        $this->assertSame('10%', $whole['value']);
        $this->assertSame('£5.00', $fixed['value']);
    }

    public function test_a_live_row_shows_usage_and_nothing_extra(): void
    {
        $row = $this->row(['usage_limit' => '10', 'min_spend_inc_tax' => '50.00', 'product_count' => '4', 'created_at' => '2026-01-01', 'usage_limit_per_customer' => '1']);

        $this->assertSame([
            'id' => 7, 'name' => 'Spring', 'type' => 'coupon', 'code' => 'SPRING10', 'value' => '10%',
            'starts_at' => null, 'ends_at' => null, 'status' => 'live', 'times_used' => 3, 'usage_limit' => 10, 'usage_limit_per_customer' => 1, 'min_spend_inc_tax' => 50.0, 'products' => 4,
        ], DiscountMcp::shapeList([$row], $this->now())[0]);
    }

    public function test_a_binned_row_shows_only_what_identifies_it(): void
    {
        $row = ['id' => '9', 'name' => 'Old', 'type' => 'sitewide', 'deleted_at' => '2026-10-01 10:00:00'];

        $this->assertSame(
            ['id' => 9, 'name' => 'Old', 'type' => 'sitewide', 'deleted_at' => '2026-10-01 10:00:00'],
            DiscountMcp::shapeList([$row], $this->now())[0],
        );
    }

    public function test_a_free_delivery_coupon_reads_as_free_delivery(): void
    {
        $row = $this->row(['value_type' => 'free_delivery', 'value' => '0.00']);

        $this->assertSame('Free delivery', DiscountMcp::shapeList([$row], $this->now())[0]['value']);
    }

    private const PRODUCTS = [7 => 'Panel A', 8 => 'Panel B'];

    private function fields(array $arguments): array
    {
        return DiscountMcp::createFields($arguments, self::PRODUCTS);
    }

    private function refused(array $arguments): string
    {
        try {
            $this->fields($arguments);
        } catch (\Osmium\Core\Library\McpToolException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected a refusal for ' . \json_encode($arguments));
    }

    public function test_a_sitewide_discount_is_cleaned_up(): void
    {
        $f = $this->fields(['name' => '  Spring  ', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 10]);

        $this->assertSame('Spring', $f['name']);
        $this->assertSame(10.0, $f['value']);
        $this->assertNull($f['code']);
        $this->assertSame([], $f['product_ids']);
        $this->assertNull($f['starts_at']);
        $this->assertNull($f['min_spend_inc_tax']);
    }

    public function test_a_coupon_code_is_made_upper_case_and_its_options_are_kept(): void
    {
        $f = $this->fields([
            'name' => 'Welcome', 'type' => 'coupon', 'value_type' => 'fixed', 'value' => 5.5, 'code' => ' welcome5 ',
            'usage_limit' => 100, 'usage_limit_per_customer' => 1, 'min_spend_inc_tax' => 49.999,
        ]);

        $this->assertSame('WELCOME5', $f['code']);
        $this->assertSame(100, $f['usage_limit']);
        $this->assertSame(1, $f['usage_limit_per_customer']);
        $this->assertSame(50.0, $f['min_spend_inc_tax']);
    }

    public function test_free_delivery_takes_no_value(): void
    {
        $f = $this->fields(['name' => 'FD', 'type' => 'coupon', 'value_type' => 'free_delivery', 'code' => 'FD']);

        $this->assertSame(0.0, $f['value']);
        $this->assertStringContainsString('no value', $this->refused(['name' => 'FD', 'type' => 'coupon', 'value_type' => 'free_delivery', 'code' => 'FD', 'value' => 5]));
    }

    public function test_the_basics_are_required_and_must_be_the_right_kind(): void
    {
        $good = ['name' => 'X', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 10];

        foreach ([
            ['name' => ''], ['name' => 5], ['name' => "Two\nlines"], ['name' => \str_repeat('a', 256)],
            ['type' => 'bogus'], ['type' => null], ['value_type' => 'bogus'],
            ['value' => '10'], ['value' => null],
        ] as $override) {
            $this->refused($override + $good);
        }
        $this->addToAssertionCount(1);
    }

    public function test_fields_that_do_not_belong_to_the_type_are_refused_not_ignored(): void
    {
        $base = ['name' => 'X', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 10];

        foreach (['code' => 'ABC', 'usage_limit' => 5, 'usage_limit_per_customer' => 1, 'min_spend_inc_tax' => 20.0] as $field => $value) {
            $this->assertStringContainsString('only applies to a coupon', $this->refused([$field => $value] + $base));
        }
        $this->assertStringContainsString('only applies to a product', $this->refused(['product_ids' => [7]] + $base));
    }

    public function test_a_coupon_needs_a_code_and_a_product_discount_needs_live_products(): void
    {
        $this->assertStringContainsString('needs a code', $this->refused(['name' => 'X', 'type' => 'coupon', 'value_type' => 'percent', 'value' => 5]));

        $product = ['name' => 'X', 'type' => 'product', 'value_type' => 'percent', 'value' => 5];
        $this->assertSame([7, 8], $this->fields($product + ['product_ids' => [7, 8, 7]])['product_ids']);
        $this->assertStringContainsString('list of product ids', $this->refused($product));
        $this->assertStringContainsString('list of product ids', $this->refused($product + ['product_ids' => []]));
        $this->assertStringContainsString('list of product ids', $this->refused($product + ['product_ids' => ['7']]));
        $this->assertStringContainsString('No live product with id 99', $this->refused($product + ['product_ids' => [7, 99]]));
    }

    public function test_limits_must_be_whole_numbers_of_at_least_one_and_minimum_spend_above_zero(): void
    {
        $coupon = ['name' => 'X', 'type' => 'coupon', 'value_type' => 'percent', 'value' => 5, 'code' => 'X'];

        foreach (['usage_limit' => 0, 'usage_limit_per_customer' => -1, 'usage_limit ' => 2.5] as $field => $value) {
            $this->refused([\trim($field) => $value] + $coupon);
        }
        $this->refused(['min_spend_inc_tax' => 0] + $coupon);
        $this->refused(['min_spend_inc_tax' => '50'] + $coupon);
        $this->addToAssertionCount(1);
    }

    public function test_dates_are_real_and_a_date_alone_means_start_or_end_of_that_day(): void
    {
        $base = ['name' => 'X', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 10];

        $f = $this->fields($base + ['starts_at' => '2026-12-01', 'ends_at' => '2026-12-24']);
        $this->assertSame('2026-12-01 00:00:00', $f['starts_at']);
        $this->assertSame('2026-12-24 23:59:59', $f['ends_at']);

        $timed = $this->fields($base + ['starts_at' => '2026-12-01 09:30', 'ends_at' => '2026-12-24 17:00']);
        $this->assertSame('2026-12-01 09:30:00', $timed['starts_at']);
        $this->assertSame('2026-12-24 17:00:00', $timed['ends_at']);

        $this->assertNull($this->fields($base + ['starts_at' => '  '])['starts_at']);
        foreach (['tomorrow', '2026-02-30', '01/12/2026', '2026-12-01T09:30', 12] as $bad) {
            $this->assertStringContainsString('starts_at', $this->refused($base + ['starts_at' => $bad]));
        }
    }
}
