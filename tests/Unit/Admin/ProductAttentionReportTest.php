<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Models;

use Osmium\Modules\Admin\Models\Product;
use PHPUnit\Framework\TestCase;

final class ProductAttentionReportTest extends TestCase
{
    public function test_unavailable_products_split_into_restocking_and_out_of_stock(): void
    {
        $rows = [
            $this->row(id: 1, available: 1),
            $this->row(id: 2, available: 0, restocking: 1, restockAt: '2026-11-01'),
            $this->row(id: 3, available: 0, restocking: 0),
        ];

        $report = Product::shapeAttentionReport($rows, 25);

        $this->assertSame(3, $report['live_products']);
        $this->assertSame(1, $report['available']);
        $this->assertSame([2], \array_column($report['restocking']['items'], 'id'));
        $this->assertSame('2026-11-01', $report['restocking']['items'][0]['restock_at']);
        $this->assertSame([3], \array_column($report['out_of_stock']['items'], 'id'));
    }

    public function test_it_flags_zero_price_and_missing_category(): void
    {
        $rows = [$this->row(id: 1, price: '0.000', hasCategory: 0), $this->row(id: 2)];

        $report = Product::shapeAttentionReport($rows, 25);

        $this->assertSame([1], \array_column($report['zero_price']['items'], 'id'));
        $this->assertSame([1], \array_column($report['no_category']['items'], 'id'));
    }

    public function test_it_caps_each_list_but_reports_the_full_total(): void
    {
        $rows = [$this->row(id: 1, available: 0), $this->row(id: 2, available: 0), $this->row(id: 3, available: 0)];

        $report = Product::shapeAttentionReport($rows, 2);

        $this->assertSame(3, $report['out_of_stock']['total']);
        $this->assertCount(2, $report['out_of_stock']['items']);
    }

    public function test_a_product_name_joins_brand_and_model(): void
    {
        $report = Product::shapeAttentionReport([$this->row(id: 1, available: 0)], 25);

        $this->assertSame('Acme Panel', $report['out_of_stock']['items'][0]['name']);
    }

    private function row(int $id, int $available = 1, int $restocking = 0, ?string $restockAt = null, string $price = '10.000', int $hasCategory = 1): array
    {
        return [
            'id' => $id, 'reference' => "REF{$id}", 'brand_name' => 'Acme', 'model_name' => 'Panel',
            'price_exc_tax' => $price, 'restock_at' => $restockAt,
            'available' => $available, 'restocking' => $restocking, 'has_category' => $hasCategory,
        ];
    }
}
