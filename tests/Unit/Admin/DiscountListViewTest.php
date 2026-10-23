<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\DiscountListView;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/DiscountListView.php';

/**
 * DiscountListView prepares the admin discounts list's labels and badges.
 */
class DiscountListViewTest extends TestCase
{
    private function discount(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1, 'name' => 'Sale', 'type' => 'sitewide', 'code' => null, 'value_type' => 'percent',
            'value' => '10.00', 'starts_at' => null, 'ends_at' => null, 'usage_limit' => null, 'times_used' => 0,
            'active' => 1, 'product_count' => 0, 'created_at' => '2026-01-01 00:00:00',
        ];
    }

    public function test_type_labels(): void
    {
        $this->assertSame('Sitewide', DiscountListView::row($this->discount())['typeLabel']);
        $this->assertSame('Per-Product', DiscountListView::row($this->discount(['type' => 'product']))['typeLabel']);
        $this->assertSame('Coupon', DiscountListView::row($this->discount(['type' => 'coupon']))['typeLabel']);
        $this->assertSame('Other', DiscountListView::row($this->discount(['type' => 'other']))['typeLabel']);
    }

    public function test_value_labels(): void
    {
        $this->assertSame('10%', DiscountListView::row($this->discount())['valueLabel']);
        $this->assertSame('12.5%', DiscountListView::row($this->discount(['value' => '12.50']))['valueLabel']);
        $fixed = $this->discount(['value_type' => 'fixed', 'value' => '5']);
        $this->assertSame('£5.00', DiscountListView::row($fixed)['valueLabel']);
        $freeDelivery = $this->discount(['value' => '10.00', 'free_delivery' => 1]);
        $this->assertSame('10% + free delivery', DiscountListView::row($freeDelivery)['valueLabel']);
    }

    public function test_date_window_labels(): void
    {
        $this->assertSame('Always on', DiscountListView::row($this->discount())['windowLabel']);
        $open = $this->discount(['starts_at' => '2026-03-04 00:00:00']);
        $this->assertSame("04 Mar 2026 \u{2192} ongoing", DiscountListView::row($open)['windowLabel']);
        $ends = $this->discount(['ends_at' => '2026-03-04 00:00:00']);
        $this->assertSame("now \u{2192} 04 Mar 2026", DiscountListView::row($ends)['windowLabel']);
    }

    public function test_usage_label_is_only_for_coupons(): void
    {
        $this->assertNull(DiscountListView::row($this->discount())['usageLabel']);
        $unlimited = $this->discount(['type' => 'coupon', 'times_used' => 3]);
        $this->assertSame('3 used', DiscountListView::row($unlimited)['usageLabel']);
        $limited = $this->discount(['type' => 'coupon', 'times_used' => 3, 'usage_limit' => 10]);
        $this->assertSame('3 / 10 used', DiscountListView::row($limited)['usageLabel']);
    }

    public function test_the_status_pill_is_the_on_off_switch(): void
    {
        $live = DiscountListView::row($this->discount())['statusBadge'];
        $off = DiscountListView::row($this->discount(['active' => 0]))['statusBadge'];

        $this->assertStringContainsString('data-list-action="toggle_active"', $live);
        $this->assertStringContainsString('Click to disable', $live);
        $this->assertStringContainsString('Click to enable', $off);
    }

    public function test_status_badges(): void
    {
        $badge = fn(array $o): string => DiscountListView::row($this->discount($o))['statusBadge'];

        $this->assertStringContainsString('Disabled', $badge(['active' => 0]));
        $this->assertStringContainsString('Live', $badge([]));
        $this->assertStringContainsString('Scheduled', $badge(['starts_at' => '2999-01-01 00:00:00']));
        $this->assertStringContainsString('Expired', $badge(['ends_at' => '2000-01-01 00:00:00']));
    }
}
