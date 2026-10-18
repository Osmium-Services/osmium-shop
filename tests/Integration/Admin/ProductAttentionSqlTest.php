<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Product;
use Tests\Integration\DatabaseTestCase;

/**
 * The SQL behind products_attention, run against the real tables: which products count as live, and what each one
 * reports (stock state, restock date, price, category). The report is read through the model, as the tool does.
 */
class ProductAttentionSqlTest extends DatabaseTestCase
{
    private Product $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products = new Product($this->db, 'products');
    }

    /** A live brand to give test products, created once if the database has none */
    private function liveBrandId(): int
    {
        $prefix = $this->db->tablePrefix();
        $brand = $this->db->prepare("SELECT id FROM {$prefix}productbrands WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->single();
        if ($brand !== false) return (int) $brand['id'];

        $this->db->prepare("INSERT INTO {$prefix}productbrands (name) VALUES ('Zq Test Brand')")->execute();

        return (int) $this->db->lastInsertId();
    }

    private function liveProduct(array $overrides = [], bool $withPage = true, bool $qrPageOnly = false): int
    {
        $id = $this->createTestProduct($overrides + ['brand_id' => $this->liveBrandId(), 'status_id' => $this->statusId(true)]);
        if ($withPage || $qrPageOnly) {
            $prefix = $this->db->tablePrefix();
            $pageId = (int) $this->db->prepare("SELECT COALESCE(MAX(page_id), 0) + 1 AS next FROM {$prefix}products_pages")->single()['next'];
            $this->db->prepare(
                "INSERT INTO {$prefix}products_pages (product_id, page_id, is_qr) VALUES (:product, :page, :qr)",
                [':product' => $id, ':page' => $pageId, ':qr' => $qrPageOnly ? 1 : 0],
            )->execute();
        }

        return $id;
    }

    private function category(int $productId, bool $deleted = false): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            "INSERT INTO {$prefix}products_productcategories (product_id, category_id, deleted_at) VALUES (:product, 1, " . ($deleted ? 'NOW()' : 'NULL') . ')',
            [':product' => $productId],
        )->execute();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->products->getAttentionReport(100000);
    }

    /** @return array<int, int> ids in one of the report's lists */
    private function ids(array $report, string $list): array
    {
        return \array_map(static fn(array $item): int => (int) $item['id'], $report[$list]['items']);
    }

    public function test_a_product_counts_as_live_only_with_a_normal_page_and_not_deleted(): void
    {
        $before = $this->report()['live_products'];
        $prefix = $this->db->tablePrefix();

        $this->liveProduct();
        $this->liveProduct(withPage: false);
        $this->liveProduct(withPage: false, qrPageOnly: true);
        $deleted = $this->liveProduct();
        $this->db->prepare("UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", [':id' => $deleted])->execute();

        $this->assertSame(1, $this->report()['live_products'] - $before);
    }

    public function test_a_product_of_a_deleted_brand_is_not_live(): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare("INSERT INTO {$prefix}productbrands (name, deleted_at) VALUES ('Zq Gone Brand', NOW())")->execute();
        $brand = (int) $this->db->lastInsertId();
        $product = $this->liveProduct(['brand_id' => $brand]);

        $report = $this->report();

        foreach (['out_of_stock', 'restocking', 'zero_price', 'no_category'] as $list) {
            $this->assertNotContains($product, $this->ids($report, $list), $list);
        }
    }

    public function test_an_available_product_counts_as_available_and_an_unavailable_one_as_out_of_stock(): void
    {
        $before = $this->report();

        $available = $this->liveProduct(['status_id' => $this->statusId(true)]);
        $prefix = $this->db->tablePrefix();
        $outOfStock = $this->db->prepare("SELECT id FROM {$prefix}productstatus WHERE product_available = 0 AND restocking = 0 AND deleted_at IS NULL ORDER BY id LIMIT 1")->single();
        if ($outOfStock === false) $this->markTestSkipped('No plain out of stock product status exists');
        $gone = $this->liveProduct(['status_id' => (int) $outOfStock['id']]);

        $after = $this->report();

        $this->assertSame(1, $after['available'] - $before['available']);
        $this->assertContains($gone, $this->ids($after, 'out_of_stock'));
        $this->assertNotContains($available, $this->ids($after, 'out_of_stock'));
    }

    public function test_a_restocking_product_is_listed_with_its_restock_date(): void
    {
        $prefix = $this->db->tablePrefix();
        $status = $this->db->prepare("SELECT id FROM {$prefix}productstatus WHERE restocking = 1 AND product_available = 0 AND deleted_at IS NULL ORDER BY id LIMIT 1")->single();
        if ($status === false) $this->markTestSkipped('No restocking product status exists');
        $product = $this->liveProduct(['status_id' => (int) $status['id']]);
        $this->db->prepare("UPDATE {$prefix}products SET restock_at = '2026-12-01' WHERE id = :id", [':id' => $product])->execute();

        $report = $this->report();

        $this->assertContains($product, $this->ids($report, 'restocking'));
        $this->assertNotContains($product, $this->ids($report, 'out_of_stock'));
        $item = \array_values(\array_filter($report['restocking']['items'], static fn(array $i): bool => (int) $i['id'] === $product))[0];
        $this->assertSame('2026-12-01', \substr((string) $item['restock_at'], 0, 10));
    }

    public function test_a_product_priced_at_zero_or_less_is_flagged_and_a_priced_one_is_not(): void
    {
        $free = $this->liveProduct(['price_exc_tax' => 0]);
        $priced = $this->liveProduct(['price_exc_tax' => 49.99]);

        $ids = $this->ids($this->report(), 'zero_price');

        $this->assertContains($free, $ids);
        $this->assertNotContains($priced, $ids);
    }

    public function test_a_product_is_in_no_category_unless_it_has_a_live_category_row(): void
    {
        $none = $this->liveProduct();
        $categorised = $this->liveProduct();
        $this->category($categorised);
        $removed = $this->liveProduct();
        $this->category($removed, deleted: true);

        $ids = $this->ids($this->report(), 'no_category');

        $this->assertContains($none, $ids);
        $this->assertNotContains($categorised, $ids);
        $this->assertContains($removed, $ids);
    }

    public function test_the_lists_are_capped_but_the_totals_are_not(): void
    {
        $before = $this->products->getAttentionReport(1)['zero_price']['total'];
        for ($i = 0; $i < 3; $i++) {
            $this->liveProduct(['price_exc_tax' => 0]);
        }

        $capped = $this->products->getAttentionReport(1);

        $this->assertSame($before + 3, $capped['zero_price']['total']);
        $this->assertCount(1, $capped['zero_price']['items']);
    }
}
