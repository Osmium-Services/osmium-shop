<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Brand;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/Brand.php';

/**
 * The brand queries behind the MCP brand tools, run against the real tables: duplicate names are caught without
 * regard to case, and the lists count products the way the admin does.
 */
class BrandMcpModelTest extends DatabaseTestCase
{
    private Brand $brands;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brands = new Brand($this->db, 'productbrands');
    }

    public function test_a_name_in_use_by_a_live_brand_is_taken_whatever_its_case(): void
    {
        $name = 'Zed Brand ' . \uniqid();
        $id = $this->brands->insert($name, null, null);

        $this->assertTrue($this->brands->nameTaken($name, 0));
        $this->assertTrue($this->brands->nameTaken(\strtoupper($name), 0));
        $this->assertFalse($this->brands->nameTaken($name, $id), 'a brand does not clash with itself');
        $this->assertFalse($this->brands->nameTaken($name . ' two', 0));
    }

    public function test_a_deleted_brands_name_is_free_again(): void
    {
        $name = 'Zed Brand ' . \uniqid();
        $id = $this->brands->insert($name, null, null);
        $this->brands->softDelete($id);

        $this->assertFalse($this->brands->nameTaken($name, 0));
    }

    public function test_the_live_list_counts_only_live_products_and_the_bin_counts_all(): void
    {
        $id = $this->brands->insert('Zed Brand ' . \uniqid(), 'brands/zed', 'https://example.com/zed');
        $live = $this->createTestProduct(['brand_id' => $id]);
        $gone = $this->createTestProduct(['brand_id' => $id]);
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $gone])->execute();

        $row = $this->rowFor($this->brands->listWithCounts(), $id);
        $this->assertSame(1, (int) $row['product_count']);
        $this->assertSame('brands/zed', $row['page_url']);

        $this->assertSame(1, $this->brands->countActiveProducts($id));
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $live])->execute();
        $this->brands->softDelete($id);

        $binRow = $this->rowFor($this->brands->listDeleted(), $id);
        $this->assertSame(2, (int) $binRow['product_count']);
    }

    public function test_an_update_changes_only_the_three_editable_fields(): void
    {
        $id = $this->brands->insert('Zed Brand ' . \uniqid(), null, null);
        $before = $this->brands->getById($id);

        $this->brands->update($id, 'Renamed ' . \uniqid(), 'brands/new', 'https://example.com/new');
        $after = $this->brands->getById($id);

        $this->assertSame('brands/new', $after['page_url']);
        $this->assertSame($before['trade_discount_allowed'], $after['trade_discount_allowed']);
        $this->assertSame($before['created_at'], $after['created_at']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowFor(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) return $row;
        }

        $this->fail("Brand {$id} not in the list");
    }
}
