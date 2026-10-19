<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Core\Library\StoreFinance;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Helpers\FakeStockGuard;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * The points where an installed service can refuse a basket change or an order:
 * basket.line.check (add and raise quantity) and order.check (start checkout).
 */
class CheckoutStockGuardHooksTest extends ShopDatabaseTestCase
{
    private CheckoutBasketService $baskets;
    private ShopOrderService $orders;
    private int $productId;
    private string $serviceDir = '';
    private string|false $previousErrorLog = false;
    private string $errorLog = '';

    protected function setUp(): void
    {
        parent::setUp();

        FakeStockGuard::reset();
        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->orders = new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );
        $this->productId = $this->createTestProduct();
        $this->installFakeGuardService();

        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'guard-log-');
        $this->previousErrorLog = \ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        \array_map('unlink', \glob($this->serviceDir . '/*') ?: []);
        \rmdir($this->serviceDir);
        \ini_set('error_log', (string) $this->previousErrorLog);
        \unlink($this->errorLog);
        FakeStockGuard::reset();

        parent::tearDown();
    }

    private function installFakeGuardService(): void
    {
        $this->serviceDir = \sys_get_temp_dir() . '/fake-guard-' . \uniqid();
        \mkdir($this->serviceDir);
        \file_put_contents($this->serviceDir . '/service.json', \json_encode([
            'id' => 'fake-guard',
            'name' => 'Fake Guard',
            'version' => '1.0.0',
            'description' => 'Test stock guard',
            'autoload' => [],
            'hooks' => [
                'basket.line.check' => [FakeStockGuard::class . '::basketLine'],
                'order.check' => [FakeStockGuard::class . '::order'],
            ],
        ]));

        (new ServiceModel($this->db))->insert(
            serviceId: 'fake-guard',
            name: 'Fake Guard',
            version: '1.0.0',
            description: null,
            authorName: null,
            githubRepo: null,
            path: $this->serviceDir,
            config: null,
            installedBy: null,
        );
    }

    private function newBasket(): int
    {
        return $this->baskets->getOrCreateBasket('guard-' . \uniqid());
    }

    private function createKitValue(int $linkedProduct): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontypes (product_id, type_label, type_slug, default_option_descr, sort)
                VALUES (:product, 'Type', :slug, 'Standard', 0)",
            bindings: [':product' => $this->productId, ':slug' => 'type-' . \uniqid()],
        )->execute();
        $typeId = (int) $this->db->lastInsertId();

        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptionvalues (option_type_id, option_premium, option_descr, option_packed_weight, linked_product_id)
                VALUES (:type, 10.00, 'Choice', 0, :linked)",
            bindings: [':type' => $typeId, ':linked' => $linkedProduct],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function lineCount(int $basketId): int
    {
        return \count($this->baskets->getLines($basketId)['lines']);
    }

    public function test_adding_a_line_asks_the_guard_with_what_is_being_added(): void
    {
        $basketId = $this->newBasket();

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 2);

        $payloads = FakeStockGuard::payloads('basket.line.check');
        $this->assertCount(expectedCount: 1, haystack: $payloads);
        $this->assertSame('add', $payloads[0]['action']);
        $this->assertSame($basketId, $payloads[0]['basketId']);
        $this->assertSame($this->productId, $payloads[0]['productId']);
        $this->assertSame(2, $payloads[0]['quantity']);
        $this->assertSame([], $payloads[0]['parts']);
        $this->assertSame([], $payloads[0]['otherLines']);
    }

    public function test_the_guard_is_told_the_kit_parts_being_added(): void
    {
        $battery = $this->createTestProduct();
        $valueId = $this->createKitValue($battery);

        $this->baskets->addLine(basketId: $this->newBasket(), productId: $this->productId, quantity: 1, optionValueIds: [$valueId]);

        $payload = FakeStockGuard::payloads('basket.line.check')[0];
        $this->assertSame([$valueId], $payload['optionValueIds']);
        $this->assertSame([['product_id' => $battery, 'option_value_id' => $valueId, 'quantity' => 1]], $payload['parts']);
    }

    public function test_a_guard_can_refuse_an_add_and_nothing_is_added(): void
    {
        $basketId = $this->newBasket();
        FakeStockGuard::$throw = new ShopOrderException('Only 1 left in stock.');

        try {
            $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 5);
            $this->fail('The guard refused the add but the line was accepted');
        } catch (ShopOrderException $e) {
            $this->assertSame('Only 1 left in stock.', $e->getMessage());
        }

        $this->assertSame(0, $this->lineCount($basketId));
    }

    public function test_adding_more_of_a_line_already_there_asks_about_the_new_total(): void
    {
        $basketId = $this->newBasket();
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 2);
        FakeStockGuard::reset();

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 3);

        $this->assertSame(5, FakeStockGuard::payloads('basket.line.check')[0]['quantity']);
    }

    public function test_a_refused_add_to_an_existing_line_leaves_its_quantity_alone(): void
    {
        $basketId = $this->newBasket();
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 2);
        FakeStockGuard::$throw = new ShopOrderException('Only 2 left in stock.');

        try {
            $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);
        } catch (ShopOrderException) {
        }

        $this->assertSame(2, (int) $this->baskets->getLines($basketId)['lines'][0]['quantity']);
    }

    public function test_the_guard_is_told_about_the_other_lines_in_the_basket(): void
    {
        $basketId = $this->newBasket();
        $other = $this->createTestProduct();
        $this->baskets->addLine(basketId: $basketId, productId: $other, quantity: 4);
        FakeStockGuard::reset();

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);

        $otherLines = FakeStockGuard::payloads('basket.line.check')[0]['otherLines'];
        $this->assertCount(expectedCount: 1, haystack: $otherLines);
        $this->assertSame($other, $otherLines[0]['product_id']);
        $this->assertSame(4, $otherLines[0]['quantity']);
        $this->assertSame([], $otherLines[0]['parts']);
    }

    public function test_raising_a_quantity_asks_the_guard_and_can_be_refused(): void
    {
        $basketId = $this->newBasket();
        $itemId = $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);
        FakeStockGuard::reset();
        FakeStockGuard::$throw = new ShopOrderException('Only 1 left in stock.');

        try {
            $this->baskets->updateQuantity(basketId: $basketId, itemId: $itemId, quantity: 4);
            $this->fail('The guard refused the increase but the quantity changed');
        } catch (ShopOrderException) {
        }

        $payload = FakeStockGuard::payloads('basket.line.check')[0];
        $this->assertSame('update', $payload['action']);
        $this->assertSame(4, $payload['quantity']);
        $this->assertSame([], $payload['otherLines'], 'The line being changed was listed as one of the other lines');
        $this->assertSame(1, (int) $this->baskets->getLines($basketId)['lines'][0]['quantity']);
    }

    public function test_lowering_a_quantity_or_removing_a_line_never_asks_the_guard(): void
    {
        $basketId = $this->newBasket();
        $itemId = $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 3);
        FakeStockGuard::reset();
        FakeStockGuard::$throw = new ShopOrderException('Out of stock.');

        $this->baskets->updateQuantity(basketId: $basketId, itemId: $itemId, quantity: 2);
        $this->baskets->updateQuantity(basketId: $basketId, itemId: $itemId, quantity: 0);

        $this->assertSame([], FakeStockGuard::$calls, 'A customer reducing an over-stocked basket was blocked');
        $this->assertSame(0, $this->lineCount($basketId));
    }

    public function test_a_guard_that_crashes_is_logged_and_the_add_still_goes_through(): void
    {
        $basketId = $this->newBasket();
        FakeStockGuard::$throw = new \RuntimeException('stock table missing');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);

        $this->assertSame(1, $this->lineCount($basketId));
        $this->assertStringContainsString('stock table missing', (string) \file_get_contents($this->errorLog));
    }

    public function test_starting_checkout_asks_the_guard_about_every_line_and_its_parts(): void
    {
        $battery = $this->createTestProduct();
        $valueId = $this->createKitValue($battery);
        $plain = $this->createTestProduct();
        $basketId = $this->newBasket();
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 2, optionValueIds: [$valueId]);
        $this->baskets->addLine(basketId: $basketId, productId: $plain, quantity: 1);
        FakeStockGuard::reset();

        $this->orders->createPendingOrder(basketId: $basketId, customer: $this->customer(), ipAddress: '127.0.0.1', userAgent: 'PHPUnit');

        $payloads = FakeStockGuard::payloads('order.check');
        $this->assertCount(expectedCount: 1, haystack: $payloads);
        $this->assertSame($basketId, $payloads[0]['basketId']);
        $lines = $payloads[0]['lines'];
        $this->assertCount(expectedCount: 2, haystack: $lines);
        $this->assertSame($this->productId, $lines[0]['product_id']);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertSame([['product_id' => $battery, 'option_value_id' => $valueId, 'quantity' => 1]], $lines[0]['parts']);
        $this->assertSame([], $lines[1]['parts']);
    }

    public function test_a_guard_can_refuse_checkout_and_no_order_is_created(): void
    {
        $basketId = $this->newBasket();
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);
        FakeStockGuard::$throw = new ShopOrderException('Sorry, that item has just sold out.');
        $ordersBefore = $this->orderTotal();

        try {
            $this->orders->createPendingOrder(basketId: $basketId, customer: $this->customer(), ipAddress: '127.0.0.1', userAgent: 'PHPUnit');
            $this->fail('The guard refused checkout but an order was created');
        } catch (ShopOrderException $e) {
            $this->assertSame('Sorry, that item has just sold out.', $e->getMessage());
        }

        $this->assertSame($ordersBefore, $this->orderTotal());
    }

    private function customer(): array
    {
        return [
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'delivery_address1' => '1 Test Street',
            'delivery_city' => 'Bristol',
            'delivery_postcode' => 'BS1 1AA',
        ];
    }

    private function orderTotal(): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->query("SELECT COUNT(*) AS c FROM {$prefix}shop_orders");

        return (int) $this->db->single()['c'];
    }
}
