<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopCategoryLookup;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopCategoryLookup.php';

final class ShopCategoryLookupTest extends TestCase
{
    public function test_categories_come_in_tree_order_with_their_parent_and_path(): void
    {
        $rows = [
            ['id' => '9', 'parent_id' => '5', 'label' => 'Fogstar', 'sort' => '1'],
            ['id' => '1', 'parent_id' => null, 'label' => 'Lighting', 'sort' => '1'],
            ['id' => '5', 'parent_id' => '2', 'label' => 'Batteries', 'sort' => '8'],
            ['id' => '2', 'parent_id' => null, 'label' => 'Panels', 'sort' => '0'],
        ];

        $categories = ShopCategoryLookup::shape($rows);

        $this->assertSame([2, 5, 9, 1], \array_column($categories, 'id'));
        $this->assertNull($categories[0]['parentId']);
        $this->assertSame(2, $categories[1]['parentId']);
        $this->assertSame(['Panels', 'Batteries', 'Fogstar'], $categories[2]['path']);
        $this->assertSame([0, 8, 1], $categories[2]['order']);
    }

    public function test_no_database_gives_nothing(): void
    {
        $this->assertNull(ShopCategoryLookup::categories([]));
    }
}
