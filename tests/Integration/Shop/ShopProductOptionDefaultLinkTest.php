<?php

declare(strict_types=1);

namespace Tests\Integration\Shop;

use Osmium\Modules\Shop\Models\ShopProductOption;
use Osmium\Modules\Shop\Services\ShopProductService;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * What the storefront learns about the product behind an option type's default
 * choice: its stock status and restock date, read from the real tables.
 */
class ShopProductOptionDefaultLinkTest extends ShopDatabaseTestCase
{
    private ShopProductOption $options;
    private ShopProductService $service;
    private int $kitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = new ShopProductOption($this->db, 'productoptiontypes');
        $this->service = new ShopProductService($this->db);
        $this->kitId = $this->createTestProduct();
    }

    private function createLinkedProduct(int $available, ?string $restockAt = null): int
    {
        $productId = $this->createTestProduct(['status_id' => $this->statusId((bool) $available)]);

        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "UPDATE {$prefix}products SET restock_at = :restock WHERE id = :id",
            bindings: [':restock' => $restockAt, ':id' => $productId],
        )->execute();

        return $productId;
    }

    private function createTypeWithDefaultLink(?int $defaultLinked): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontypes
                (product_id, type_label, type_slug, default_option_descr, default_linked_product_id, sort)
                VALUES (:product, 'Gland', 'gland', 'Single', :linked, 0)",
            bindings: [':product' => $this->kitId, ':linked' => $defaultLinked],
        )->execute();
    }

    private function onlyType(): array
    {
        $types = $this->service->buildOptionTypes($this->options->getAllOptionsForProduct($this->kitId), '£');
        $this->assertCount(expectedCount: 1, haystack: $types);

        return $types[0];
    }

    public function test_an_out_of_stock_default_product_is_reported_with_its_restock_date(): void
    {
        $this->createTypeWithDefaultLink($this->createLinkedProduct(available: 0, restockAt: '2026-11-01'));

        $type = $this->onlyType();

        $this->assertFalse($type['defaultAvailable']);
        $this->assertSame('2026-11-01', $type['defaultRestockDate']);
    }

    public function test_an_in_stock_default_product_is_available(): void
    {
        $this->createTypeWithDefaultLink($this->createLinkedProduct(available: 1));

        $this->assertTrue($this->onlyType()['defaultAvailable']);
    }

    public function test_a_default_with_no_product_behind_it_is_available(): void
    {
        $this->createTypeWithDefaultLink(null);

        $type = $this->onlyType();

        $this->assertTrue($type['defaultAvailable']);
        $this->assertNull($type['defaultLinkedProductId']);
    }
}
