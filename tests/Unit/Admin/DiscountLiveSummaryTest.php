<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\DiscountListView;
use Osmium\Modules\Admin\Services\DiscountLiveSummary;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/DiscountListView.php';
require_once 'app/services/osmium-shop/models/DiscountLiveSummary.php';

/**
 * DiscountLiveSummary states what the basket would apply right now, with the basket's precedence.
 */
class DiscountLiveSummaryTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return DiscountListView::row($overrides + [
            'id' => 1, 'name' => 'Sale', 'type' => 'sitewide', 'code' => null, 'value_type' => 'percent',
            'value' => '10.00', 'starts_at' => null, 'ends_at' => null, 'usage_limit' => null, 'times_used' => 0,
            'active' => 1, 'product_count' => 0, 'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_nothing_live_when_there_are_no_discounts_or_all_are_off(): void
    {
        $this->assertFalse(DiscountLiveSummary::build([])['hasAny']);
        $this->assertFalse(DiscountLiveSummary::build([$this->row(['active' => 0])])['hasAny']);
    }

    public function test_scheduled_expired_and_used_up_discounts_are_not_live(): void
    {
        $rows = [
            $this->row(['starts_at' => '2999-01-01 00:00:00']),
            $this->row(['ends_at' => '2000-01-01 00:00:00']),
            $this->row(['type' => 'coupon', 'code' => 'GONE', 'usage_limit' => 2, 'times_used' => 2]),
        ];

        $this->assertFalse(DiscountLiveSummary::build($rows)['hasAny']);
    }

    public function test_a_coupon_with_uses_left_is_live(): void
    {
        $summary = DiscountLiveSummary::build([$this->row(['type' => 'coupon', 'code' => 'HI', 'usage_limit' => 2, 'times_used' => 1])]);

        $this->assertSame(['HI'], \array_column($summary['coupons'], 'code'));
    }

    public function test_the_biggest_sitewide_discount_is_the_one_that_applies(): void
    {
        $summary = DiscountLiveSummary::build([
            $this->row(['id' => 1, 'name' => 'Small', 'value' => '5.00']),
            $this->row(['id' => 2, 'name' => 'Big', 'value' => '20.00']),
        ]);

        $this->assertSame('Big', $summary['sitewide']['name']);
        $this->assertSame(1, $summary['shadowedSitewide']);
    }

    public function test_product_and_coupon_discounts_are_listed(): void
    {
        $summary = DiscountLiveSummary::build([
            $this->row(['type' => 'product', 'name' => 'Panels', 'product_count' => 3]),
            $this->row(['type' => 'coupon', 'name' => 'Welcome', 'code' => 'WELCOME']),
        ]);

        $this->assertNull($summary['sitewide']);
        $this->assertSame(['Panels'], \array_column($summary['products'], 'name'));
        $this->assertSame(['3 products'], \array_column($summary['products'], 'scopeLabel'));
        $this->assertTrue($summary['couponsReplaceProducts']);
    }
}
