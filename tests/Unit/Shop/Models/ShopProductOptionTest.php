<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\SqlLoader;
use Osmium\Modules\Shop\Models\ShopProductOption;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Helpers\DatabaseMockHelper;

/**
 * Tests for ShopProductOption model
 *
 * Pattern 8: Database Query Execution
 * Pattern 9: Business Logic with PAGES resolution and data transformation
 */
class ShopProductOptionTest extends TestCase
{
    /**
     * Create a test instance with overridden pagesById for testing
     */
    private function createOptionWithPages(MockObject $mockDb, array $mockPages): ShopProductOption
    {
        return new class($mockDb, 'product_options', $mockPages) extends ShopProductOption {
            private static array $testPagesById;

            public function __construct($database, $tableName, $mockPages)
            {
                parent::__construct($database, $tableName);
                self::$testPagesById = $mockPages;
            }

            public static function pagesById(): array
            {
                return self::$testPagesById;
            }
        };
    }

    // =========================================================================
    // getAllOptionsForProduct() - Pattern 9: Business Logic
    // =========================================================================

    /**
     * @test
     * Pattern 9: Returns empty array when no options exist
     */
    public function test_get_all_options_returns_empty_for_no_options(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('resultset')
            ->willReturn([]);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Pattern 9: Resolves linked_product_url from PAGES cache
     */
    public function test_get_all_options_resolves_linked_product_url(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            50 => ['url' => 'shop/linked-product', 'deleted_at' => null],
        ];

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Wattage',
                'type_slug' => 'wattage',
                'default_option_descr' => '400W',
                'linked_product_id' => 999,
                'type_linked_image_slot' => null,
                'linked_product_descr' => 'Linked Product Description',
                'linked_product_title' => 'Linked Product',
                'linked_product_description' => 'Full description',
                'linked_product_brand' => 'Brand X',
                'linked_product_model' => 'Model Y',
                'linked_product_page_id' => 50,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 100.00,
                'option_premium' => 10.00,
                'option_descr' => '400W Option',
                'option_packed_weight' => 5.5,
                'option_reference' => 'REF-400',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertCount(1, $result);
        $this->assertEquals('shop/linked-product', $result[0]['linked_product_url']);
    }

