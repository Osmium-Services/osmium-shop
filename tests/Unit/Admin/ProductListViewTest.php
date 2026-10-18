<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ProductListView;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ProductListView.php';

/**
 * ProductListView prepares the admin products list's badges and display values.
 */
class ProductListViewTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7, 'brand_name' => 'Acme', 'model_name' => 'Panel', 'price' => '100.00',
            'available' => 1, 'has_category' => 1, 'created' => '2026-01-02 10:30:00',
        ];
    }

    public function test_indexed_page_is_visible(): void
    {
        $result = ProductListView::product(product: $this->row(['robots' => 'index,follow']), taxRate: 20);

        $this->assertSame('visible', $result['seoStatus']);
        $this->assertSame('success', $result['seoClass']);
        $this->assertSame('Allow search engines to index: index,follow', $result['seoTitle']);
    }

    public function test_noindex_follow_is_partial(): void
    {
        $result = ProductListView::product(product: $this->row(['robots' => 'noindex,follow']), taxRate: 20);

        $this->assertSame('partial', $result['seoStatus']);
        $this->assertSame('warning', $result['seoClass']);
    }

    public function test_noindex_nofollow_is_blocked(): void
    {
        $result = ProductListView::product(product: $this->row(['robots' => 'noindex,nofollow']), taxRate: 20);

        $this->assertSame('blocked', $result['seoStatus']);
        $this->assertSame('danger', $result['seoClass']);
    }

    public function test_robots_defaults_to_indexed(): void
    {
        $result = ProductListView::product(product: $this->row(), taxRate: 20);

        $this->assertSame('index,follow', $result['robots']);
        $this->assertSame('visible', $result['seoStatus']);
    }

    public function test_available_with_category_is_buyable(): void
    {
        $result = ProductListView::product(product: $this->row(), taxRate: 20);

        $this->assertTrue($result['isAvailable']);
        $this->assertSame('Available to buy', $result['availTitle']);
    }

    public function test_no_category_is_hidden(): void
    {
        $result = ProductListView::product(product: $this->row(['has_category' => 0]), taxRate: 20);

        $this->assertFalse($result['isAvailable']);
        $this->assertSame('Hidden (no category)', $result['availTitle']);
    }

    public function test_unavailable_with_category_is_not_available_to_buy(): void
    {
        $result = ProductListView::product(product: $this->row(['available' => 0]), taxRate: 20);

        $this->assertSame('Not available to buy', $result['availTitle']);
        $this->assertSame('danger', $result['availClass']);
    }

    public function test_price_includes_vat(): void
    {
        $result = ProductListView::product(product: $this->row(), taxRate: 20);

        $this->assertEqualsWithDelta(100.0, $result['price_exc_tax'], 0.001);
        $this->assertEqualsWithDelta(120.0, $result['priceIncVat'], 0.001);
    }

    public function test_title_and_updated_date_fall_back(): void
    {
        $result = ProductListView::product(product: $this->row(), taxRate: 20);

        $this->assertSame('Acme Panel', $result['productTitle']);
        $this->assertSame('02 Jan 10:30', $result['updatedFormatted']);
    }

    public function test_updated_date_shows_dash_when_no_dates(): void
    {
        $result = ProductListView::product(product: $this->row(['created' => null]), taxRate: 20);

        $this->assertSame('-', $result['updatedFormatted']);
    }

    public function test_deleted_product_formats_date_and_title(): void
    {
        $result = ProductListView::deletedProduct(product: [
            'id' => 3, 'brand_name' => 'Acme', 'model_name' => 'Panel', 'deleted_at' => '2026-03-04 09:05:00',
        ]);

        $this->assertSame('Acme Panel', $result['productTitle']);
        $this->assertSame('04 Mar 2026, 09:05', $result['deletedFormatted']);
    }

    public function test_deleted_product_without_date_shows_dash(): void
    {
        $result = ProductListView::deletedProduct(product: ['id' => 3]);

        $this->assertSame('-', $result['deletedFormatted']);
    }
}
