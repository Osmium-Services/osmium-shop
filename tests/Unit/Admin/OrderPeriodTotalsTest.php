<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Models;

use Osmium\Modules\Admin\Models\Order;
use PHPUnit\Framework\TestCase;

final class OrderPeriodTotalsTest extends TestCase
{
    public function test_it_groups_each_status_under_its_periods(): void
    {
        $rows = [[
            'status' => 'paid',
            'today_count' => '1', 'today_value' => '10.50',
            'week_count' => '3', 'week_value' => '40.00',
            'month_count' => '5', 'month_value' => '99.99',
        ]];

        $totals = Order::shapePeriodTotals($rows);

        $this->assertSame(['count' => 1, 'value' => 10.5], $totals['today']['paid']);
        $this->assertSame(['count' => 3, 'value' => 40.0], $totals['last_7_days']['paid']);
        $this->assertSame(['count' => 5, 'value' => 99.99], $totals['last_30_days']['paid']);
    }

    public function test_it_leaves_out_a_status_with_no_orders_in_a_period(): void
    {
        $rows = [[
            'status' => 'cancelled',
            'today_count' => '0', 'today_value' => '0',
            'week_count' => '0', 'week_value' => '0',
            'month_count' => '2', 'month_value' => '20.00',
        ]];

        $totals = Order::shapePeriodTotals($rows);

        $this->assertSame([], $totals['today']);
        $this->assertSame([], $totals['last_7_days']);
        $this->assertArrayHasKey('cancelled', $totals['last_30_days']);
    }

    public function test_no_rows_gives_three_empty_periods(): void
    {
        $this->assertSame(['today' => [], 'last_7_days' => [], 'last_30_days' => []], Order::shapePeriodTotals([]));
    }
}