    /**
     * @test
     * Pattern 9: Sets linked_product_url to null for deleted pages
     */
    public function test_get_all_options_sets_null_url_for_deleted_pages(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            50 => ['url' => 'shop/old-product', 'deleted_at' => '2024-01-01 00:00:00'],
        ];

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Size',
                'type_slug' => 'size',
                'default_option_descr' => 'Medium',
                'linked_product_id' => 999,
                'type_linked_image_slot' => null,
                'linked_product_descr' => 'Old Product',
                'linked_product_title' => 'Old Product',
                'linked_product_description' => 'Deleted',
                'linked_product_brand' => 'Brand',
                'linked_product_model' => 'Model',
                'linked_product_page_id' => 50,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => null,
                'option_rrp' => null,
                'option_premium' => null,
                'option_descr' => null,
                'option_packed_weight' => null,
                'option_reference' => null,
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => null,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['linked_product_url']);
    }

    /**
     * @test
     * Pattern 9: Sets linked_product_url to null when no linked product
     */
    public function test_get_all_options_sets_null_url_when_no_linked_product(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Color',
                'type_slug' => 'color',
                'default_option_descr' => 'Black',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 50.00,
                'option_premium' => 5.00,
                'option_descr' => 'Black Color',
                'option_packed_weight' => 1.0,
                'option_reference' => 'REF-BLK',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['linked_product_url']);
    }

    /**
     * @test
     * Pattern 9: Groups flat rows by type_id into nested structure
     */
    public function test_get_all_options_groups_by_type_id(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Wattage',
                'type_slug' => 'wattage',
                'default_option_descr' => '400W',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 100.00,
                'option_premium' => 10.00,
                'option_descr' => '400W',
                'option_packed_weight' => 5.5,
                'option_reference' => 'REF-400',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
            [
                'type_id' => 1,
                'type_label' => 'Wattage',
                'type_slug' => 'wattage',
                'default_option_descr' => '400W',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 20,
                'option_rrp' => 150.00,
                'option_premium' => 20.00,
                'option_descr' => '600W',
                'option_packed_weight' => 7.5,
                'option_reference' => 'REF-600',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 2,
            ],
            [
                'type_id' => 2,
                'type_label' => 'Color',
                'type_slug' => 'color',
                'default_option_descr' => 'Black',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 2,
                'value_id' => 30,
                'option_rrp' => 0.00,
                'option_premium' => 0.00,
                'option_descr' => 'Black',
                'option_packed_weight' => 0.0,
                'option_reference' => 'REF-BLK',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals('Wattage', $result[0]['type_label']);
        $this->assertCount(2, $result[0]['values']);
        $this->assertEquals(2, $result[1]['id']);
        $this->assertEquals('Color', $result[1]['type_label']);
        $this->assertCount(1, $result[1]['values']);
    }

    /**
     * @test
     * Pattern 9: Handles null value_id (LEFT JOIN case - no values for type)
     */
    public function test_get_all_options_handles_null_value_id(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Custom',
                'type_slug' => 'custom',
                'default_option_descr' => 'Default',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => null,
                'option_rrp' => null,
                'option_premium' => null,
                'option_descr' => null,
                'option_packed_weight' => null,
                'option_reference' => null,
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => null,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertCount(1, $result);
        $this->assertEquals('Custom', $result[0]['type_label']);
        $this->assertEmpty($result[0]['values']);
    }

    /**
     * @test
     * Pattern 9: Re-indexes array sequentially (0, 1, 2...)
     */
    public function test_get_all_options_reindexes_array_sequentially(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 5,
                'type_label' => 'TypeA',
                'type_slug' => 'type-a',
                'default_option_descr' => 'A',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 10.00,
                'option_premium' => 1.00,
                'option_descr' => 'ValueA',
                'option_packed_weight' => 1.0,
                'option_reference' => 'REF-A',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
            [
                'type_id' => 10,
                'type_label' => 'TypeB',
                'type_slug' => 'type-b',
                'default_option_descr' => 'B',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 2,
                'value_id' => 20,
                'option_rrp' => 20.00,
                'option_premium' => 2.00,
                'option_descr' => 'ValueB',
                'option_packed_weight' => 2.0,
                'option_reference' => 'REF-B',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getAllOptionsForProduct(productID: 100);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(5, $result);
        $this->assertArrayNotHasKey(10, $result);
    }

    // =========================================================================
    // DEPRECATED METHODS
    // =========================================================================

    /**
     * @test
     * Returns first option type values
     */
    public function test_get_options_by_product_id_returns_first_type_values(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Wattage',
                'type_slug' => 'wattage',
                'default_option_descr' => '400W',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 100.00,
                'option_premium' => 10.00,
                'option_descr' => '400W',
                'option_packed_weight' => 5.5,
                'option_reference' => 'REF-400',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getOptionsByProductID(productID: 100);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(10, $result[0]['id']);
    }

    /**
     * @test
     * Returns empty array when no options
     */
    public function test_get_options_by_product_id_returns_empty_when_no_options(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('resultset')
            ->willReturn([]);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getOptionsByProductID(productID: 100);

        $this->assertEmpty($result);
    }

    /**
     * @test
     * Returns second option type values
     */
    public function test_get_sub_options_by_product_id_returns_second_type_values(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Wattage',
                'type_slug' => 'wattage',
                'default_option_descr' => '400W',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 100.00,
                'option_premium' => 10.00,
                'option_descr' => '400W',
                'option_packed_weight' => 5.5,
                'option_reference' => 'REF-400',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
            [
                'type_id' => 2,
                'type_label' => 'Color',
                'type_slug' => 'color',
                'default_option_descr' => 'Black',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 2,
                'value_id' => 20,
                'option_rrp' => 0.00,
                'option_premium' => 0.00,
                'option_descr' => 'Black',
                'option_packed_weight' => 0.0,
                'option_reference' => 'REF-BLK',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getSubOptionsByProductID(productID: 100);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(20, $result[0]['id']);
    }

    /**
     * @test
     * Returns empty array when fewer than 2 types
     */
    public function test_get_sub_options_by_product_id_returns_empty_when_only_one_type(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM options');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockRows = [
            [
                'type_id' => 1,
                'type_label' => 'Wattage',
                'type_slug' => 'wattage',
                'default_option_descr' => '400W',
                'linked_product_id' => null,
                'type_linked_image_slot' => null,
                'linked_product_descr' => null,
                'linked_product_title' => null,
                'linked_product_description' => null,
                'linked_product_brand' => null,
                'linked_product_model' => null,
                'linked_product_page_id' => null,
                'linked_product_available' => 1,
                'linked_product_restocking' => 0,
                'linked_product_restock_at' => null,
                'default_linked_product_id' => null,
                'default_linked_product_available' => null,
                'default_linked_product_restocking' => null,
                'default_linked_product_restock_at' => null,
                'type_sort' => 1,
                'value_id' => 10,
                'option_rrp' => 100.00,
                'option_premium' => 10.00,
                'option_descr' => '400W',
                'option_packed_weight' => 5.5,
                'option_reference' => 'REF-400',
                'value_linked_product_id' => null,
                'value_linked_image_slot' => null,
                'linked_value_product_available' => 1,
                'linked_value_product_restocking' => 0,
                'linked_value_product_restock_at' => null,
                'value_sort' => 1,
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockRows);

        $option = $this->createOptionWithPages(mockDb: $mockDb, mockPages: []);

        $result = $option->getSubOptionsByProductID(productID: 100);

        $this->assertEmpty($result);
    }
}
