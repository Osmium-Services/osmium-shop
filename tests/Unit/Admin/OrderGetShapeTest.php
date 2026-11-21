<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopReportsMcp;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopReportsMcp.php';

final class OrderGetShapeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(array $over = []): array
    {
        return $over + [
            'id' => '7', 'order_ref' => 'AB-1001', 'status' => 'paid', 'is_test_payment' => '0',
            'payment_provider' => 'stripe', 'payment_method' => 'Card (Visa ••4242)', 'payment_order_ref' => 'pi_1', 'payment_capture_ref' => 'ch_1',
            'currency' => 'GBP', 'subtotal_exc_tax' => '10.00', 'shipping_amount' => '5.00', 'tax_amount' => '3.00', 'tax_rate_percent' => '20.00',
            'discount_code' => null, 'discount_amount' => '0.00', 'total_inc_tax' => '18.00',
            'delivery_zone' => 'uk', 'delivery_country' => 'United Kingdom', 'tracking_carrier' => null, 'tracking_ref' => null,
            'terms_accepted_at' => '2026-11-01 10:00:00', 'xero_synced_at' => null,
            'created_at' => '2026-11-01 10:00:00', 'paid_at' => '2026-11-01 10:01:00', 'dispatched_at' => null,
        ];
    }

    public function test_money_is_numbers_and_flags_are_booleans(): void
    {
        $shaped = ShopReportsMcp::shapeOrder($this->row(['is_test_payment' => '1']), []);

        $this->assertSame(18.0, $shaped['money']['total_inc_tax']);
        $this->assertTrue($shaped['test_payment']);
        $this->assertTrue($shaped['terms_accepted']);
        $this->assertFalse($shaped['accounts_synced']);
        $this->assertSame('Card (Visa ••4242)', $shaped['payment']['method']);
    }

    public function test_lines_keep_their_order_and_numbers(): void
    {
        $shaped = ShopReportsMcp::shapeOrder($this->row(), [
            ['product_ref' => 'A1', 'product_title' => 'First', 'product_options' => 'Red', 'quantity' => '2', 'unit_price_exc_tax' => '5.00', 'line_total_exc_tax' => '10.00', 'line_tax_amount' => '2.00'],
        ]);

        $this->assertSame(2, $shaped['items'][0]['quantity']);
        $this->assertSame(10.0, $shaped['items'][0]['line_total_exc_tax']);
        $this->assertSame('Red', $shaped['items'][0]['options']);
    }

    public function test_a_column_that_is_not_listed_never_reaches_the_answer(): void
    {
        $shaped = ShopReportsMcp::shapeOrder($this->row(['customer_email' => 'sam@example.com', 'billing_postcode' => 'ZZ9 9ZZ']), []);

        $this->assertStringNotContainsString('sam@example.com', \json_encode($shaped));
        $this->assertStringNotContainsString('ZZ9', \json_encode($shaped));
    }
}
