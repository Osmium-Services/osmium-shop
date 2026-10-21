<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Admin\Services\DiscountListView;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/core/admin/models/ListRows.php';
require_once 'app/services/osmium-shop/models/Discount.php';
require_once 'app/services/osmium-shop/models/DiscountListView.php';

/**
 * Orders record which discount reduced each line, and the Discounts list reports orders and money given per
 * discount from paid orders, whatever the discount's type.
 */
class DiscountReportingTest extends ShopDatabaseTestCase
{
    private OsmiumAdminModel $model;
    private CheckoutBasketService $baskets;
    private ShopOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog', 'listRows']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'discount', \Osmium\Modules\Admin\Models\Discount::class, 'discounts');

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $discounts = new ShopDiscountService(new CheckoutDiscount($this->db, 'discounts'));
        $this->orders = new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99, discounts: $discounts),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
            discounts: $discounts,
        );
    }

    private function createDiscount(string $type, float $value, ?string $code = null, string $valueType = 'percent'): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'discounts', data: [
            'name' => 'Report ' . \uniqid(), 'type' => $type, 'code' => $code, 'value_type' => $valueType, 'value' => $value, 'active' => 1,
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function orderFor(int $productId, int $quantity, ?string $coupon = null, bool $pay = true): int
    {
        $basketId = $this->baskets->getOrCreateBasket('report-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $productId, quantity: $quantity);
        $order = $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: [
                'customer_name' => 'Pat', 'customer_email' => 'pat@example.com',
                'delivery_address1' => '1 Test Street', 'delivery_city' => 'Bristol', 'delivery_postcode' => 'BS1 1AA',
            ],
            ipAddress: '203.0.113.9',
            userAgent: 'PHPUnit',
            couponCode: $coupon,
        );
        if ($pay) $this->orders->markPaid(orderId: $order['id'], captureRef: 'ch_' . \uniqid(), payerEmail: 'pat@example.com');

        return $order['id'];
    }

    private function reportFor(int $discountId): array
    {
        foreach ($this->model->discount->listWithCounts() as $row) {
            if ((int)$row['id'] === $discountId) return DiscountListView::row($row);
        }
        $this->fail("Discount {$discountId} missing from the list");
    }

    public function test_an_order_line_records_its_discount_and_the_total_it_took_off(): void
    {
        $discountId = $this->createDiscount('sitewide', 25.0);
        $orderId = $this->orderFor($this->createTestProduct(['price_exc_tax' => 100.00]), quantity: 2);

        $item = $this->orders->getOrder($orderId)['items'][0];

        $this->assertSame($discountId, (int)$item['discount_id']);
        $this->assertEqualsWithDelta(50.00, (float)$item['discount_amount'], 0.001);
    }

    public function test_a_line_with_no_discount_records_none(): void
    {
        $orderId = $this->orderFor($this->createTestProduct(['price_exc_tax' => 100.00]), quantity: 1);

        $item = $this->orders->getOrder($orderId)['items'][0];

        $this->assertNull($item['discount_id']);
        $this->assertEqualsWithDelta(0.0, (float)$item['discount_amount'], 0.001);
    }

    public function test_the_list_reports_paid_orders_and_money_given_for_a_discount(): void
    {
        $discountId = $this->createDiscount('sitewide', 25.0);
        $product = $this->createTestProduct(['price_exc_tax' => 100.00]);
        $this->orderFor($product, quantity: 2);
        $this->orderFor($product, quantity: 1);

        $row = $this->reportFor($discountId);

        $this->assertSame('2', $row['ordersLabel']);
        $this->assertSame('£75.00', $row['givenLabel']);
    }

    public function test_unpaid_orders_are_not_counted(): void
    {
        $discountId = $this->createDiscount('sitewide', 25.0);
        $this->orderFor($this->createTestProduct(['price_exc_tax' => 100.00]), quantity: 1, pay: false);

        $row = $this->reportFor($discountId);

        $this->assertSame('-', $row['ordersLabel']);
        $this->assertSame('-', $row['givenLabel']);
    }

    public function test_a_refunded_order_is_not_counted(): void
    {
        $discountId = $this->createDiscount('sitewide', 25.0);
        $orderId = $this->orderFor($this->createTestProduct(['price_exc_tax' => 100.00]), quantity: 1);
        $this->orders->markStatus(orderId: $orderId, status: 'refunded');

        $this->assertSame('-', $this->reportFor($discountId)['ordersLabel']);
    }

    public function test_a_discount_nobody_used_reports_dashes(): void
    {
        $row = $this->reportFor($this->createDiscount('sitewide', 10.0));

        $this->assertSame('-', $row['ordersLabel']);
        $this->assertSame('-', $row['givenLabel']);
    }

    public function test_a_free_delivery_coupon_counts_its_orders_though_it_takes_nothing_off_the_goods(): void
    {
        $discountId = $this->createDiscount('coupon', 0.0, code: 'SHIPFREE', valueType: 'free_delivery');
        $product = $this->createTestProduct(['price_exc_tax' => 100.00]);
        $this->orderFor($product, quantity: 1, coupon: 'SHIPFREE');
        $this->orderFor($product, quantity: 1);

        $row = $this->reportFor($discountId);

        $this->assertSame('1', $row['ordersLabel']);
        $this->assertSame('-', $row['givenLabel']);
    }
}
