<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Helpers\ShopDatabaseTestCase;

class ShopOrderServiceTest extends ShopDatabaseTestCase
{
    private ShopOrderService $orders;
    private CheckoutBasketService $baskets;
    private int $testProductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99);
        $this->orders = new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: $totals,
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );

        $this->testProductId = $this->createTestProduct();
    }

    private function validCustomer(): array
    {
        return [
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'delivery_address1' => '1 Test Street',
            'delivery_city' => 'Bristol',
            'delivery_postcode' => 'BS1 1AA',
        ];
    }

    public function test_creates_a_pending_order_with_line_items(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('order-test-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $order = $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: $this->validCustomer(),
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $this->assertStringStartsWith(prefix: 'SG2-', string: $order['order_ref']);

        $stored = $this->orders->getOrder($order['id']);
        $this->assertSame(expected: 'pending', actual: $stored['status']);
        $this->assertCount(expectedCount: 1, haystack: $stored['items']);
    }

    public function test_a_product_discontinued_after_it_was_added_cannot_be_ordered(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('order-test-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET status_id = 3 WHERE id = :id", bindings: [':id' => $this->testProductId])->execute();

        $this->expectException(\Osmium\Modules\Checkout\Services\ShopOrderException::class);
        $this->expectExceptionMessage('is no longer available');
        $this->orders->createPendingOrder(basketId: $basketId, customer: $this->validCustomer(), ipAddress: '127.0.0.1', userAgent: 'PHPUnit');
    }

    public function test_mark_paid_is_idempotent(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('order-test-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);
        $order = $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: $this->validCustomer(),
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $first = $this->orders->markPaid(orderId: $order['id'], captureRef: 'CAP-1', payerEmail: 'payer@example.com');
        $second = $this->orders->markPaid(orderId: $order['id'], captureRef: 'CAP-1', payerEmail: 'payer@example.com');

        $this->assertIsInt($first);
        $this->assertNull($second); // The second call did nothing — one invoice number, not two
    }

    private function pendingOrderId(): int
    {
        $basketId = $this->baskets->getOrCreateBasket('order-test-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        return $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: $this->validCustomer(),
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        )['id'];
    }

    public function test_mark_paid_stores_the_invoice_number_and_payment_details(): void
    {
        $orderId = $this->pendingOrderId();

        $invoiceNumber = $this->orders->markPaid(orderId: $orderId, captureRef: 'CAP-STORE', payerEmail: 'payer@example.com');

        $stored = $this->orders->getOrder($orderId);
        $this->assertSame(expected: 'paid', actual: $stored['status']);
        $this->assertSame(expected: $invoiceNumber, actual: (int)$stored['invoice_number']);
        $this->assertSame(expected: 'CAP-STORE', actual: $stored['payment_capture_ref']);
        $this->assertSame(expected: 'payer@example.com', actual: $stored['payment_payer_email']);
    }

    public function test_invoice_numbers_are_gapless_across_orders(): void
    {
        $first = $this->orders->markPaid(orderId: $this->pendingOrderId(), captureRef: 'CAP-A', payerEmail: null);
        $second = $this->orders->markPaid(orderId: $this->pendingOrderId(), captureRef: 'CAP-B', payerEmail: null);

        $this->assertSame(expected: $first + 1, actual: $second);
    }
}
