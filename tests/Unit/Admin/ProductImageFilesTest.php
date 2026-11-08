<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Osmium\Modules\Admin\Services\ProductImageFiles;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopPaths.php';
require_once 'app/services/osmium-shop/models/ProductImageFiles.php';

/**
 * Which generated copies of a product's main image exist on disk.
 */
class ProductImageFilesTest extends TestCase
{
    private string $root;
    private ?string $originalDocRoot;

    protected function setUp(): void
    {
        $this->originalDocRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $this->root = \sys_get_temp_dir() . '/productimagefiles-' . \bin2hex(\random_bytes(4));
        $_SERVER['DOCUMENT_ROOT'] = $this->root;
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->root));
        $_SERVER['DOCUMENT_ROOT'] = $this->originalDocRoot;
    }

    private function touch(string $relative): void
    {
        $path = $this->root . '/assets/images/shop/acme/widget/' . $relative;
        \mkdir(\dirname($path), 0755, true);
        \file_put_contents($path, 'x');
    }

    public function test_nothing_exists_for_a_product_with_no_images(): void
    {
        $this->assertSame(
            ['large' => false, 'medium' => false, 'thumb' => false, 'webp' => false, 'avif' => false],
            ProductImageFiles::derivations('acme', 'widget'),
        );
    }

    public function test_each_size_is_found_in_any_supported_extension(): void
    {
        $this->touch('large/acme-widget.jpg');
        $this->touch('medium/acme-widget.png');
        $this->touch('thumb/acme-widget.webp');

        $result = ProductImageFiles::derivations('acme', 'widget');

        $this->assertSame([true, true, true], [$result['large'], $result['medium'], $result['thumb']]);
    }

    public function test_the_webp_and_avif_masters_are_looked_for_under_large(): void
    {
        $this->touch('large/webp/acme-widget.webp');

        $result = ProductImageFiles::derivations('acme', 'widget');

        $this->assertTrue($result['webp']);
        $this->assertFalse($result['avif']);
    }

    public function test_another_products_images_are_not_counted(): void
    {
        $this->touch('large/acme-widget.jpg');

        $this->assertFalse(ProductImageFiles::derivations('acme', 'gadget')['large']);
    }
}
