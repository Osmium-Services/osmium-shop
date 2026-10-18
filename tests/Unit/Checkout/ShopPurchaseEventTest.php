<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\ShopPurchaseEvent;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/ShopPurchaseEvent.php';

/**
 * The sale figures a browser-side service (analytics, ads pixel) reports from
 * the thank-you page.
 */
class ShopPurchaseEventTest extends TestCase
{
    private function order(array $items, array $overrides = []): array
    {
        return $overrides + [
            'order_ref' => 'SG2-1001',
            'total_inc_tax' => '245.99',
            'currency' => 'GBP',
            'items' => $items,
        ];
    }

    private function item(array $overrides = []): array
    {
        return $overrides + [
            'product_id' => '7',
            'product_title' => 'Solar Panel 400W',
            'quantity' => '2',
            'unit_price_exc_tax' => '100.00',
            'tax_rate_percent' => '20.00',
        ];
    }

    public function test_the_event_carries_the_order_ref_value_and_currency(): void
    {
        $event = ShopPurchaseEvent::fromOrder($this->order([$this->item()]));

        $this->assertSame('SG2-1001', $event['order_ref']);
        $this->assertSame(245.99, $event['value']);
        $this->assertSame('GBP', $event['currency']);
    }

    public function test_database_strings_become_real_numbers(): void
    {
        $item = ShopPurchaseEvent::fromOrder($this->order([$this->item()]))['items'][0];

        $this->assertSame(7, $item['product_id']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame(120.0, $item['unit_price_inc_tax']);
        $this->assertSame('Solar Panel 400W', $item['title']);
    }

    public function test_unit_price_includes_tax_at_the_rate_recorded_on_the_line(): void
    {
        $items = [
            $this->item(['unit_price_exc_tax' => '50.00', 'tax_rate_percent' => '20.00']),
            $this->item(['unit_price_exc_tax' => '50.00', 'tax_rate_percent' => '5.00']),
            $this->item(['unit_price_exc_tax' => '50.00', 'tax_rate_percent' => '0.00']),
        ];

        $prices = \array_column(ShopPurchaseEvent::fromOrder($this->order($items))['items'], 'unit_price_inc_tax');

        $this->assertSame([60.0, 52.5, 50.0], $prices);
    }

    public function test_unit_price_is_rounded_to_the_penny(): void
    {
        $item = $this->item(['unit_price_exc_tax' => '33.333', 'tax_rate_percent' => '20.00']);

        $price = ShopPurchaseEvent::fromOrder($this->order([$item]))['items'][0]['unit_price_inc_tax'];

        $this->assertSame(40.0, $price);
    }

    public function test_every_line_is_reported_in_order(): void
    {
        $items = [
            $this->item(['product_id' => '1', 'product_title' => 'A']),
            $this->item(['product_id' => '2', 'product_title' => 'B']),
        ];

        $event = ShopPurchaseEvent::fromOrder($this->order($items));

        $this->assertSame(['A', 'B'], \array_column($event['items'], 'title'));
        $this->assertSame([1, 2], \array_column($event['items'], 'product_id'));
    }

    public function test_an_order_with_no_items_reports_an_empty_list(): void
    {
        $this->assertSame([], ShopPurchaseEvent::fromOrder($this->order([]))['items']);
    }

    public function test_the_event_is_json_encodable_for_the_thank_you_page(): void
    {
        $json = \json_encode(ShopPurchaseEvent::fromOrder($this->order([$this->item(['product_title' => 'Panel "400" <b>'])])));

        $this->assertIsString($json);
        $this->assertSame('Panel "400" <b>', \json_decode($json, true)['items'][0]['title']);
    }
}
