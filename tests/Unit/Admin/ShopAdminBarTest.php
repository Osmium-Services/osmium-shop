<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Osmium\Modules\Admin\Services\ShopAdminBar;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopAdminBar.php';

final class ShopAdminBarTest extends TestCase
{
    public function test_a_product_page_is_announced_by_its_id(): void
    {
        $context = ShopAdminBar::context(['data' => ['product' => ['id' => 42]]]);

        $this->assertSame('product:42', $context);
    }

    public function test_a_category_page_is_announced_by_its_id(): void
    {
        $context = ShopAdminBar::context(['data' => ['category' => ['ID' => 7, 'label' => 'Bolts']]]);

        $this->assertSame('category:7', $context);
    }

    public function test_the_shop_landing_page_is_announced(): void
    {
        $context = ShopAdminBar::context(['data' => ['shop' => ['listTotal' => 120, 'categoryTree' => []]]]);

        $this->assertSame('shop-index:all', $context);
    }

    public function test_a_page_with_only_the_category_tree_is_not_the_shop_landing_page(): void
    {
        $this->assertNull(ShopAdminBar::context(['data' => ['shop' => ['categoryTree' => []]]]));
    }

    public function test_the_shop_landing_page_links_to_the_category_editor(): void
    {
        $links = ShopAdminBar::links(['context' => 'shop-index:all']);

        $this->assertSame('shop/categories/', $links[0]['path']);
        $this->assertSame('bx-news', $links[0]['icon']);
        $this->assertTrue($links[0]['primary']);
    }

    public function test_a_product_wins_over_a_category_on_the_same_page(): void
    {
        $context = ShopAdminBar::context(['data' => ['product' => ['id' => 3], 'category' => ['ID' => 9]]]);

        $this->assertSame('product:3', $context);
    }

    public function test_other_pages_announce_nothing(): void
    {
        $this->assertNull(ShopAdminBar::context(['data' => ['page' => ['title' => 'About']]]));
        $this->assertNull(ShopAdminBar::context(['data' => ['product' => ['id' => 0]]]));
        $this->assertNull(ShopAdminBar::context([]));
    }

    public function test_the_product_link_opens_that_products_edit_page(): void
    {
        $links = ShopAdminBar::links(['context' => 'product:42', 'role' => 'editor']);

        $this->assertSame('shop/products/edit/?id=42', $links[0]['path']);
        $this->assertSame('Edit product', $links[0]['label']);
        $this->assertTrue($links[0]['primary']);
        $this->assertSame('bx-edit-alt', $links[0]['icon']);
    }

    public function test_the_category_link_opens_that_categorys_edit_form(): void
    {
        $links = ShopAdminBar::links(['context' => 'category:7']);

        $this->assertSame('shop/categories/?edit=7', $links[0]['path']);
        $this->assertSame('Edit category', $links[0]['label']);
        $this->assertTrue($links[0]['primary']);
    }

    public function test_another_services_context_gets_no_link(): void
    {
        $this->assertNull(ShopAdminBar::links(['context' => 'post:5']));
        $this->assertNull(ShopAdminBar::links(['context' => 'product:abc']));
        $this->assertNull(ShopAdminBar::links([]));
    }

    public function test_the_manifest_registers_both_handlers(): void
    {
        $manifest = \json_decode((string)\file_get_contents('app/services/osmium-shop/service.json'), true);

        $this->assertContains('Osmium\\Modules\\Admin\\Services\\ShopAdminBar::context', $manifest['hooks']['adminbar.context']);
        $this->assertContains('Osmium\\Modules\\Admin\\Services\\ShopAdminBar::links', $manifest['hooks']['adminbar.links']);
    }
}
