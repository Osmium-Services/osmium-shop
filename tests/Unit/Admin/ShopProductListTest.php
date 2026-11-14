<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopProductList;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopProductList.php';

final class ShopProductListTest extends TestCase
{
    private const CATEGORIES = [
        1 => ['parent' => null, 'label' => 'Solar Panels', 'sort' => 2],
        5 => ['parent' => 1, 'label' => 'Lithium Batteries', 'sort' => 7],
    ];

    private const ROW = [
        'id' => '7', 'brand_id' => '3', 'brand_name' => 'Acme Co', 'model_name' => 'Panel 200W', 'reference' => 'AC-200',
        'price_exc_tax' => '120.5000', 'available' => '1', 'status_label' => 'In stock', 'has_options' => '0', 'category_id' => '5',
    ];

    public function test_a_product_is_shaped_for_another_service_with_its_category_path(): void
    {
        $product = ShopProductList::shape(self::ROW, self::CATEGORIES, '/assets/images', '/var/www/html/assets/images', static fn(string $path): bool => false);

        $this->assertSame(7, $product['id']);
        $this->assertSame(3, $product['brandId']);
        $this->assertSame('Acme Co Panel 200W', $product['title']);
        $this->assertSame('AC-200', $product['reference']);
        $this->assertSame(120.5, $product['priceExcTax']);
        $this->assertTrue($product['available']);
        $this->assertFalse($product['hasOptions']);
        $this->assertSame(['Solar Panels', 'Lithium Batteries'], $product['category']['path']);
        $this->assertSame([2, 7], $product['category']['order']);
    }

    public function test_the_image_is_given_only_when_the_medium_file_exists(): void
    {
        $file = '/var/www/html/assets/images/shop/acme-co/panel-200w/medium/acme-co-panel-200w.jpg';

        $with = ShopProductList::shape(self::ROW, self::CATEGORIES, '/assets/images', '/var/www/html/assets/images', static fn(string $path): bool => $path === $file);
        $without = ShopProductList::shape(self::ROW, self::CATEGORIES, '/assets/images', '/var/www/html/assets/images', static fn(string $path): bool => false);

        $this->assertSame('/assets/images/shop/acme-co/panel-200w/medium/acme-co-panel-200w.jpg', $with['image']);
        $this->assertNull($without['image']);
    }

    public function test_a_product_in_no_category_has_none_and_an_unavailable_one_says_so(): void
    {
        $row = ['category_id' => null, 'available' => '0', 'has_options' => '1', 'status_label' => 'Out of stock'] + self::ROW;

        $product = ShopProductList::shape($row, self::CATEGORIES, '/assets/images', '/root', static fn(string $path): bool => false);

        $this->assertNull($product['category']);
        $this->assertFalse($product['available']);
        $this->assertTrue($product['hasOptions']);
        $this->assertSame('Out of stock', $product['statusLabel']);
    }

    public function test_no_database_gives_nothing(): void
    {
        $this->assertNull(ShopProductList::list([]));
    }
}
