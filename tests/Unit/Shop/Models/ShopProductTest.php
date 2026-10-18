<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\SqlLoader;
use Osmium\Modules\Shop\Models\ShopProduct;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Helpers\DatabaseMockHelper;

/**
 * Tests for ShopProduct model
 *
 * Pattern 8: Database Query Execution
 * Pattern 9: Business Logic with PAGES resolution and data transformation
 */
class ShopProductTest extends TestCase
{
    /**
     * Create a test instance with overridden pagesById for testing
     */
    private function createProductWithPages(MockObject $mockDb, array $mockPages): ShopProduct
    {
        return new class($mockDb, 'products', $mockPages) extends ShopProduct {
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
    // loadByID() - Pattern 9: Business Logic with PAGES Resolution
    // =========================================================================

    /**
     * @test
     * Pattern 9: Returns array when product + valid page found
     */
    public function test_load_by_id_returns_array_when_found(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/brand/model', 'deleted_at' => null],
        ];

        $mockData = [
            'page_id' => 100,
            'category_id' => 5,
            'brand_name' => 'Victron Energy',
            'model_name' => 'MultiPlus II 48/5000',
            'price_exc_tax' => 1200.00,
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $product->loadByID(productID: 42);

        $this->assertIsArray($result);
        $this->assertEquals('shop/brand/model', $result['url']);
    }

    /**
     * @test
     * Pattern 9: Returns empty array when database returns no result
     * Note: getProductDataByID converts false to [], so loadByID returns []
     */
    public function test_load_by_id_returns_empty_when_not_found(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('single')
            ->willReturn(false);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $result = $product->loadByID(productID: 999);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
        $this->assertSame(0, $product->id); // A missing product leaves the model unloaded
    }

    /**
     * @test
     * Pattern 9: Returns empty array when page is deleted (deleted_at set)
     */
    public function test_load_by_id_returns_empty_when_page_deleted(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/old-product', 'deleted_at' => '2024-01-01 00:00:00'],
        ];

        $mockData = [
            'page_id' => 100,
            'brand_name' => 'Old Brand',
            'model_name' => 'Old Model',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $product->loadByID(productID: 42);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Pattern 9: Returns empty array when page_id missing from PAGES cache
     */
    public function test_load_by_id_returns_empty_when_page_missing(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            50 => ['url' => 'shop/existing', 'deleted_at' => null],
        ];

        $mockData = [
            'page_id' => 999, // Not in mockPages
            'brand_name' => 'Brand',
            'model_name' => 'Model',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $product->loadByID(productID: 42);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     * Pattern 9: Sets internal properties correctly
     */
    public function test_load_by_id_sets_internal_properties(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/victron/multiplus', 'deleted_at' => null],
        ];

        $mockData = [
            'page_id' => 100,
            'category_id' => 5,
            'brand_name' => 'Victron Energy',
            'model_name' => 'MultiPlus II 48/5000',
            'price_exc_tax' => '1200.50',
            'rrp_exc_tax' => '1500.00',
            'product_available' => 10,
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $product->loadByID(productID: 42);

        $this->assertEquals(42, $product->id);
        $this->assertEquals(5, $product->categoryID);
        $this->assertEquals('Victron Energy', $product->brandName);
        $this->assertEquals('MultiPlus II 48/5000', $product->model);
        $this->assertEquals(1200.50, $product->priceExcTax);
        $this->assertEquals(1500.00, $product->rrpExcTax);
        $this->assertEquals(10, $product->available);
        $this->assertEquals('Victron Energy MultiPlus II 48/5000', $product->title);
        $this->assertEquals('victron-energy', $product->brandPath);
        $this->assertEquals('multiplus-ii-485000', $product->modelPath); // Slash removed before hyphen conversion
    }

    // =========================================================================

    // =========================================================================
    // loadMultipleByIDs() - Pattern 9: Batch Loading with Sanitization
    // =========================================================================

    /**
     * @test
     * Pattern 9: Returns empty array for empty input
     */
    public function test_load_multiple_by_ids_returns_empty_for_empty_input(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $result = $product->loadMultipleByIDs(productIDs: []);

        $this->assertEmpty($result);
    }

    /**
     * @test
     * Pattern 9: Sanitizes IDs - filters zeros
     */
    public function test_load_multiple_by_ids_filters_zero_ids(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id IN (:productIDs)');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('resultset')
            ->willReturn([]);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $result = $product->loadMultipleByIDs(productIDs: [0, 0, 0]);

        $this->assertEmpty($result);
    }

    /**
     * @test
     * Pattern 9: Sanitizes IDs - removes duplicates
     */
    public function test_load_multiple_by_ids_removes_duplicates(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id IN (:productIDs)');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/product', 'deleted_at' => null],
        ];

        $mockResults = [
            [
                'id' => '10',
                'page_id' => 100,
                'brand_name' => 'Brand',
                'model_name' => 'Model',
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $mockDb->expects($this->once())
            ->method('bind')
            ->with(':id0', 10); // Only one bind call despite [10, 10, 10]

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $product->loadMultipleByIDs(productIDs: [10, 10, 10]);
    }

    /**
     * @test
     * Pattern 9: Skips products with deleted pages
     */
    public function test_load_multiple_by_ids_skips_deleted_pages(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id IN (:productIDs)');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/valid', 'deleted_at' => null],
            200 => ['url' => 'shop/deleted', 'deleted_at' => '2024-01-01'],
        ];

        $mockResults = [
            [
                'id' => '10',
                'page_id' => 100,
                'brand_name' => 'Valid',
                'model_name' => 'Product',
            ],
            [
                'id' => '20',
                'page_id' => 200,
                'brand_name' => 'Deleted',
                'model_name' => 'Product',
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $product->loadMultipleByIDs(productIDs: [10, 20]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(10, $result);
        $this->assertArrayNotHasKey(20, $result);
    }

    /**
     * @test
     * Pattern 9: Adds computed fields (title, brand_path, model_path)
     */
    public function test_load_multiple_by_ids_adds_computed_fields(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id IN (:productIDs)');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/product', 'deleted_at' => null],
        ];

        $mockResults = [
            [
                'id' => '10',
                'page_id' => 100,
                'brand_name' => 'Victron Energy',
                'model_name' => 'MultiPlus II',
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $product->loadMultipleByIDs(productIDs: [10]);

        $this->assertEquals('Victron Energy MultiPlus II', $result[10]['title']);
        $this->assertEquals('victron-energy', $result[10]['brand_path']);
        $this->assertEquals('multiplus-ii', $result[10]['model_path']);
    }

    /**
     * @test
     * Pattern 9: Returns array indexed by product ID
     */
    public function test_load_multiple_by_ids_indexes_by_product_id(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id IN (:productIDs)');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/p1', 'deleted_at' => null],
            200 => ['url' => 'shop/p2', 'deleted_at' => null],
        ];

        $mockResults = [
            [
                'id' => '42',
                'page_id' => 100,
                'brand_name' => 'Brand A',
                'model_name' => 'Model A',
            ],
            [
                'id' => '99',
                'page_id' => 200,
                'brand_name' => 'Brand B',
                'model_name' => 'Model B',
            ],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $product->loadMultipleByIDs(productIDs: [42, 99]);

        $this->assertArrayHasKey(42, $result);
        $this->assertArrayHasKey(99, $result);
        $this->assertEquals('Brand A', $result[42]['brand_name']);
        $this->assertEquals('Brand B', $result[99]['brand_name']);
    }

    // =========================================================================
    // all() - Pattern 8: Query Execution with Optional Filtering
    // =========================================================================

    /**
     * @test
     * Pattern 8: Uses category-filtered SQL when categoryId provided
     */
    public function test_all_uses_category_filter_when_provided(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE category_id = :categoryId');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->expects($this->once())
            ->method('bind')
            ->with(':categoryId', '5');

        $mockDb->method('resultset')
            ->willReturn([]);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $product->all(categoryId: '5');
    }

    /**
     * @test
     * Pattern 8: Uses all-products SQL when categoryId null
     */
    public function test_all_uses_no_filter_when_category_null(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->expects($this->never())
            ->method('bind');

        $mockDb->method('resultset')
            ->willReturn([]);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $product->all(categoryId: null);
    }

    // =========================================================================
    // count() - Pattern 8: Aggregation Query
    // =========================================================================

    /**
     * @test
     * Pattern 8: Returns integer from string database value
     */
    public function test_count_returns_integer(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT COUNT(*) as total FROM products');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('single')
            ->willReturn(['total' => '42']);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $result = $product->count();

        $this->assertIsInt($result);
        $this->assertEquals(42, $result);
    }

    /**
     * @test
     * Pattern 8: Returns 0 when 'total' key missing
     */
    public function test_count_returns_zero_when_total_missing(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT COUNT(*) as total FROM products');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('single')
            ->willReturn([]);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $result = $product->count();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // rangeByCategory() - Pattern 8: Query with Conditional SQL
    // =========================================================================

    /**
     * @test
     * Pattern 8: Appends stock filter when includeOutOfStock=false
     */
    public function test_range_by_category_excludes_out_of_stock_when_false(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $baseSql = 'SELECT * FROM products WHERE category_id IN (:categoryIDs) ORDER BY product_model';
        $mockSqlLoader->method('load')->willReturn($baseSql);
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->expects($this->once())
            ->method('query')
            ->with($this->stringContains('AND P.product_status_id NOT IN (3,4)'));

        $mockDb->method('single')
            ->willReturn([]);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $product->rangeByCategory(
            categoryIDs: '1,2,3',
            includeOutOfStock: false
        );
    }

    /**
     * @test
     * Pattern 8: No stock filter when includeOutOfStock=true
     */
    public function test_range_by_category_includes_out_of_stock_when_true(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $baseSql = 'SELECT * FROM products WHERE category_id IN (:categoryIDs) ORDER BY product_model';
        $mockSqlLoader->method('load')->willReturn($baseSql);
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->expects($this->once())
            ->method('query')
            ->with($this->logicalNot($this->stringContains('product_status_id NOT IN')));

        $mockDb->method('single')
            ->willReturn([]);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: []);

        $product->rangeByCategory(
            categoryIDs: '1,2,3',
            includeOutOfStock: true
        );
    }

    // =========================================================================
    // imagePath() - Pattern 9: String Construction
    // =========================================================================

    /**
     * @test
     * Pattern 9: Builds correct path with all parameters
     */
    public function test_image_path_builds_correct_path(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/victron/multiplus', 'deleted_at' => null],
        ];

        $mockData = [
            'page_id' => 100,
            'brand_name' => 'Victron Energy',
            'model_name' => 'MultiPlus II',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);
        $product->loadByID(productID: 42);

        $result = $product->imagePath(
            imageSize: 'large',
            imageType: 'front',
            suffix: '-v2.jpg'
        );

        $expected = '/shop/victron-energy/multiplus-ii/large/victron-energy-multiplus-ii-front-v2.jpg';
        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     * Pattern 9: Prefixes imageType with hyphen when non-empty
     */
    public function test_image_path_adds_hyphen_to_image_type(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/brand/model', 'deleted_at' => null],
        ];

        $mockData = [
            'page_id' => 100,
            'brand_name' => 'Brand',
            'model_name' => 'Model',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);
        $product->loadByID(productID: 1);

        $result = $product->imagePath(
            imageSize: 'medium',
            imageType: 'back'
        );

        $this->assertStringContainsString('-back', $result);
    }

    /**
     * @test
     * Pattern 9: Omits hyphen when imageType empty
     */
    public function test_image_path_omits_hyphen_when_image_type_empty(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/brand/model', 'deleted_at' => null],
        ];

        $mockData = [
            'page_id' => 100,
            'brand_name' => 'Brand',
            'model_name' => 'Model',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => '2024-01-01 00:00:00',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);
        $product->loadByID(productID: 1);

        $result = $product->imagePath(
            imageSize: 'medium',
            imageType: '',
            suffix: '.jpg'
        );

        $expected = '/shop/brand/model/medium/brand-model.jpg';
        $this->assertEquals($expected, $result);
    }

    // =========================================================================
    // isNew() - Pattern 9: Date Comparison
    // =========================================================================

    /**
     * @test
     * Pattern 9: Returns true when addedDate < 1 month ago
     */
    public function test_is_new_returns_true_for_recent_product(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/product', 'deleted_at' => null],
        ];

        $recentDate = date('Y-m-d H:i:s', strtotime('-2 weeks'));

        $mockData = [
            'page_id' => 100,
            'brand_name' => 'Brand',
            'model_name' => 'Model',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => $recentDate,
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);
        $product->loadByID(productID: 1);

        $result = $product->isNew();

        $this->assertTrue($result);
    }

    /**
     * @test
     * Pattern 9: Returns false when addedDate > 1 month ago
     */
    public function test_is_new_returns_false_for_old_product(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM products WHERE id = :productID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            100 => ['url' => 'shop/product', 'deleted_at' => null],
        ];

        $oldDate = date('Y-m-d H:i:s', strtotime('-2 months'));

        $mockData = [
            'page_id' => 100,
            'brand_name' => 'Brand',
            'model_name' => 'Model',
            'kit_link' => null,
            'restock_at' => null,
            'created_at' => $oldDate,
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $product = $this->createProductWithPages(mockDb: $mockDb, mockPages: $mockPages);
        $product->loadByID(productID: 1);

        $result = $product->isNew();

        $this->assertFalse($result);
    }
}
