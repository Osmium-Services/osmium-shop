<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Models\Discount;
use Osmium\Modules\Admin\Services\DiscountMcp;
use Tests\Helpers\ShopDatabaseTestCase;

/** discounts_list against the real tables: live rows with product counts, and the bin. */
class DiscountMcpListTest extends ShopDatabaseTestCase
{
    private function fields(string $name, string $type, ?string $code = null): array
    {
        return [
            'name' => $name, 'type' => $type, 'code' => $code, 'value_type' => 'percent', 'value' => 15,
            'starts_at' => null, 'ends_at' => null, 'usage_limit' => 5, 'usage_limit_per_customer' => null, 'active' => 1,
        ];
    }

    private function listed(bool $deleted = false): array
    {
        $result = DiscountMcp::discountsList(['dataSource' => $this->db, 'arguments' => ['deleted' => $deleted]]);

        return \array_column($result['discounts'], null, 'name');
    }

    public function test_a_live_discount_is_listed_with_its_status_and_usage(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $discounts->insert($this->fields('zz-mcp-list-coupon', 'coupon', 'ZZMCPLIST'));

        $entry = $this->listed()['zz-mcp-list-coupon'];

        $this->assertSame('ZZMCPLIST', $entry['code']);
        $this->assertSame('15%', $entry['value']);
        $this->assertSame('live', $entry['status']);
        $this->assertSame(0, $entry['times_used']);
        $this->assertSame(5, $entry['usage_limit']);
    }

    public function test_a_binned_discount_moves_from_the_live_list_to_the_bin(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $discounts->insert($this->fields('zz-mcp-list-binned', 'sitewide'));
        $discounts->softDelete($id);

        $this->assertArrayNotHasKey('zz-mcp-list-binned', $this->listed());
        $this->assertArrayHasKey('deleted_at', $this->listed(deleted: true)['zz-mcp-list-binned']);
    }

    private function call(array $arguments): array
    {
        return ['dataSource' => $this->db, 'arguments' => $arguments, 'actor' => 'Claude via MCP'];
    }

    private function changelogCount(int $id): int
    {
        $prefix = $this->db->tablePrefix();

        return (int) $this->db->prepare(
            sql: "SELECT COUNT(*) AS n FROM {$prefix}changelog WHERE record_type = 'discount' AND record_id = :id",
            bindings: [':id' => $id],
        )->single()['n'];
    }

    public function test_delete_bins_a_live_discount_and_restore_brings_it_back(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $discounts->insert($this->fields('zz-mcp-del-roundtrip', 'coupon', 'ZZMCPDEL'));

        $deleted = DiscountMcp::delete($this->call(['id' => $id, 'expected_name' => 'zz-mcp-del-roundtrip']));
        $this->assertTrue($deleted['deleted']);
        $this->assertNotNull($discounts->getById($id)['deleted_at']);
        $this->assertSame(1, $this->changelogCount($id));

        $restored = DiscountMcp::restore($this->call(['id' => $id, 'expected_name' => 'zz-mcp-del-roundtrip']));
        $this->assertTrue($restored['restored']);
        $this->assertSame('live', $restored['status']);
        $this->assertNull($discounts->getById($id)['deleted_at']);
        $this->assertSame(2, $this->changelogCount($id));
    }

    public function test_a_wrong_name_changes_nothing(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $discounts->insert($this->fields('zz-mcp-del-name', 'sitewide'));

        $this->expectException(McpToolException::class);
        try {
            DiscountMcp::delete($this->call(['id' => $id, 'expected_name' => 'something else']));
        } finally {
            $this->assertNull($discounts->getById($id)['deleted_at']);
            $this->assertSame(0, $this->changelogCount($id));
        }
    }

