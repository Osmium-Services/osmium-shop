<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Services\ShopPageLinks;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * What the Osmium Shop tells core about pages through pages.delete_blockers, pages.owners and pages.reserved_ids: a page that
 * belongs to a product or a category cannot be deleted, a live product's page is the shop's, and every product page id stays spoken for.
 */
class ShopPageLinksTest extends ShopDatabaseTestCase
{
    private function page(): int
    {
        $prefix = $this->db->tablePrefix();
        $id = 1500 + \random_int(0, 400);
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}pages (id, parent_page_id, url, title, robots, created_at) VALUES (:id, NULL, :url, 'Test', 'index,follow', NOW())",
            bindings: [':id' => $id, ':url' => 'zz-shop-links-' . \uniqid()],
        )->execute();

        return $id;
    }

    private function blocker(int $pageId): ?string
    {
        return ShopPageLinks::deleteBlocker(['pageId' => $pageId, 'dataSource' => $this->db]);
    }

    public function test_a_page_nobody_claims_is_not_blocked(): void
    {
        $this->assertNull($this->blocker($this->page()));
    }

    public function test_a_products_page_is_blocked_with_the_product_name(): void
    {
        $page = $this->page();
        $product = $this->createTestProduct(['model_name' => 'Linked Widget']);
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $product, 'page_id' => $page]);

        $this->assertSame('Page is linked to product "Linked Widget"', $this->blocker($page));
    }

    public function test_a_categorys_page_is_blocked_with_the_category_label(): void
    {
        $page = $this->page();
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productcategories', data: ['label' => 'Locked category', 'parent_id' => null, 'page_id' => $page]);

        $this->assertSame('Page is the page for category "Locked category"', $this->blocker($page));
    }

    public function test_a_live_products_page_is_owned_by_the_shop_and_kept_out_of_the_parent_choices(): void
    {
        $page = $this->page();
        $product = $this->createTestProduct(['model_name' => 'Owned Widget']);
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $product, 'page_id' => $page]);

        $owners = ShopPageLinks::owners(['dataSource' => $this->db]);

        $this->assertSame('Shop product "Owned Widget"', $owners[$page]['label']);
        $this->assertSame('shop/products/edit/?id=' . $product, $owners[$page]['editUrl']);
        $this->assertFalse($owners[$page]['canBeParent']);
    }

    public function test_a_deleted_products_page_is_not_owned(): void
    {
        $page = $this->page();
        $product = $this->createTestProduct();
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $product, 'page_id' => $page]);
        $this->db->prepare(sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id', bindings: [':id' => $product])->execute();

        $this->assertArrayNotHasKey($page, ShopPageLinks::owners(['dataSource' => $this->db]));
    }

    public function test_every_products_page_id_stays_reserved_even_when_the_product_is_deleted(): void
    {
        $page = $this->page();
        $product = $this->createTestProduct();
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $product, 'page_id' => $page]);
        $this->db->prepare(sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id', bindings: [':id' => $product])->execute();

        $this->assertContains($page, ShopPageLinks::reservedIds(['dataSource' => $this->db])['ids']);
    }
}
