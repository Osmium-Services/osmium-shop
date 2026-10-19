<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Helpers\ShopDatabaseTestCase;

class ShopOrderServiceCouponTest extends ShopDatabaseTestCase
{
    private ShopOrderService $orders;
    private ShopDiscountService $discounts;
    private CheckoutBasketService $baskets;
    private int $testProductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $totals = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $this->discounts);
        $this->orders = new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: $totals,
            discounts: $this->discounts,
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );

        $this->testProductId = $this->createTestProduct();
    }

    private function validCustomer(array $overrides = []): array
    {
        return \array_merge([
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'delivery_address1' => '1 Test Street',
            'delivery_city' => 'Bristol',
            'delivery_postcode' => 'BS1 1AA',
        ], $overrides);
    }

    private function createCoupon(array $overrides = []): int
    {
        $defaults = [
            'name' => 'Test coupon ' . \uniqid(),
            'code' => 'TESTCODE' . \uniqid(),
            'value_type' => 'percent',
            'value' => 10.00,
            'usage_limit' => null,
            'usage_limit_per_customer' => null,
        ];
        $data = \array_merge($defaults, $overrides);

        $sql = "INSERT INTO {$this->db->tablePrefix()}discounts
                (name, type, code, value_type, value, active, usage_limit, usage_limit_per_customer)
                VALUES (:name, 'coupon', :code, :value_type, :value, 1, :usage_limit, :usage_limit_per_customer)";

        $this->db->prepare(sql: $sql, bindings: [
            ':name' => $data['name'],
            ':code' => $data['code'],
            ':value_type' => $data['value_type'],
            ':value' => $data['value'],
            ':usage_limit' => $data['usage_limit'],
            ':usage_limit_per_customer' => $data['usage_limit_per_customer'],
        ])->execute();

        return (int)$this->db->lastInsertId();
    }

    public function test_order_created_with_coupon_records_discount_fields(): void
    {
        $discountId = $this->createCoupon(['code' => 'ORDER10', 'value' => 10.00]);

        $basketId = $this->baskets->getOrCreateBasket('coupon-order-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $order = $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: $this->validCustomer(),
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            couponCode: 'ORDER10',
        );

        $stored = $this->orders->getOrder($order['id']);

        $this->assertSame($discountId, (int)$stored['discount_id']);
        $this->assertSame('ORDER10', $stored['discount_code']);
        $this->assertGreaterThan(0.0, (float)$stored['discount_amount']);
    }

    public function test_mark_paid_records_redemption_and_increments_times_used(): void
    {
        $discountId = $this->createCoupon(['code' => 'REDEEM10', 'value' => 10.00]);

        $basketId = $this->baskets->getOrCreateBasket('coupon-order-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $order = $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: $this->validCustomer(['customer_email' => 'redeemer@example.com']),
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            couponCode: 'REDEEM10',
        );

        $this->orders->markPaid(orderId: $order['id'], captureRef: 'CAP-REDEEM', payerEmail: 'redeemer@example.com');

        $redemption = $this->db->prepare(
            sql: "SELECT * FROM {$this->db->tablePrefix()}discount_redemptions WHERE discount_id = :id",
            bindings: [':id' => $discountId],
        )->single();
        $this->assertNotFalse($redemption);
        $this->assertSame('redeemer@example.com', $redemption['customer_email']);

        $discount = $this->db->prepare(
            sql: "SELECT times_used FROM {$this->db->tablePrefix()}discounts WHERE id = :id",
            bindings: [':id' => $discountId],
        )->single();
        $this->assertSame(1, (int)$discount['times_used']);
    }

    public function test_the_counter_never_passes_the_usage_limit_when_two_orders_were_open_at_once(): void
    {
        $discountId = $this->createCoupon(['code' => 'LASTONE', 'value' => 10.00, 'usage_limit' => 1]);

        $orderIds = [];
        foreach (['a', 'b'] as $who) {
            $basketId = $this->baskets->getOrCreateBasket("coupon-race-{$who}-" . \uniqid());
            $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);
            $orderIds[] = $this->orders->createPendingOrder(
                basketId: $basketId,
                customer: $this->validCustomer(['customer_email' => "{$who}@example.com"]),
                ipAddress: '127.0.0.1',
                userAgent: 'PHPUnit',
                couponCode: 'LASTONE',
            )['id'];
        }

        foreach ($orderIds as $i => $orderId) {
            $this->assertNotNull($this->orders->markPaid(orderId: $orderId, captureRef: "CAP-RACE-{$i}", payerEmail: null), 'a paid order stands');
        }

        $discount = $this->db->prepare(
            sql: "SELECT times_used FROM {$this->db->tablePrefix()}discounts WHERE id = :id",
            bindings: [':id' => $discountId],
        )->single();
        $this->assertSame(1, (int)$discount['times_used']);
    }

    public function test_per_customer_usage_limit_blocks_a_repeat_redemption(): void
    {
        $discountId = $this->createCoupon(['code' => 'ONCEONLY', 'value' => 10.00, 'usage_limit_per_customer' => 1]);

        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discount_redemptions (discount_id, customer_email)
                  VALUES (:discount_id, :customer_email)",
            bindings: [':discount_id' => $discountId, ':customer_email' => 'repeat@example.com'],
        )->execute();

        $basketId = $this->baskets->getOrCreateBasket('coupon-order-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $this->expectException(ShopOrderException::class);

        $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: $this->validCustomer(['customer_email' => 'repeat@example.com']),
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            couponCode: 'ONCEONLY',
        );
    }
}
