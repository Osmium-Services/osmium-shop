<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopBrandLookup;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopBrandLookup.php';

final class ShopBrandLookupTest extends TestCase
{
    public function test_brand_rows_are_shaped_with_an_int_id_and_a_name(): void
    {
        $this->assertSame(
            [['id' => 3, 'name' => 'Acme'], ['id' => 12, 'name' => 'Zed']],
            ShopBrandLookup::shape([['id' => '3', 'name' => 'Acme'], ['id' => 12, 'name' => 'Zed']]),
        );
        $this->assertSame([], ShopBrandLookup::shape([]));
    }

    public function test_asking_without_a_database_answers_null(): void
    {
        $this->assertNull(ShopBrandLookup::brands([]));
    }
}
