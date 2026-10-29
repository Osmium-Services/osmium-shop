<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopProductLookup;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopProductLookup.php';

final class ShopProductLookupTest extends TestCase
{
    public function test_a_product_row_is_shaped_with_real_types(): void
    {
        $row = ['id' => '12', 'brand_id' => '3', 'brand_name' => 'Acme', 'model_name' => 'Panel 400W', 'reference' => 'AC-400', 'price_exc_tax' => '199.500', 'available' => '1'];

        $this->assertSame(
            ['id' => 12, 'brandId' => 3, 'brandName' => 'Acme', 'name' => 'Panel 400W', 'reference' => 'AC-400', 'priceExcTax' => 199.5, 'available' => true],
            ShopProductLookup::shape($row),
        );
    }

    public function test_missing_optional_fields_become_empty_and_an_unavailable_status_is_false(): void
    {
        $shaped = ShopProductLookup::shape(['id' => 1, 'brand_id' => 1, 'brand_name' => null, 'model_name' => 'X', 'reference' => null, 'price_exc_tax' => '0.000', 'available' => 0]);

        $this->assertSame('', $shaped['brandName']);
        $this->assertSame('', $shaped['reference']);
        $this->assertFalse($shaped['available']);
    }

    public function test_asking_without_a_database_answers_null(): void
    {
        $this->assertNull(ShopProductLookup::get(['productId' => 12]));
    }
}
