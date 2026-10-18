<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Models\Discount;
use Osmium\Modules\Admin\Services\DiscountMcp;
use Tests\Integration\DatabaseTestCase;

/**
 * The discount write tools run for real against the tables. A discount made or edited here must always be
 * switched off, an edit must refuse a switched-on discount, the type must never change, a code must never be
 * reused (the bin counts), delete and restore must only move to and from the bin, and every refusal must leave
 * the database exactly as it was.
 */
class DiscountMcpHandlersTest extends DatabaseTestCase
{
    private Discount $discounts;
    private string $runTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discounts = new Discount($this->db, 'discounts');
        $this->runTag = 'ZQ' . \strtoupper(\substr(\uniqid(), -6));
    }

    private function call(array $arguments): array
    {
        return ['dataSource' => $this->db, 'arguments' => $arguments, 'actor' => 'tester'];
    }

    /** @return array<string, mixed> */
    private function create(array $arguments): array
    {
        return DiscountMcp::create($this->call($arguments));
    }

    private function discountName(string $suffix): string
    {
        return "{$this->runTag} {$suffix}";
    }

    private function couponCode(string $suffix): string
    {
        return "{$this->runTag}{$suffix}";
    }

    private function discountRows(): int
    {
        $prefix = $this->db->tablePrefix();

        return (int) $this->db->prepare("SELECT COUNT(*) AS total FROM {$prefix}discounts")->single()['total'];
    }

    private function refused(callable $attempt, string $messagePart): void
    {
        try {
            $attempt();
            $this->fail("Expected a refusal containing: {$messagePart}");
        } catch (McpToolException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    private function switchOn(int $id): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare("UPDATE {$prefix}discounts SET active = 1 WHERE id = :id", [':id' => $id])->execute();
    }

    /** @return array<int, array<string, mixed>> */
    private function changelog(int $id): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->prepare("SELECT * FROM {$prefix}changelog WHERE record_type = 'discount' AND record_id = :id ORDER BY id ASC", [':id' => $id])->resultset();
    }

    private function product(): int
    {
        return $this->createTestProduct();
    }

    private function sitewide(string $suffix, array $more = []): array
    {
        return $this->create(\array_merge(['name' => $this->discountName($suffix), 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 10], $more));
    }

    private function coupon(string $suffix, array $more = []): array
    {
        return $this->create(\array_merge(['name' => $this->discountName($suffix), 'type' => 'coupon', 'code' => $this->couponCode($suffix), 'value_type' => 'percent', 'value' => 10], $more));
    }

    // ---- create -------------------------------------------------------------------------------------------

    public function test_a_new_discount_is_always_switched_off_even_if_the_caller_asks_for_it_on(): void
    {
        $result = $this->sitewide('off', ['active' => 1, 'enabled' => true]);

        $this->assertSame(0, (int) $this->discounts->getById($result['id'])['active']);
        $this->assertTrue($result['created']);
        $this->assertSame('disabled', $result['status']);
    }

    public function test_a_percentage_sitewide_discount_is_stored_as_given(): void
    {
        $result = $this->create(['name' => $this->discountName('stored'), 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 12.5]);

        $row = $this->discounts->getById($result['id']);

        $this->assertSame([$this->discountName('stored'), 'sitewide', 'percent', 12.5], [$row['name'], $row['type'], $row['value_type'], (float) $row['value']]);
        $this->assertNull($row['code']);
        $this->assertNull($row['starts_at']);
        $this->assertNull($row['ends_at']);
    }

    public function test_a_coupon_stores_an_upper_case_code_its_limits_and_its_minimum_spend(): void
    {
        $result = $this->create([
            'name' => $this->discountName('coupon'), 'type' => 'coupon', 'code' => \strtolower($this->couponCode('lower')), 'value_type' => 'fixed', 'value' => 5,
            'usage_limit' => 100, 'usage_limit_per_customer' => 2, 'min_spend_inc_tax' => 49.99,
        ]);

        $row = $this->discounts->getById($result['id']);

        $this->assertSame($this->couponCode('LOWER'), $row['code']);
        $this->assertSame([100, 2], [(int) $row['usage_limit'], (int) $row['usage_limit_per_customer']]);
        $this->assertEqualsWithDelta(49.99, (float) $row['min_spend_inc_tax'], 0.001);
    }

    public function test_a_free_delivery_coupon_takes_no_value(): void
    {
        $result = $this->create(['name' => $this->discountName('free'), 'type' => 'coupon', 'code' => $this->couponCode('FREE'), 'value_type' => 'free_delivery']);

        $this->assertSame('free_delivery', $this->discounts->getById($result['id'])['value_type']);
    }

    public function test_dates_alone_mean_the_start_and_the_end_of_that_day(): void
    {
        $result = $this->sitewide('dates', ['starts_at' => '2030-01-10', 'ends_at' => '2030-01-20']);

        $row = $this->discounts->getById($result['id']);

        $this->assertSame('2030-01-10 00:00:00', $row['starts_at']);
        $this->assertSame('2030-01-20 23:59:59', $row['ends_at']);
    }

    public function test_a_product_discount_covers_exactly_the_products_given(): void
    {
        $one = $this->product();
        $two = $this->product();

        $result = $this->create(['name' => $this->discountName('products'), 'type' => 'product', 'value_type' => 'percent', 'value' => 20, 'product_ids' => [$two, $one, $two]]);

        $this->assertEqualsCanonicalizing([$one, $two], \array_map('intval', $this->discounts->getProductIds($result['id'])));
    }

    public function test_creating_a_discount_is_logged_once_under_the_caller(): void
    {
        $result = $this->sitewide('logged');

        $entries = $this->changelog($result['id']);

        $this->assertCount(1, $entries);
        $this->assertSame('Created discount: ' . $this->discountName('logged'), $entries[0]['description']);
        $this->assertStringContainsString('tester', (string) $entries[0]['user_name']);
    }

    public function test_a_code_already_used_by_a_live_or_a_binned_discount_is_refused_and_nothing_is_added(): void
    {
        $live = $this->coupon('LIVE');
        $binned = $this->coupon('BIN');
        DiscountMcp::delete($this->call(['id' => $binned['id'], 'expected_name' => $binned['name']]));
        $before = $this->discountRows();

        $this->refused(fn() => $this->create(['name' => $this->discountName('dup1'), 'type' => 'coupon', 'code' => $this->couponCode('LIVE'), 'value_type' => 'percent', 'value' => 5]), 'already in use');
        $this->refused(fn() => $this->create(['name' => $this->discountName('dup2'), 'type' => 'coupon', 'code' => $this->couponCode('BIN'), 'value_type' => 'percent', 'value' => 5]), 'already in use');

        $this->assertSame($before, $this->discountRows());
        $this->assertNotNull($live['id']);
    }

    public function test_discounts_that_break_a_rule_are_refused_and_nothing_is_added(): void
    {
        $before = $this->discountRows();

        $this->refused(fn() => $this->sitewide('over', ['value' => 150]), 'cannot exceed 100%');
        $this->refused(fn() => $this->create(['name' => $this->discountName('nopro'), 'type' => 'product', 'value_type' => 'percent', 'value' => 5, 'product_ids' => [987654321]]), 'No live product');
        $this->refused(fn() => $this->create(['name' => $this->discountName('badtype'), 'type' => 'everything', 'value_type' => 'percent', 'value' => 5]), 'type must be');
        $this->refused(fn() => $this->sitewide('code', ['code' => 'NOPE']), 'only applies to a coupon');
        $this->refused(fn() => $this->sitewide('dates', ['starts_at' => '2030-02-01', 'ends_at' => '2030-01-01']), 'End date must be after');

        $this->assertSame($before, $this->discountRows());
    }

    // ---- update -------------------------------------------------------------------------------------------

    public function test_an_edit_changes_only_what_was_asked_and_says_what_it_was(): void
    {
        $made = $this->coupon('EDIT', ['usage_limit' => 50, 'min_spend_inc_tax' => 30]);

        $result = DiscountMcp::update($this->call(['id' => $made['id'], 'expected_name' => $made['name'], 'value' => 25]));

        $row = $this->discounts->getById($made['id']);
        $this->assertSame(['value'], $result['changed']);
        $this->assertEquals(10.0, $result['was']['value']);
        $this->assertEqualsWithDelta(25.0, (float) $row['value'], 0.001);
        $this->assertSame(50, (int) $row['usage_limit']);
        $this->assertEqualsWithDelta(30.0, (float) $row['min_spend_inc_tax'], 0.001);
        $this->assertSame($this->couponCode('EDIT'), $row['code']);
        $this->assertSame('coupon', $row['type']);
        $this->assertSame(0, (int) $row['active']);
        $this->assertSame('disabled', $result['status']);
    }

    public function test_an_edit_cannot_change_the_type_or_switch_a_discount_on(): void
    {
        $made = $this->sitewide('locked');
        $base = ['id' => $made['id'], 'expected_name' => $made['name']];

        $this->refused(fn() => DiscountMcp::update($this->call($base + ['type' => 'coupon'])), 'cannot be changed');
        $this->refused(fn() => DiscountMcp::update($this->call($base + ['active' => 1])), 'cannot be changed');

        $row = $this->discounts->getById($made['id']);
        $this->assertSame(['sitewide', 0], [$row['type'], (int) $row['active']]);
    }

    public function test_a_switched_on_discount_cannot_be_edited_and_stays_exactly_as_it_was(): void
    {
        $made = $this->sitewide('live-edit');
        $this->switchOn($made['id']);

        $this->refused(fn() => DiscountMcp::update($this->call(['id' => $made['id'], 'expected_name' => $made['name'], 'value' => 90])), 'switched on');

        $row = $this->discounts->getById($made['id']);
        $this->assertEqualsWithDelta(10.0, (float) $row['value'], 0.001);
        $this->assertSame(1, (int) $row['active']);
        $this->assertCount(1, $this->changelog($made['id']));
    }

    public function test_fields_can_be_cleared_but_not_both_set_and_cleared(): void
    {
        $made = $this->coupon('CLEAR', ['usage_limit' => 5, 'ends_at' => '2030-06-30']);
        $base = ['id' => $made['id'], 'expected_name' => $made['name']];

        $this->refused(fn() => DiscountMcp::update($this->call($base + ['usage_limit' => 9, 'clear' => ['usage_limit']])), 'both given and cleared');

        $result = DiscountMcp::update($this->call($base + ['clear' => ['usage_limit', 'ends_at']]));

        $row = $this->discounts->getById($made['id']);
        $this->assertNull($row['usage_limit']);
        $this->assertNull($row['ends_at']);
        $this->assertEqualsCanonicalizing(['usage_limit', 'ends_at'], $result['changed']);
    }

    public function test_an_edit_with_nothing_to_change_or_nothing_new_is_refused(): void
    {
        $made = $this->sitewide('same');
        $base = ['id' => $made['id'], 'expected_name' => $made['name']];

        $this->refused(fn() => DiscountMcp::update($this->call($base)), 'at least one thing');
        $this->refused(fn() => DiscountMcp::update($this->call($base + ['value' => 10])), 'Nothing would change');
    }

    public function test_an_edit_cannot_take_a_code_another_discount_has_but_may_keep_its_own(): void
    {
        $this->coupon('TAKEN');
        $mine = $this->coupon('MINE');
        $base = ['id' => $mine['id'], 'expected_name' => $mine['name']];

        $this->refused(fn() => DiscountMcp::update($this->call($base + ['code' => $this->couponCode('TAKEN')])), 'already in use');
        $result = DiscountMcp::update($this->call($base + ['code' => $this->couponCode('mine'), 'value' => 11]));

        $this->assertSame(['value'], $result['changed']);
        $this->assertSame($this->couponCode('MINE'), $this->discounts->getById($mine['id'])['code']);
    }

    public function test_the_products_of_a_product_discount_can_be_replaced(): void
    {
        $a = $this->product();
        $b = $this->product();
        $made = $this->create(['name' => $this->discountName('swap'), 'type' => 'product', 'value_type' => 'percent', 'value' => 10, 'product_ids' => [$a]]);

        DiscountMcp::update($this->call(['id' => $made['id'], 'expected_name' => $made['name'], 'product_ids' => [$b]]));

        $this->assertSame([$b], \array_map('intval', $this->discounts->getProductIds($made['id'])));
    }

    public function test_an_edit_with_the_wrong_name_or_on_a_binned_discount_is_refused(): void
    {
        $made = $this->sitewide('guard-edit');
        $binned = $this->sitewide('binned-edit');
        DiscountMcp::delete($this->call(['id' => $binned['id'], 'expected_name' => $binned['name']]));

        $this->refused(fn() => DiscountMcp::update($this->call(['id' => $made['id'], 'expected_name' => 'Wrong', 'value' => 20])), 'does not match');
        $this->refused(fn() => DiscountMcp::update($this->call(['id' => $binned['id'], 'expected_name' => $binned['name'], 'value' => 20])), 'No matching discount');

        $this->assertEqualsWithDelta(10.0, (float) $this->discounts->getById($made['id'])['value'], 0.001);
    }

    public function test_an_edit_is_logged(): void
    {
        $made = $this->sitewide('edit-log');

        DiscountMcp::update($this->call(['id' => $made['id'], 'expected_name' => $made['name'], 'value' => 15]));

        $entries = $this->changelog($made['id']);
        $this->assertCount(2, $entries);
        $this->assertSame('Updated discount: ' . $made['name'], $entries[1]['description']);
    }

    // ---- delete and restore -------------------------------------------------------------------------------

    public function test_delete_moves_a_discount_to_the_bin_and_restore_brings_it_back_unchanged(): void
    {
        $made = $this->coupon('BINROUND', ['usage_limit' => 7]);

        DiscountMcp::delete($this->call(['id' => $made['id'], 'expected_name' => $made['name']]));
        $binned = $this->discounts->getById($made['id']);
        $this->assertNotNull($binned['deleted_at']);

        $restored = DiscountMcp::restore($this->call(['id' => $made['id'], 'expected_name' => $made['name']]));
        $row = $this->discounts->getById($made['id']);

        $this->assertTrue($restored['restored']);
        $this->assertNull($row['deleted_at']);
        $this->assertSame([$this->couponCode('BINROUND'), 7, 0], [$row['code'], (int) $row['usage_limit'], (int) $row['active']]);
        $this->assertCount(3, $this->changelog($made['id']));
    }

    public function test_delete_and_restore_never_switch_a_discount_on_or_off(): void
    {
        $off = $this->sitewide('stays-off');
        $on = $this->sitewide('stays-on');
        $this->switchOn($on['id']);

        foreach ([$off, $on] as $discount) {
            DiscountMcp::delete($this->call(['id' => $discount['id'], 'expected_name' => $discount['name']]));
            DiscountMcp::restore($this->call(['id' => $discount['id'], 'expected_name' => $discount['name']]));
        }

        $this->assertSame(0, (int) $this->discounts->getById($off['id'])['active']);
        $this->assertSame(1, (int) $this->discounts->getById($on['id'])['active']);
    }

    public function test_a_deleted_discount_no_longer_counts_as_live_and_its_code_cannot_be_reused(): void
    {
        $made = $this->coupon('GONE');
        $this->switchOn($made['id']);
        $this->assertSame($made['id'], $this->discounts->findIdByCode($this->couponCode('GONE')));

        DiscountMcp::delete($this->call(['id' => $made['id'], 'expected_name' => $made['name']]));

        $this->assertNull($this->discounts->findIdByCode($this->couponCode('GONE')));
        $this->assertTrue($this->discounts->codeExistsAnywhere($this->couponCode('GONE')));
    }

    public function test_delete_and_restore_refuse_a_wrong_name_and_a_discount_in_the_wrong_state(): void
    {
        $live = $this->sitewide('state-live');
        $binned = $this->sitewide('state-binned');
        DiscountMcp::delete($this->call(['id' => $binned['id'], 'expected_name' => $binned['name']]));

        $this->refused(fn() => DiscountMcp::delete($this->call(['id' => $live['id'], 'expected_name' => 'Wrong'])), 'does not match');
        $this->refused(fn() => DiscountMcp::delete($this->call(['id' => $binned['id'], 'expected_name' => $binned['name']])), 'No matching discount');
        $this->refused(fn() => DiscountMcp::restore($this->call(['id' => $live['id'], 'expected_name' => $live['name']])), 'No matching discount');
        $this->refused(fn() => DiscountMcp::restore($this->call(['id' => $binned['id'], 'expected_name' => 'Wrong'])), 'does not match');
        $this->refused(fn() => DiscountMcp::delete($this->call(['id' => 987654321, 'expected_name' => 'Anything'])), 'No matching discount');

        $this->assertNull($this->discounts->getById($live['id'])['deleted_at']);
        $this->assertNotNull($this->discounts->getById($binned['id'])['deleted_at']);
    }

    public function test_a_product_discount_keeps_its_products_through_the_bin(): void
    {
        $product = $this->product();
        $made = $this->create(['name' => $this->discountName('keeps'), 'type' => 'product', 'value_type' => 'percent', 'value' => 10, 'product_ids' => [$product]]);

        DiscountMcp::delete($this->call(['id' => $made['id'], 'expected_name' => $made['name']]));
        DiscountMcp::restore($this->call(['id' => $made['id'], 'expected_name' => $made['name']]));

        $this->assertSame([$product], \array_map('intval', $this->discounts->getProductIds($made['id'])));
    }
}