    public function test_delete_refuses_a_discount_already_in_the_bin_and_restore_refuses_a_live_one(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $live = $discounts->insert($this->fields('zz-mcp-del-live', 'sitewide'));
        $binned = $discounts->insert($this->fields('zz-mcp-del-binned', 'sitewide'));
        $discounts->softDelete($binned);

        foreach ([
            fn() => DiscountMcp::restore($this->call(['id' => $live, 'expected_name' => 'zz-mcp-del-live'])),
            fn() => DiscountMcp::delete($this->call(['id' => $binned, 'expected_name' => 'zz-mcp-del-binned'])),
            fn() => DiscountMcp::delete($this->call(['id' => 999999999, 'expected_name' => 'nothing'])),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a refusal');
            } catch (McpToolException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $this->changelogCount($live) + $this->changelogCount($binned));
    }

    public function test_restoring_a_switched_off_discount_says_it_is_still_disabled(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $discounts->insert(['active' => 0] + $this->fields('zz-mcp-del-off', 'sitewide'));
        $discounts->softDelete($id);

        $restored = DiscountMcp::restore($this->call(['id' => $id, 'expected_name' => 'zz-mcp-del-off']));

        $this->assertSame('disabled', $restored['status']);
    }

    private function created(array $arguments): array
    {
        return DiscountMcp::create($this->call($arguments));
    }

    public function test_a_created_discount_is_always_switched_off_and_logged(): void
    {
        $discounts = new Discount($this->db, 'discounts');

        $result = $this->created(['name' => 'zz-create-sale', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 15]);

        $row = $discounts->getById($result['id']);
        $this->assertTrue($result['created']);
        $this->assertSame('disabled', $result['status']);
        $this->assertSame(0, (int) $row['active']);
        $this->assertSame('sitewide', $row['type']);
        $this->assertSame('15.00', $row['value']);
        $this->assertSame(1, $this->changelogCount($result['id']));
        $this->assertStringContainsString('Enable it in the admin', $result['note']);
    }

    public function test_a_created_coupon_keeps_its_code_limits_dates_and_minimum_spend(): void
    {
        $discounts = new Discount($this->db, 'discounts');

        $result = $this->created([
            'name' => 'zz-create-coupon', 'type' => 'coupon', 'value_type' => 'fixed', 'value' => 5, 'code' => 'zzcreate5',
            'usage_limit' => 50, 'usage_limit_per_customer' => 1, 'min_spend_inc_tax' => 40,
            'starts_at' => '2027-01-01', 'ends_at' => '2027-01-31',
        ]);

        $row = $discounts->getById($result['id']);
        $this->assertSame('ZZCREATE5', $row['code']);
        $this->assertSame('fixed', $row['value_type']);
        $this->assertSame(50, (int) $row['usage_limit']);
        $this->assertSame(1, (int) $row['usage_limit_per_customer']);
        $this->assertSame('40.00', $row['min_spend_inc_tax']);
        $this->assertSame('2027-01-01 00:00:00', $row['starts_at']);
        $this->assertSame('2027-01-31 23:59:59', $row['ends_at']);
        $this->assertSame(0, (int) $row['active']);
    }

    public function test_a_created_coupon_can_have_free_delivery_switched_on(): void
    {
        $discounts = new Discount($this->db, 'discounts');

        $result = $this->created(['name' => 'zz-create-fd', 'type' => 'coupon', 'value_type' => 'percent', 'value' => 10, 'free_delivery' => true, 'code' => 'ZZCREATEFD']);

        $row = $discounts->getById($result['id']);
        $this->assertSame('percent', $row['value_type']);
        $this->assertSame(1, (int) $row['free_delivery']);
    }

    public function test_a_created_product_discount_covers_the_products_given_and_names_them(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $productId = $this->createTestProduct(['model_name' => 'zz-create-product']);

        $result = $this->created(['name' => 'zz-create-product-deal', 'type' => 'product', 'value_type' => 'percent', 'value' => 10, 'product_ids' => [$productId]]);

        $this->assertSame([$productId], \array_map('intval', $discounts->getProductIds($result['id'])));
        $this->assertContains('zz-create-product', $result['products']);
    }

    public function test_a_rule_the_admin_form_has_is_enforced_and_nothing_is_saved(): void
    {
        $discounts = new Discount($this->db, 'discounts');

        foreach ([
            ['name' => 'zz-bad-1', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 150],
            ['name' => 'zz-bad-2', 'type' => 'sitewide', 'value_type' => 'free_delivery', 'value' => 5],
            ['name' => 'zz-bad-3', 'type' => 'sitewide', 'value_type' => 'fixed', 'value' => 0],
            ['name' => 'zz-bad-4', 'type' => 'sitewide', 'value_type' => 'percent', 'value' => 10, 'starts_at' => '2027-02-01', 'ends_at' => '2027-01-01'],
            ['name' => 'zz-bad-5', 'type' => 'coupon', 'value_type' => 'percent', 'value' => 10, 'code' => 'bad code!'],
        ] as $arguments) {
            try {
                $this->created($arguments);
                $this->fail('Expected a refusal for ' . $arguments['name']);
            } catch (McpToolException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(0, \array_filter($this->listed(), fn(array $d): bool => \str_starts_with($d['name'], 'zz-bad-')));
        unset($discounts);
    }

    public function test_a_code_already_taken_is_refused_including_one_in_the_bin(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $first = $this->created(['name' => 'zz-dup-first', 'type' => 'coupon', 'value_type' => 'percent', 'value' => 5, 'code' => 'ZZDUP']);

        foreach (['live', 'binned'] as $state) {
            if ($state === 'binned') $discounts->softDelete($first['id']);
            try {
                $this->created(['name' => "zz-dup-{$state}", 'type' => 'coupon', 'value_type' => 'percent', 'value' => 5, 'code' => 'zzdup']);
                $this->fail("Expected the {$state} code to be refused");
            } catch (McpToolException $e) {
                $this->assertSame('That coupon code is already in use', $e->getMessage());
            }
        }
    }

    private function updated(array $arguments): array
    {
        return DiscountMcp::update($this->call($arguments));
    }

    private function refusedUpdate(array $arguments): string
    {
        try {
            $this->updated($arguments);
        } catch (McpToolException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected a refusal for ' . \json_encode($arguments));
    }

    private function switchedOffCoupon(string $name, string $code, array $extra = []): int
    {
        return $this->created(['name' => $name, 'type' => 'coupon', 'value_type' => 'percent', 'value' => 10, 'code' => $code] + $extra)['id'];
    }

    public function test_a_switched_off_discount_can_be_changed_and_stays_off_with_the_old_values_reported(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $this->switchedOffCoupon('zz-upd-coupon', 'ZZUPD1', ['min_spend_inc_tax' => 30, 'usage_limit' => 10]);

        $result = $this->updated(['id' => $id, 'expected_name' => 'zz-upd-coupon', 'value' => 15, 'min_spend_inc_tax' => 45.5, 'code' => 'zzupd1b']);

        $row = $discounts->getById($id);
        $this->assertTrue($result['updated']);
        $this->assertSame('disabled', $result['status']);
        $this->assertEqualsCanonicalizing(['value', 'min_spend_inc_tax', 'code'], $result['changed']);
        $this->assertSame(10.0, $result['was']['value']);
        $this->assertSame(30.0, $result['was']['min_spend_inc_tax']);
        $this->assertSame('ZZUPD1', $result['was']['code']);
        $this->assertSame('15.00', $row['value']);
        $this->assertSame('45.50', $row['min_spend_inc_tax']);
        $this->assertSame('ZZUPD1B', $row['code']);
        $this->assertSame(10, (int) $row['usage_limit']); // Untouched
        $this->assertSame(0, (int) $row['active']);
        $this->assertSame(2, $this->changelogCount($id)); // Created, then updated
    }

    public function test_a_switched_on_discount_cannot_be_edited(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $discounts->insert($this->fields('zz-upd-live', 'sitewide'));

        $this->assertStringContainsString('switched on', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-live', 'value' => 50]));
        $this->assertSame('15.00', $discounts->getById($id)['value']);
    }

    public function test_the_wrong_name_a_binned_or_missing_discount_is_refused(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $this->switchedOffCoupon('zz-upd-guard', 'ZZUPDG');

        $this->assertStringContainsString('does not match', $this->refusedUpdate(['id' => $id, 'expected_name' => 'other', 'value' => 20]));
        $this->assertStringContainsString('No matching discount', $this->refusedUpdate(['id' => 999999999, 'expected_name' => 'x', 'value' => 20]));
        $discounts->softDelete($id);
        $this->assertStringContainsString('No matching discount', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-guard', 'value' => 20]));
    }

    public function test_fields_that_cannot_be_changed_here_are_refused_by_name(): void
    {
        $id = $this->switchedOffCoupon('zz-upd-fields', 'ZZUPDF');

        foreach (['type' => 'sitewide', 'active' => true, 'deleted_at' => '2026-01-01'] as $field => $value) {
            $this->assertStringContainsString($field, $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-fields', $field => $value]));
        }
    }

    public function test_it_needs_something_to_change_and_refuses_a_no_op(): void
    {
        $id = $this->switchedOffCoupon('zz-upd-noop', 'ZZUPDN');

        $this->assertStringContainsString('at least one', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-noop']));
        $this->assertStringContainsString('Nothing would change', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-noop', 'value' => 10, 'name' => 'zz-upd-noop']));
    }

    public function test_optional_fields_are_removed_with_clear_and_cannot_be_both_given_and_cleared(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $this->switchedOffCoupon('zz-upd-clear', 'ZZUPDC', ['min_spend_inc_tax' => 30, 'usage_limit' => 10, 'ends_at' => '2027-05-31']);

        $this->updated(['id' => $id, 'expected_name' => 'zz-upd-clear', 'clear' => ['min_spend_inc_tax', 'ends_at']]);

        $row = $discounts->getById($id);
        $this->assertNull($row['min_spend_inc_tax']);
        $this->assertNull($row['ends_at']);
        $this->assertSame(10, (int) $row['usage_limit']);
        $this->assertStringContainsString('both given and cleared', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-clear', 'usage_limit' => 5, 'clear' => ['usage_limit']]));
        $this->assertStringContainsString('clear can hold', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-clear', 'clear' => ['name']]));
    }

    public function test_free_delivery_can_be_switched_on_and_off_on_an_edit(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $this->switchedOffCoupon('zz-upd-fd', 'ZZUPDFD');
        $this->assertSame(0, (int) $discounts->getById($id)['free_delivery']);

        $this->updated(['id' => $id, 'expected_name' => 'zz-upd-fd', 'free_delivery' => true]);
        $this->assertSame(1, (int) $discounts->getById($id)['free_delivery']);
        $this->assertSame('10.00', $discounts->getById($id)['value']);

        $this->updated(['id' => $id, 'expected_name' => 'zz-upd-fd', 'free_delivery' => false]);
        $this->assertSame(0, (int) $discounts->getById($id)['free_delivery']);
    }

    public function test_the_admin_rules_apply_to_an_edit_and_nothing_is_saved_when_one_fails(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $this->switchedOffCoupon('zz-upd-rules', 'ZZUPDR');

        foreach ([['value' => 150], ['value' => 0], ['ends_at' => '2000-01-01', 'starts_at' => '2001-01-01'], ['code' => 'bad code!'], ['name' => '']] as $change) {
            $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-rules'] + $change);
        }

        $row = $discounts->getById($id);
        $this->assertSame('10.00', $row['value']);
        $this->assertSame('zz-upd-rules', $row['name']);
    }

    public function test_a_code_taken_elsewhere_is_refused_but_keeping_your_own_is_fine(): void
    {
        $this->switchedOffCoupon('zz-upd-other', 'ZZUPDOTHER');
        $id = $this->switchedOffCoupon('zz-upd-mine', 'ZZUPDMINE');

        $this->assertStringContainsString('already in use', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-mine', 'code' => 'zzupdother']));
        $result = $this->updated(['id' => $id, 'expected_name' => 'zz-upd-mine', 'code' => 'zzupdmine', 'name' => 'zz-upd-renamed']);
        $this->assertSame(['name'], $result['changed']); // The same code in other letters is no change
    }

    public function test_dates_survive_an_edit_that_does_not_touch_them(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $id = $this->switchedOffCoupon('zz-upd-dates', 'ZZUPDD', ['starts_at' => '2027-04-01', 'ends_at' => '2027-04-30']);

        $result = $this->updated(['id' => $id, 'expected_name' => 'zz-upd-dates', 'name' => 'zz-upd-dates-2']);

        $row = $discounts->getById($id);
        $this->assertSame(['name'], $result['changed']);
        $this->assertSame('2027-04-01 00:00:00', $row['starts_at']);
        $this->assertSame('2027-04-30 23:59:59', $row['ends_at']); // Still the end of the day, not 23:59:00
    }

    public function test_a_product_discount_can_have_its_products_replaced(): void
    {
        $discounts = new Discount($this->db, 'discounts');
        $first = $this->createTestProduct(['model_name' => 'zz-upd-prod-1']);
        $second = $this->createTestProduct(['model_name' => 'zz-upd-prod-2']);
        $id = $this->created(['name' => 'zz-upd-products', 'type' => 'product', 'value_type' => 'percent', 'value' => 5, 'product_ids' => [$first]])['id'];

        $result = $this->updated(['id' => $id, 'expected_name' => 'zz-upd-products', 'product_ids' => [$second, $first]]);
        $this->assertSame(['product_ids'], $result['changed']);
        $sorted = \array_map('intval', $discounts->getProductIds($id));
        \sort($sorted);
        $this->assertSame([$first, $second], $sorted);

        $same = $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-products', 'product_ids' => [$first, $second]]);
        $this->assertStringContainsString('Nothing would change', $same);
        $this->assertStringContainsString('No live product', $this->refusedUpdate(['id' => $id, 'expected_name' => 'zz-upd-products', 'product_ids' => [999999999]]));
    }
}
