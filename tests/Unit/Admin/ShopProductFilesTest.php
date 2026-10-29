<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopProductFiles;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopProductFiles.php';

final class ShopProductFilesTest extends TestCase
{
    private const CATEGORIES = [
        1 => ['parent' => null, 'label' => 'Solar Panels', 'sort' => 2],
        5 => ['parent' => 1, 'label' => 'Lithium Batteries', 'sort' => 7],
        9 => ['parent' => 5, 'label' => 'Fogstar', 'sort' => 1],
    ];

    /** @param string[] $files */
    private function has(array $files): callable
    {
        return static fn(string $path): bool => \in_array($path, $files, true);
    }

    public function test_a_product_lists_its_documents_and_images_in_the_shops_own_layout(): void
    {
        $root = '/var/www/html/assets/images';
        $base = $root . '/shop/acme-co/panel-200w';
        $files = [
            $base . '/datasheet/acme-co-panel-200w-datasheet.pdf',
            $base . '/instructions/acme-co-panel-200w-instructions.pdf',
            $base . '/large/acme-co-panel-200w.jpg',
            $base . '/large/acme-co-panel-200w@2x.jpg',
            $base . '/extra/acme-co-panel-200w-extra1.jpg',
        ];

        $product = ShopProductFiles::shape(['id' => '7', 'brand_name' => 'Acme Co', 'model_name' => 'Panel 200W', 'category_id' => '9'], self::CATEGORIES, '/assets/images', $root, $this->has($files));

        $this->assertSame(7, $product['id']);
        $this->assertSame('Acme Co Panel 200W', $product['title']);
        $this->assertSame([['type' => 'datasheet', 'label' => 'Datasheet', 'url' => '/assets/images/shop/acme-co/panel-200w/datasheet/acme-co-panel-200w-datasheet.pdf'], ['type' => 'instructions', 'label' => 'Instructions', 'url' => '/assets/images/shop/acme-co/panel-200w/instructions/acme-co-panel-200w-instructions.pdf']], $product['documents']);
        $this->assertSame(['main', 'extra'], \array_column($product['images'], 'type'));
        $this->assertSame($base . '/large/acme-co-panel-200w@2x.jpg', $product['images'][0]['zipFile']);
        $this->assertSame('acme-co-panel-200w.jpg', $product['images'][0]['zipName']);
        $this->assertSame($base . '/extra/acme-co-panel-200w-extra1.jpg', $product['images'][1]['zipFile'], 'no 2x copy, so the plain file is zipped');
        $this->assertSame('acme-co-panel-200w-extra1.jpg', $product['images'][1]['zipName']);
    }

    public function test_extra_images_stop_at_the_first_gap(): void
    {
        $root = '/r';
        $base = $root . '/shop/a/b';
        $files = [$base . '/extra/a-b-extra1.jpg', $base . '/extra/a-b-extra2.jpg', $base . '/extra/a-b-extra4.jpg'];

        $product = ShopProductFiles::shape(['id' => 1, 'brand_name' => 'A', 'model_name' => 'B', 'category_id' => null], [], '/img', $root, $this->has($files));

        $this->assertSame(['Extra Image 1', 'Extra Image 2'], \array_column($product['images'], 'label'));
        $this->assertNull($product['category']);
        $this->assertSame([], $product['documents']);
    }

    public function test_a_product_with_no_files_is_left_out(): void
    {
        $this->assertNull(ShopProductFiles::shape(['id' => 1, 'brand_name' => 'A', 'model_name' => 'B', 'category_id' => 1], self::CATEGORIES, '/img', '/r', $this->has([])));
    }

    public function test_a_category_is_given_with_its_names_and_sort_numbers_from_the_top(): void
    {
        $this->assertSame(['id' => 9, 'name' => 'Fogstar', 'path' => ['Solar Panels', 'Lithium Batteries', 'Fogstar'], 'order' => [2, 7, 1]], ShopProductFiles::category(9, self::CATEGORIES));
        $this->assertSame(['id' => 1, 'name' => 'Solar Panels', 'path' => ['Solar Panels'], 'order' => [2]], ShopProductFiles::category(1, self::CATEGORIES));
        $this->assertNull(ShopProductFiles::category(42, self::CATEGORIES));
        $this->assertNull(ShopProductFiles::category(null, self::CATEGORIES));
    }

    public function test_a_category_loop_or_missing_parent_ends_the_path_instead_of_hanging(): void
    {
        $loop = [1 => ['parent' => 2, 'label' => 'A', 'sort' => 1], 2 => ['parent' => 1, 'label' => 'B', 'sort' => 2]];
        $orphan = [3 => ['parent' => 99, 'label' => 'C', 'sort' => 3]];

        $this->assertSame(['B', 'A'], ShopProductFiles::category(1, $loop)['path']);
        $this->assertSame(['C'], ShopProductFiles::category(3, $orphan)['path']);
    }

    public function test_category_rows_become_a_map_and_a_missing_sort_goes_last(): void
    {
        $map = ShopProductFiles::categoryMap([['id' => '4', 'parent_id' => null, 'label' => 'X', 'sort' => null], ['id' => 5, 'parent_id' => '4', 'label' => 'Y', 'sort' => '3']]);

        $this->assertSame([4 => ['parent' => null, 'label' => 'X', 'sort' => 9999], 5 => ['parent' => 4, 'label' => 'Y', 'sort' => 3]], $map);
    }

    public function test_asking_without_a_database_answers_null(): void
    {
        $this->assertNull(ShopProductFiles::files([]));
    }
}
