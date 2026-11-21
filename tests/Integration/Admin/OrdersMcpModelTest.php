<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Order;
use Tests\Integration\DatabaseTestCase;

/**
 * The order queries behind orders_summary, run against the real table: test payments never count, the periods
 * cut at the right day, each status is totalled on its own, and the newest-orders list carries no customer data.
 * Totals are compared before and after because the dev database already holds orders.
 */
class OrdersMcpModelTest extends DatabaseTestCase
{
    private Order $orders;
    private string $runTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new Order($this->db, 'shop_orders');
        $this->runTag = 'OT' . \substr(\uniqid(), -6);
    }

    private function order(string $suffix, string $status, string $total, int $daysAgo, bool $test = false): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            "INSERT INTO {$prefix}shop_orders (order_ref, status, customer_name, customer_email, total_inc_tax, is_test_payment, created_at)
             VALUES (:ref, :status, 'Sam Secret', 'sam.secret@example.com', :total, :test, DATE_SUB(NOW(), INTERVAL :days DAY))",
            [':ref' => "{$this->runTag}-{$suffix}", ':status' => $status, ':total' => $total, ':test' => $test ? 1 : 0, ':days' => $daysAgo],
        )->execute();
    }

    /** @return array{count: int, value: float} */
    private function totalFor(array $totals, string $period, string $status): array
    {
        return $totals[$period][$status] ?? ['count' => 0, 'value' => 0.0];
    }

    public function test_a_test_payment_is_never_counted(): void
    {
        $before = $this->orders->getPeriodTotals();

        $this->order('real', 'paid', '100.00', 0);
        $this->order('test', 'paid', '9999.00', 0, true);

        $after = $this->orders->getPeriodTotals();

        foreach (['today', 'last_7_days', 'last_30_days'] as $period) {
            $this->assertSame(1, $this->totalFor($after, $period, 'paid')['count'] - $this->totalFor($before, $period, 'paid')['count'], $period);
            $this->assertEqualsWithDelta(100.0, $this->totalFor($after, $period, 'paid')['value'] - $this->totalFor($before, $period, 'paid')['value'], 0.001, $period);
        }
    }

    public function test_the_periods_cut_at_today_6_days_ago_and_29_days_ago(): void
    {
        $before = $this->orders->getPeriodTotals();

        $this->order('today', 'dispatched', '1.00', 0);
        $this->order('yesterday', 'dispatched', '5.00', 1);
        $this->order('six', 'dispatched', '10.00', 6);
        $this->order('seven', 'dispatched', '100.00', 7);
        $this->order('twenty-nine', 'dispatched', '1000.00', 29);
        $this->order('thirty', 'dispatched', '10000.00', 30);

        $after = $this->orders->getPeriodTotals();
        $delta = fn(string $period): array => [
            $this->totalFor($after, $period, 'dispatched')['count'] - $this->totalFor($before, $period, 'dispatched')['count'],
            \round($this->totalFor($after, $period, 'dispatched')['value'] - $this->totalFor($before, $period, 'dispatched')['value'], 2),
        ];

        $this->assertSame([1, 1.0], $delta('today'));
        $this->assertSame([3, 16.0], $delta('last_7_days'));
        $this->assertSame([5, 1116.0], $delta('last_30_days'));
    }

    public function test_each_status_is_totalled_on_its_own(): void
    {
        $before = $this->orders->getPeriodTotals();

        $this->order('a', 'refunded', '20.00', 1);
        $this->order('b', 'cancelled', '30.00', 1);
        $this->order('c', 'pending', '40.00', 1);

        $after = $this->orders->getPeriodTotals();

        foreach (['refunded' => 20.0, 'cancelled' => 30.0, 'pending' => 40.0] as $status => $value) {
            $this->assertSame(1, $this->totalFor($after, 'last_7_days', $status)['count'] - $this->totalFor($before, 'last_7_days', $status)['count'], $status);
            $this->assertEqualsWithDelta($value, $this->totalFor($after, 'last_7_days', $status)['value'] - $this->totalFor($before, 'last_7_days', $status)['value'], 0.001, $status);
        }
    }

    public function test_the_newest_orders_list_is_newest_first_leaves_out_test_payments_and_respects_the_limit(): void
    {
        $this->order('older', 'paid', '5.00', 2);
        $this->order('newest', 'paid', '6.00', 0);
        $this->order('test', 'paid', '7.00', 0, true);

        $refs = \array_column($this->orders->getRecentAnonymous(1000), 'order_ref');
        $mine = \array_values(\array_filter($refs, fn(string $ref): bool => \str_starts_with($ref, $this->runTag)));

        $this->assertSame(["{$this->runTag}-newest", "{$this->runTag}-older"], $mine);
        $this->assertCount(3, $this->orders->getRecentAnonymous(3));
    }

    public function test_the_newest_orders_list_has_only_references_status_money_and_dates(): void
    {
        $this->order('one', 'paid', '5.00', 0);

        $row = $this->orders->getRecentAnonymous(1)[0];

        $this->assertSame(
            ['order_ref', 'status', 'total_inc_tax', 'currency', 'payment_provider', 'created_at', 'paid_at', 'dispatched_at'],
            \array_keys($row),
        );
        $this->assertStringNotContainsString('Secret', \json_encode($row));
        $this->assertStringNotContainsString('sam.secret', \json_encode($row));
    }

    public function test_an_order_is_found_by_its_exact_reference_with_no_customer_data(): void
    {
        $this->order('find', 'paid', '12.50', 0);
        $prefix = $this->db->tablePrefix();
        $ref = "{$this->runTag}-find";
        $this->db->prepare(
            "UPDATE {$prefix}shop_orders SET billing_address1 = '1 Secret Street', delivery_postcode = 'ZZ9 9ZZ', customer_phone = '07000000000',
                admin_notes = 'Secret note', ip_address = '203.0.113.9' WHERE order_ref = :ref",
            [':ref' => $ref],
        )->execute();

        $row = $this->orders->getAnonymousByRef($ref);

        $this->assertNotNull($row);
        $this->assertSame($ref, $row['order_ref']);
        $this->assertSame('12.50', $row['total_inc_tax']);
        $json = \json_encode($row);
        foreach (['Secret', 'sam.secret', 'ZZ9', '07000000000', '203.0.113.9'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_an_unknown_reference_finds_nothing_and_a_test_payment_is_still_found(): void
    {
        $this->order('test', 'paid', '3.00', 0, true);

        $this->assertNull($this->orders->getAnonymousByRef("{$this->runTag}-nothing"));
        $this->assertSame(1, (int) $this->orders->getAnonymousByRef("{$this->runTag}-test")['is_test_payment']);
    }

    public function test_the_lines_on_an_order_come_back_in_order_without_customer_data(): void
    {
        $this->order('lines', 'paid', '30.00', 0);
        $prefix = $this->db->tablePrefix();
        $id = (int) $this->orders->getAnonymousByRef("{$this->runTag}-lines")['id'];
        foreach ([['A1', 'First', 1], ['B2', 'Second', 3]] as [$ref, $title, $qty]) {
            $this->db->prepare(
                "INSERT INTO {$prefix}shop_order_items (order_id, product_id, product_ref, product_title, quantity, unit_price_exc_tax, line_total_exc_tax)
                 VALUES (:id, 1, :ref, :title, :qty, 10.00, :line)",
                [':id' => $id, ':ref' => $ref, ':title' => $title, ':qty' => $qty, ':line' => 10 * $qty],
            )->execute();
        }

        $items = $this->orders->getItemsAnonymous($id);

        $this->assertSame(['First', 'Second'], \array_column($items, 'product_title'));
        $this->assertSame(
            ['product_ref', 'product_title', 'product_options', 'quantity', 'unit_price_exc_tax', 'line_total_exc_tax', 'line_tax_amount'],
            \array_keys($items[0]),
        );
    }
}
