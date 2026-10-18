<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\SqlLoader;
use Osmium\Modules\Shop\Models\ShopProductCategory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Helpers\DatabaseMockHelper;

/**
 * Tests for ShopProductCategory model
 *
 * Pattern 8: Database Query Execution
 * Pattern 9: Business Logic with PAGES constant resolution
 */
class ShopProductCategoryTest extends TestCase
{
    /**
     * Create a test instance with overridden pagesById for testing
     */
    private function createCategoryWithPages(MockObject $mockDb, array $mockPages): ShopProductCategory
    {
        return new class($mockDb, 'product_categories', $mockPages) extends ShopProductCategory {
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
    // loadByID() - Pattern 8: Query Execution with Property Assignment
    // =========================================================================

    /**
     * @test
     * Pattern 8: Returns false when categoryID < 1
     */
    public function test_load_by_id_returns_false_for_invalid_id(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: []);

        $result = $category->loadByID(categoryID: 0);

        $this->assertFalse($result);
    }

    /**
     * @test
     * Pattern 8: Returns false when categoryID is null
     */
    public function test_load_by_id_returns_false_for_null_id(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: []);

        $result = $category->loadByID(categoryID: null);

        $this->assertFalse($result);
    }

    /**
     * @test
     * Pattern 8: Returns false when database returns no data
     */
    public function test_load_by_id_returns_false_when_not_found(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM categories WHERE id = :categoryID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('single')
            ->willReturn(false);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: []);

        $result = $category->loadByID(categoryID: 99);

        $this->assertFalse($result);
    }

    /**
     * @test
     * Pattern 8: Returns true and sets all properties when found
     */
    public function test_load_by_id_sets_properties_when_found(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM categories WHERE id = :categoryID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockData = [
            'parent_id' => 5,
            'label' => 'Solar Panels',
            'title' => 'Solar Panel Products',
            'subtitle' => 'High efficiency panels',
            'sort' => 10,
            'description' => 'Our range of solar panels',
            'social_image' => 'solar-panels.jpg',
        ];

        $mockDb->method('single')
            ->willReturn($mockData);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: []);

        $result = $category->loadByID(categoryID: 42);

        $this->assertTrue($result);
        $this->assertEquals(42, $category->id);
        $this->assertEquals(5, $category->parentID);
        $this->assertEquals('Solar Panels', $category->label);
        $this->assertEquals('Solar Panel Products', $category->title);
        $this->assertEquals('High efficiency panels', $category->subtitle);
        $this->assertEquals(10, $category->sort);
        $this->assertEquals('Our range of solar panels', $category->description);
        $this->assertEquals('solar-panels.jpg', $category->socialImage);
    }

    /**
     * @test
     * Pattern 8: Handles null coalescing defaults for all properties
     */
    public function test_load_by_id_uses_defaults_for_missing_fields(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM categories WHERE id = :categoryID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockDb->method('single')
            ->willReturn([]); // Empty data - all fields missing

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: []);

        $result = $category->loadByID(categoryID: 1);

        $this->assertTrue($result);
        $this->assertEquals(1, $category->id);
        $this->assertEquals(0, $category->parentID);
        $this->assertEquals('', $category->label);
        $this->assertEquals('', $category->title);
        $this->assertEquals('', $category->subtitle);
        $this->assertEquals(0, $category->sort);
        $this->assertEquals('', $category->description);
        $this->assertEquals('', $category->socialImage);
    }

    // =========================================================================
    // subcategories() - Pattern 9: Business Logic with PAGES Resolution
    // =========================================================================

    /**
     * @test
     * Pattern 9: Returns subcategories with resolved page URLs
     */
    public function test_subcategories_resolves_page_urls(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM categories WHERE parent_id = :thisID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            10 => ['url' => 'shop/inverters', 'deleted_at' => null],
            20 => ['url' => 'shop/batteries', 'deleted_at' => null],
        ];

        $mockResults = [
            ['id' => 1, 'label' => 'Inverters', 'page_id' => 10],
            ['id' => 2, 'label' => 'Batteries', 'page_id' => 20],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $category->subcategories();

        $this->assertCount(2, $result);
        $this->assertEquals('shop/inverters', $result[0]['link']);
        $this->assertEquals('shop/batteries', $result[1]['link']);
    }

    /**
     * @test
     * Pattern 9: Sets link to null when page is deleted
     */
    public function test_subcategories_sets_null_link_for_deleted_pages(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM categories WHERE parent_id = :thisID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            10 => ['url' => 'shop/old-category', 'deleted_at' => '2024-01-01 00:00:00'],
        ];

        $mockResults = [
            ['id' => 1, 'label' => 'Old Category', 'page_id' => 10],
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $category->subcategories();

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['link']);
    }

    /**
     * @test
     * Pattern 9: Sets link to null when page_id not in PAGES cache
     */
    public function test_subcategories_sets_null_link_for_missing_pages(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        DatabaseMockHelper::mockTablePrefix($mockDb);

        $mockSqlLoader = $this->createMock(SqlLoader::class);
        $mockSqlLoader->method('load')->willReturn('SELECT * FROM categories WHERE parent_id = :thisID');
        $mockDb->sqlLoader = $mockSqlLoader;

        $mockPages = [
            10 => ['url' => 'shop/existing', 'deleted_at' => null],
        ];

        $mockResults = [
            ['id' => 1, 'label' => 'Missing Page', 'page_id' => 999], // Page ID not in mockPages
        ];

        $mockDb->method('resultset')
            ->willReturn($mockResults);

        $category = $this->createCategoryWithPages(mockDb: $mockDb, mockPages: $mockPages);

        $result = $category->subcategories();

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['link']);
    }
}
