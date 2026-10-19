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

/**
 * An order keeps, per line, the products that line took stock from, frozen at
 * the moment the order was made.
 */
class ShopOrderServiceKitPartsTest extends ShopDatabaseTestCase
{
    private ShopOrderService $orders;
    private CheckoutBasketService $baskets;
    private int $kitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->orders = new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );
        $this->kitId = $this->createTestProduct();
    }

    private function createType(?int $defaultLinked = null): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontypes
                (product_id, type_label, type_slug, default_option_descr, default_linked_product_id, sort)
                VALUES (:product, 'Type', :slug, 'Standard', :default_linked, 0)",
            bindings: [':product' => $this->kitId, ':slug' => 'type-' . \uniqid(), ':default_linked' => $defaultLinked],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function createValue(int $typeId, ?int $linked): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptionvalues
                (option_type_id, option_premium, option_descr, option_packed_weight, linked_product_id)
                VALUES (:type, 10.00, 'Choice', 0, :linked)",
            bindings: [':type' => $typeId, ':linked' => $linked],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    /** @param array<int, array{product_id?: int, quantity: int, optionValueIds?: array<int>}> $lines */
    private function orderFor(array $lines): array
    {
        $basketId = $this->baskets->getOrCreateBasket('kit-parts-' . \uniqid());
        foreach ($lines as $line) {
            $this->baskets->addLine(
                basketId: $basketId,
                productId: $line['product_id'] ?? $this->kitId,
                quantity: $line['quantity'],
                optionValueIds: $line['optionValueIds'] ?? [],
            );
        }

        $created = $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: [
                'customer_name' => 'Test Customer',
                'customer_email' => 'test@example.com',
                'delivery_address1' => '1 Test Street',
                'delivery_city' => 'Bristol',
                'delivery_postcode' => 'BS1 1AA',
            ],
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        return $this->orders->getOrder($created['id']);
    }

    private function part(int $productId, ?int $valueId): array
    {
        return ['product_id' => $productId, 'option_value_id' => $valueId, 'quantity' => 1];
    }

    public function test_a_chosen_option_with_a_linked_product_is_kept_on_the_order_line(): void
    {
        $battery = $this->createTestProduct();
        $valueId = $this->createValue($this->createType(), linked: $battery);

        $order = $this->orderFor([['quantity' => 1, 'optionValueIds' => [$valueId]]]);

        $this->assertSame([$this->part($battery, $valueId)], $order['items'][0]['parts']);
    }

    public function test_a_type_left_on_its_default_keeps_the_default_product(): void
    {
        $gland = $this->createTestProduct();
        $this->createType(defaultLinked: $gland);

        $order = $this->orderFor([['quantity' => 1]]);

        $this->assertSame([$this->part($gland, null)], $order['items'][0]['parts']);
    }

    public function test_a_plain_product_has_no_parts(): void
    {
        $plain = $this->createTestProduct();

        $order = $this->orderFor([['product_id' => $plain, 'quantity' => 1]]);

        $this->assertSame([], $order['items'][0]['parts']);
    }

    public function test_parts_are_per_unit_so_the_line_quantity_does_not_change_them(): void
    {
        $battery = $this->createTestProduct();
        $valueId = $this->createValue($this->createType(), linked: $battery);

        $order = $this->orderFor([['quantity' => 3, 'optionValueIds' => [$valueId]]]);

        $this->assertSame(3, (int) $order['items'][0]['quantity']);
        $this->assertSame([$this->part($battery, $valueId)], $order['items'][0]['parts']);
    }

    public function test_each_line_keeps_its_own_parts(): void
    {
        $single = $this->createTestProduct();
        $double = $this->createTestProduct();
        $typeId = $this->createType();
        $singleValue = $this->createValue($typeId, linked: $single);
        $doubleValue = $this->createValue($typeId, linked: $double);

        $order = $this->orderFor([
            ['quantity' => 1, 'optionValueIds' => [$singleValue]],
            ['quantity' => 1, 'optionValueIds' => [$doubleValue]],
        ]);

        $partsByProduct = [];
        foreach ($order['items'] as $item) {
            $partsByProduct[] = $item['parts'][0]['product_id'];
        }
        $this->assertSame([$single, $double], $partsByProduct);
    }

    public function test_parts_do_not_change_when_the_option_is_edited_after_the_order(): void
    {
        $battery = $this->createTestProduct();
        $replacement = $this->createTestProduct();
        $valueId = $this->createValue($this->createType(), linked: $battery);
        $order = $this->orderFor([['quantity' => 1, 'optionValueIds' => [$valueId]]]);

        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "UPDATE {$prefix}productoptionvalues SET linked_product_id = :product WHERE id = :id",
            bindings: [':product' => $replacement, ':id' => $valueId],
        )->execute();

        $stored = $this->orders->getOrder($order['id']);
        $this->assertSame([$this->part($battery, $valueId)], $stored['items'][0]['parts'], 'An old order followed a later edit to the option');
    }
}
