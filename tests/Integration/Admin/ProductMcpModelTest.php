<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Product;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * The narrow product queries behind the MCP product tools, run against the real tables: the restock date changes
 * on its own, and the status check reads the real status flags.
 */
class ProductMcpModelTest extends ShopDatabaseTestCase
{
    private Product $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = new Product($this->db, 'products');
    }

    private function row(int $id): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->prepare(sql: "SELECT * FROM {$prefix}products WHERE id = :id", bindings: [':id' => $id])->single();
    }

    private function restockingStatusId(): ?int
    {
        $prefix = $this->db->tablePrefix();
        $row = $this->db->prepare(sql: "SELECT id FROM {$prefix}productstatus WHERE restocking = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->single();

        return $row ? (int) $row['id'] : null;
    }

    public function test_setting_the_restock_date_changes_only_that_column(): void
    {
        $id = $this->createTestProduct(['price_exc_tax' => 123.45]);
        $before = $this->row($id);

        $this->products->setRestockAt($id, '2027-01-05');
        $after = $this->row($id);

        $this->assertSame('2027-01-05', $after['restock_at']);
        foreach (['model_name', 'reference', 'price_exc_tax', 'rrp_exc_tax', 'descr', 'descr2', 'descr3', 'status_id', 'brand_id'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }
    }

    public function test_a_null_date_clears_the_restock_date(): void
    {
        $id = $this->createTestProduct();
        $this->products->setRestockAt($id, '2027-01-05');
        $this->products->setRestockAt($id, null);

        $this->assertNull($this->row($id)['restock_at']);
    }

    public function test_a_deleted_product_is_left_alone(): void
    {
        $id = $this->createTestProduct();
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $id])->execute();

        $this->products->setRestockAt($id, '2027-01-05');

        $this->assertNull($this->row($id)['restock_at']);
    }

    public function test_the_assistant_row_has_the_name_parts_and_the_restocking_flag(): void
    {
        $statusId = $this->restockingStatusId();
        if ($statusId === null) $this->markTestSkipped('No restocking status in this database');

        $restocking = $this->createTestProduct(['status_id' => $statusId, 'model_name' => 'Panel X']);
        $available = $this->createTestProduct(['status_id' => $this->statusId(true)]);

        $row = $this->products->getForAssistant($restocking);
        $this->assertSame('Panel X', $row['model_name']);
        $this->assertArrayHasKey('brand_name', $row);
        $this->assertSame(1, (int) $row['restocking']);
        $this->assertNull($row['deleted_at']);
        $this->assertSame(0, (int) $this->products->getForAssistant($available)['restocking']);
        $this->assertNull($this->products->getForAssistant(999999999));
    }

    public function test_the_audit_row_has_lengths_and_flags_but_never_the_text(): void
    {
        $id = $this->createTestProduct(['descr' => '  Secret copy about the product  ', 'descr2' => '', 'price_exc_tax' => 0]);
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "UPDATE {$prefix}products SET faq = 'Secret FAQ text', techspec = '', video_url = 'https://example.com/v', features = '3,4' WHERE id = :id",
            bindings: [':id' => $id],
        )->execute();

        $row = $this->products->getAuditForAssistant($id);

        $this->assertSame(\strlen('Secret copy about the product'), (int) $row['descr_length']);
        $this->assertSame(0, (int) $row['descr2_length']);
        $this->assertSame(\strlen('Secret FAQ text'), (int) $row['faq_length']);
        $this->assertSame(0, (int) $row['techspec_length']);
        $this->assertSame(1, (int) $row['video_set']);
        $this->assertSame('3,4', $row['features']);
        $this->assertSame(0.0, (float) $row['price_exc_tax']);
        $json = \json_encode($row);
        $this->assertStringNotContainsString('Secret', $json);
        $this->assertStringNotContainsString('example.com', $json);
    }

    public function test_a_binned_or_unknown_product_has_no_audit_row(): void
    {
        $id = $this->createTestProduct();
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $id])->execute();

        $this->assertNull($this->products->getAuditForAssistant($id));
        $this->assertNull($this->products->getAuditForAssistant(0));
    }

    public function test_only_live_categories_and_the_products_own_page_are_reported(): void
    {
        $id = $this->createTestProduct();
        $prefix = $this->db->tablePrefix();
        $tag = \substr(\uniqid(), -6);
        foreach (['Live' => null, 'Binned' => 'NOW()'] as $label => $deleted) {
            $this->db->prepare(sql: "INSERT INTO {$prefix}productcategories (label, deleted_at) VALUES (:label, " . ($deleted ?? 'NULL') . ')', bindings: [':label' => "{$label} {$tag}"])->execute();
            $categoryId = (int) $this->db->prepare(sql: "SELECT id FROM {$prefix}productcategories WHERE label = :label", bindings: [':label' => "{$label} {$tag}"])->single()['id'];
            $this->db->prepare(sql: "INSERT INTO {$prefix}products_productcategories (product_id, category_id) VALUES (:p, :c)", bindings: [':p' => $id, ':c' => $categoryId])->execute();
        }

        $labels = \array_column($this->products->getLiveCategoriesForAssistant($id), 'label');

        $this->assertSame(["Live {$tag}"], $labels);
        $this->assertNull($this->products->getPageSeoForAssistant($id));
    }
}
