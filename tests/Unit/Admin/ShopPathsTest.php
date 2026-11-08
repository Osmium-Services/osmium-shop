<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Osmium\Modules\Admin\Services\ShopPaths;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopPaths.php';

/**
 * The URL-safe brand and model names a product's images are stored under.
 */
class ShopPathsTest extends TestCase
{
    public function test_generate_brand_path_basic(): void
    {
        $result = ShopPaths::brand('Victron Energy');

        $this->assertEquals('victron-energy', $result);
    }

    public function test_generate_brand_path_strips_special_chars(): void
    {
        $result = ShopPaths::brand('EcoFlow / Delta');

        $this->assertEquals('ecoflow-delta', $result);
    }

    public function test_generate_brand_path_collapses_consecutive_hyphens(): void
    {
        $result = ShopPaths::brand('Brand  &&  Name');

        $this->assertEquals('brand-name', $result);
    }

    public function test_generate_brand_path_trims_edge_hyphens(): void
    {
        $result = ShopPaths::brand(' -Brand- ');

        $this->assertEquals('brand', $result);
    }

    public function test_generate_brand_path_handles_utf8(): void
    {
        $result = ShopPaths::brand('Büttner');

        $this->assertEquals('b-ttner', $result); // Non-ASCII chars replaced with hyphen
    }

    // =========================================================================
    // generateModelPath()
    // =========================================================================

    public function test_generate_model_path_basic(): void
    {
        $result = ShopPaths::model('MultiPlus II');

        $this->assertEquals('multiplus-ii', $result);
    }

    public function test_generate_model_path_converts_slashes_and_spaces(): void
    {
        $result = ShopPaths::model('MPPT 150/35');

        $this->assertEquals('mppt-15035', $result); // Slash stripped, space becomes hyphen
    }

    public function test_generate_model_path_strips_ampersand(): void
    {
        $result = ShopPaths::model('SmartSolar & BMV');

        $this->assertEquals('smartsolar-bmv', $result);
    }

    public function test_normalize_accepts_the_slugs_the_shop_generates(): void
    {
        $this->assertSame('acme-co', ShopPaths::normalize('acme-co'));
        $this->assertSame('x2', ShopPaths::normalize('x2'));
        $this->assertSame('brand', ShopPaths::normalize('brand'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePaths')]
    public function test_normalize_refuses_anything_that_could_leave_the_product_folder(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ShopPaths::normalize($path);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'parent folder' => ['..'],
            'parent in the middle' => ['a/../b'],
            'encoded parent' => ['&period;&period;'],
            'slash' => ['path/to/dir'],
            'slashes' => ['a/b/c'],
            'backslash' => ['a\\b'],
            'encoded slash' => ['acme&#x2F;co'],
            'upper case' => ['Brand'],
            'space' => ['Brand Co'],
            'leading hyphen' => ['-a'],
            'double hyphen' => ['a--b'],
            'null byte' => ["a\0b"],
        ];
    }

    public function test_product_dir_is_the_site_relative_folder_for_a_brand_and_model(): void
    {
        $this->assertSame('/assets/images/shop/acme/widget', ShopPaths::productDir('acme', 'widget'));
    }
}
