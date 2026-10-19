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
}
