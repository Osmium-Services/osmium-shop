<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * Order creation rules and the status lifecycle: what is stored, what is
 * refused, and that money states only move in safe directions.
 */
class ShopOrderServiceLifecycleTest extends ShopDatabaseTestCase
{
    private ShopOrderService $orders;
    private CheckoutBasketService $baskets;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->orders = $this->orderService(new ShopTotalsService(
            taxRatePercent: 20.0,
            deliveryCharge: 4.99,
            excludedPostcodes: ['JE', 'GY', 'IM'],
        ));
        $this->productId = $this->createTestProduct(['price_exc_tax' => 100.00, 'model_name' => 'Lifecycle Panel']);
    }

    private function orderService(ShopTotalsService $totals): ShopOrderService
    {
        return new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: $totals,
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );
    }

    private function customer(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Pat Customer',
            'customer_email' => 'pat@example.com',
            'delivery_address1' => '1 Test Street',
            'delivery_city' => 'Bristol',
            'delivery_postcode' => 'bs1 1aa',
        ];
    }

    private function basketWith(int $quantity = 1): int
    {
        $basketId = $this->baskets->getOrCreateBasket('lifecycle-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: $quantity);

        return $basketId;
    }

    private function createOrder(array $customer = [], int $quantity = 1, ?ShopOrderService $service = null): array
    {
        return ($service ?? $this->orders)->createPendingOrder(
            basketId: $this->basketWith($quantity),
            customer: $this->customer($customer),
            ipAddress: '203.0.113.9',
            userAgent: 'PHPUnit',
        );
    }

    private function orderCount(): int
    {
        $prefix = $this->db->tablePrefix();

        return (int) $this->db->prepare("SELECT COUNT(*) AS n FROM {$prefix}shop_orders")->single()['n'];
    }

    private function statusOf(int $orderId): string
    {
        return $this->orders->getOrder($orderId)['status'];
    }

    // ── creation ─────────────────────────────────────────────────────────────

    public function test_an_empty_basket_cannot_be_ordered(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('lifecycle-' . \uniqid());

        $this->expectException(ShopOrderException::class);
        $this->orders->createPendingOrder(basketId: $basketId, customer: $this->customer(), ipAddress: null, userAgent: null);
    }

    public function test_an_order_is_refused_when_delivery_is_not_configured(): void
    {
        $unconfigured = $this->orderService(new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: null));

        $this->expectException(ShopOrderException::class);
        $this->expectExceptionMessage('cannot take orders');
        $this->createOrder(service: $unconfigured);
    }

    public function test_an_order_is_refused_for_an_excluded_postcode(): void
    {
        $this->expectException(ShopOrderException::class);
        $this->expectExceptionMessage('not able to deliver');
        $this->createOrder(['delivery_postcode' => 'JE2 3AA']);
    }

    public function test_money_is_recomputed_from_the_basket_and_stored(): void
    {
        $order = $this->createOrder(quantity: 2);

        $stored = $this->orders->getOrder($order['id']);

        $this->assertEqualsWithDelta(200.00, (float) $stored['subtotal_exc_tax'], 0.001);
        $this->assertEqualsWithDelta(4.99, (float) $stored['shipping_amount'], 0.001);
        $this->assertEqualsWithDelta(41.00, (float) $stored['tax_amount'], 0.001); // 40.00 on the goods + 1.00 on delivery
        $this->assertEqualsWithDelta(245.99, (float) $stored['total_inc_tax'], 0.001);
        $this->assertEqualsWithDelta((float) $order['totals']['total_inc_tax'], (float) $stored['total_inc_tax'], 0.001);
        $this->assertSame('GBP', $stored['currency']);
        $this->assertEqualsWithDelta(20.0, (float) $stored['tax_rate_percent'], 0.001);
    }

    public function test_a_failed_order_insert_is_reported_to_the_customer_and_leaves_no_order(): void
    {
        $failing = new class ($this->db, 'shop_orders') extends CheckoutOrder {
            public function insert(array $bindings): int
            {
                throw new \RuntimeException('disk full');
            }
        };
        $service = new ShopOrderService(
            orders: $failing,
            basket: $this->baskets,
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );
        $before = $this->orderCount();

        try {
            $this->createOrder(service: $service);
            $this->fail('Expected the order to be refused');
        } catch (ShopOrderException $e) {
            $this->assertSame('Sorry, we could not start your order. Please try again.', $e->getMessage()); // No internals leaked
        }

        $this->assertSame($before, $this->orderCount());
    }

    public function test_a_failing_order_hook_never_undoes_an_order_or_its_payment(): void
    {
        $unreadable = new class ($this->db, 'shop_orders') extends CheckoutOrder {
            public bool $failReads = false;

            public function getById(int $orderId): ?array
            {
                if ($this->failReads) throw new \RuntimeException('read failed');

                return parent::getById($orderId);
            }
        };
        $service = new ShopOrderService(
            orders: $unreadable,
            basket: $this->baskets,
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );

        $unreadable->failReads = true; // Hooks read the order back; make that read blow up
        $order = $this->createOrder(service: $service);
        $invoice = $service->markPaid(orderId: $order['id'], captureRef: 'CAP-1', payerEmail: 'pat@example.com');
        $unreadable->failReads = false;

        $this->assertNotNull($invoice);
        $this->assertSame('paid', $this->orders->getOrder($order['id'])['status']);
    }

    public function test_the_order_ref_is_derived_from_the_order_id(): void
    {
        $order = $this->createOrder();

        $this->assertSame('ORD-' . (1000 + $order['id']), $order['order_ref']);
        $this->assertSame($order['order_ref'], $this->orders->getOrder($order['id'])['order_ref']);
    }

    public function test_the_basket_lines_become_order_items_with_their_own_totals(): void
    {
        $order = $this->createOrder(quantity: 3);

        $items = $this->orders->getOrder($order['id'])['items'];

        $this->assertCount(1, $items);
        $this->assertSame('Lifecycle Panel', $items[0]['product_title']);
        $this->assertSame(3, (int) $items[0]['quantity']);
        $this->assertEqualsWithDelta(100.00, (float) $items[0]['unit_price_exc_tax'], 0.001);
        $this->assertEqualsWithDelta(300.00, (float) $items[0]['line_total_exc_tax'], 0.001);
        $this->assertEqualsWithDelta(60.00, (float) $items[0]['line_tax_amount'], 0.001);
    }

    public function test_the_delivery_postcode_is_stored_uppercase_and_billing_falls_back_to_delivery(): void
    {
        $stored = $this->orders->getOrder($this->createOrder()['id']);

        $this->assertSame('BS1 1AA', $stored['delivery_postcode']);
        $this->assertSame('1 Test Street', $stored['billing_address1']);
        $this->assertSame('Bristol', $stored['billing_city']);
        $this->assertSame('bs1 1aa', $stored['billing_postcode']);
        $this->assertSame('Pat Customer', $stored['delivery_name']); // No separate recipient: the customer
    }

    public function test_a_separate_billing_address_and_recipient_are_kept(): void
    {
        $stored = $this->orders->getOrder($this->createOrder([
            'delivery_name' => 'Sam Recipient',
            'billing_address1' => '9 Billing Road',
            'billing_city' => 'Leeds',
            'billing_postcode' => 'LS1 1AA',
        ])['id']);

        $this->assertSame('Sam Recipient', $stored['delivery_name']);
        $this->assertSame('9 Billing Road', $stored['billing_address1']);
        $this->assertSame('Leeds', $stored['billing_city']);
        $this->assertSame('1 Test Street', $stored['delivery_address1']);
    }

    public function test_a_new_order_is_pending_with_acceptance_and_request_details_recorded(): void
    {
        $stored = $this->orders->getOrder($this->createOrder()['id']);

        $this->assertSame('pending', $stored['status']);
        $this->assertNull($stored['invoice_number']);
        $this->assertNull($stored['paid_at']);
        $this->assertNotNull($stored['terms_accepted_at']);
        $this->assertSame('203.0.113.9', $stored['ip_address']);
        $this->assertSame('PHPUnit', $stored['user_agent']);
    }

    public function test_a_very_long_user_agent_is_truncated_to_the_column(): void
    {
        $order = $this->orders->createPendingOrder(
            basketId: $this->basketWith(),
            customer: $this->customer(),
            ipAddress: null,
            userAgent: \str_repeat('x', 400),
        );

        $this->assertSame(255, \strlen($this->orders->getOrder($order['id'])['user_agent']));
    }

    // ── payment references ───────────────────────────────────────────────────

    public function test_the_payment_reference_is_stored_and_findable(): void
    {
        $order = $this->createOrder();

        $this->orders->setPaymentReference(orderId: $order['id'], provider: 'stripe', paymentOrderRef: 'pi_123', isTestPayment: true);

        $found = $this->orders->findByPaymentReference('pi_123');
        $this->assertSame($order['id'], (int) $found['id']);
        $this->assertSame('stripe', $found['payment_provider']);
        $this->assertSame(1, (int) $found['is_test_payment']);
    }

    public function test_an_unknown_payment_reference_finds_nothing(): void
    {
        $this->assertNull($this->orders->findByPaymentReference('pi_nobody'));
    }

    public function test_the_payment_reference_cannot_be_rewritten_once_the_order_is_paid(): void
    {
        $order = $this->createOrder();
        $this->orders->setPaymentReference(orderId: $order['id'], provider: 'stripe', paymentOrderRef: 'pi_first', isTestPayment: false);
        $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_1', payerEmail: null);

        $this->orders->setPaymentReference(orderId: $order['id'], provider: 'paypal', paymentOrderRef: 'PP-SECOND', isTestPayment: true);

        $stored = $this->orders->getOrder($order['id']);
        $this->assertSame('pi_first', $stored['payment_order_ref']);
        $this->assertSame('stripe', $stored['payment_provider']);
        $this->assertSame(0, (int) $stored['is_test_payment']);
    }

    public function test_loading_a_missing_order_returns_null(): void
    {
        $this->assertNull($this->orders->getOrder(987654321));
    }

    // ── paying ───────────────────────────────────────────────────────────────

    public function test_paying_an_unknown_order_is_an_error(): void
    {
        $this->expectException(ShopOrderException::class);
        $this->orders->markPaid(orderId: 987654321, captureRef: 'ch_x', payerEmail: null);
    }

    public function test_paying_a_cancelled_order_does_nothing_and_uses_no_invoice_number(): void
    {
        $order = $this->createOrder();
        $this->orders->markStatus(orderId: $order['id'], status: 'cancelled');
        $before = $this->orders->takeInvoiceNumber();

        $result = $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_late', payerEmail: null);

        $this->assertNull($result);
        $this->assertSame('cancelled', $this->statusOf($order['id']));
        $this->assertSame($before + 1, $this->orders->takeInvoiceNumber()); // No number was burned by the refused payment
    }

    public function test_paying_stamps_the_paid_time(): void
    {
        $order = $this->createOrder();

        $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_1', payerEmail: 'a@example.com');

        $this->assertNotNull($this->orders->getOrder($order['id'])['paid_at']);
    }

    // ── non-payment states ───────────────────────────────────────────────────

    public function test_a_pending_order_can_be_failed_or_cancelled(): void
    {
        $failed = $this->createOrder()['id'];
        $cancelled = $this->createOrder()['id'];

        $this->orders->markStatus(orderId: $failed, status: 'failed');
        $this->orders->markStatus(orderId: $cancelled, status: 'cancelled');

        $this->assertSame('failed', $this->statusOf($failed));
        $this->assertSame('cancelled', $this->statusOf($cancelled));
    }

    public function test_a_paid_order_can_be_refunded(): void
    {
        $order = $this->createOrder();
        $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_1', payerEmail: null);

        $this->orders->markStatus(orderId: $order['id'], status: 'refunded');

        $this->assertSame('refunded', $this->statusOf($order['id']));
    }

    public function test_a_late_failure_event_cannot_overwrite_a_paid_order(): void
    {
        $order = $this->createOrder();
        $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_1', payerEmail: null);

        $this->orders->markStatus(orderId: $order['id'], status: 'failed'); // A failed attempt's webhook, delivered after the successful one

        $this->assertSame('paid', $this->statusOf($order['id']), 'Money was taken, but the order now reads failed');
    }

    public function test_a_late_cancellation_cannot_overwrite_a_paid_order(): void
    {
        $order = $this->createOrder();
        $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_1', payerEmail: null);

        $this->orders->markStatus(orderId: $order['id'], status: 'cancelled');

        $this->assertSame('paid', $this->statusOf($order['id']));
    }

    public function test_a_pending_order_cannot_be_refunded(): void
    {
        $order = $this->createOrder();

        $this->orders->markStatus(orderId: $order['id'], status: 'refunded');

        $this->assertSame('pending', $this->statusOf($order['id']));
    }

    public function test_a_dispatched_order_can_be_refunded(): void
    {
        $order = $this->createOrder();
        $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_1', payerEmail: null);
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}shop_orders SET status = 'dispatched' WHERE id = :id", bindings: [':id' => $order['id']])->execute();

        $this->orders->markStatus(orderId: $order['id'], status: 'refunded');

        $this->assertSame('refunded', $this->statusOf($order['id']));
    }

    public function test_a_failed_order_cannot_be_moved_to_cancelled(): void
    {
        $order = $this->createOrder();
        $this->orders->markStatus(orderId: $order['id'], status: 'failed');

        $this->orders->markStatus(orderId: $order['id'], status: 'cancelled');

        $this->assertSame('failed', $this->statusOf($order['id']));
    }

    public function test_paid_cannot_be_set_through_mark_status(): void
    {
        $order = $this->createOrder();

        $this->expectException(\InvalidArgumentException::class);
        $this->orders->markStatus(orderId: $order['id'], status: 'paid'); // Would skip the invoice number
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $order = $this->createOrder();

        $this->expectException(\InvalidArgumentException::class);
        $this->orders->markStatus(orderId: $order['id'], status: 'bogus');
    }
}
