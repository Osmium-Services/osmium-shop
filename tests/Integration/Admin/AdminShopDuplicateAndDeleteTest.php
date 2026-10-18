<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminPages;
use Osmium\Modules\Admin\Services\AdminShop;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/AdminShop.php';

/**
 * AdminShop::duplicate() and ::deleteCompletely() against the real database,
 * with the routes file faked so the real app/config/routes.php is never
 * rewritten, and the image folders made under a throwaway working directory.
 */
class AdminShopDuplicateAndDeleteTest extends DatabaseTestCase
{
    private AdminShop $shop;
    private Product $products;
    private Page $pages;
    private object $pagesService;
    private string $workDir;
    private string $savedCwd;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'zq' . \substr(\md5(\uniqid()), 0, 8);
        $this->savedCwd = (string) \getcwd();
        $this->workDir = \sys_get_temp_dir() . '/osmium_shop_dup_test_' . \uniqid();
        \mkdir($this->workDir);

        $this->products = new Product($this->db, 'products');
        $this->pages = new Page($this->db, 'pages');
        $this->pagesService = new class ($this->pages) extends AdminPages {
            public int $routeRegenerations = 0;

            public function regenerateRoutesFile(): void
            {
                $this->routeRegenerations++;
            }
        };
        $pagesService = $this->pagesService;

        $this->shop = new class (
            $this->products,
            new Brand($this->db, 'brands'),
            new ProductOptionType($this->db, 'productoptiontypes'),
            new ProductOptionValue($this->db, 'productoptionvalues'),
            false,
            $pagesService,
        ) extends AdminShop {
            public function __construct($p, $b, $t, $v, $slash, private object $fake)
            {
                parent::__construct($p, $b, $t, $v, $slash);
            }

            protected function pagesService(): AdminPages
            {
                return $this->fake;
            }
        };
    }

    protected function tearDown(): void
    {
        \chdir($this->savedCwd);
        $this->removeTree($this->workDir);
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($path);
    }

    private function table(string $name): string
    {
        return $this->db->tablePrefix() . $name;
    }

    private function createBrand(string $name): int
    {
        $this->db->insert(tableName: $this->table('productbrands'), data: ['name' => $name]);

        return (int) $this->db->lastInsertId();
    }

    private function createCategory(?int $pageId): int
    {
        $this->db->insert(tableName: $this->table('productcategories'), data: ['label' => $this->tag, 'page_id' => $pageId]);

        return (int) $this->db->lastInsertId();
    }

    private function createPage(string $url): int
    {
        $id = $this->pages->getNextId();
        $this->pages->insert($id, null, $url, 'Title', null, 'index,follow', $this->now());

        return $id;
    }

    private function linkCategory(int $productId, int $categoryId): void
    {
        $this->db->insert(tableName: $this->table('products_productcategories'), data: ['product_id' => $productId, 'category_id' => $categoryId]);
    }

    private function one(string $sql, array $bindings = []): array
    {
        return $this->db->prepare(sql: $sql, bindings: $bindings)->single() ?: [];
    }

    private function rows(string $table, string $where, array $bindings): int
    {
        return (int) $this->one("SELECT COUNT(*) AS c FROM {$this->table($table)} WHERE {$where}", $bindings)['c'];
    }

    public function test_duplicating_copies_the_product_its_categories_and_gives_it_a_page_under_the_category_page(): void
    {
        $brandId = $this->createBrand("Zq Brand {$this->tag}");
        $categoryPage = $this->createPage("{$this->tag}-cat");
        $categoryId = $this->createCategory($categoryPage);
        $id = $this->createTestProduct([
            'brand_id' => $brandId,
            'model_name' => "Model {$this->tag}",
            'reference' => "REF-{$this->tag}",
            'price_exc_tax' => 123.45,
        ]);
        $this->linkCategory($id, $categoryId);

        $highest = 0;
        foreach (\Osmium\Config\PAGES as $route) {
            if ((int) $route['id'] < 2000) $highest = \max($highest, (int) $route['id']);
        }
        $expectedPageId = \max($highest, $this->products->getMaxPageId()) + 1;

        $newId = $this->shop->duplicate($id);

        $copy = $this->one("SELECT * FROM {$this->table('products')} WHERE id = :id", [':id' => $newId]);
        $this->assertNotSame($id, $newId);
        $this->assertSame("Model {$this->tag} (Copy)", $copy['model_name']);
        $this->assertSame("REF-{$this->tag}-COPY", $copy['reference']);
        $this->assertSame($brandId, (int) $copy['brand_id']);
        $this->assertEquals(123.45, $copy['price_exc_tax']);

        $this->assertSame(1, $this->rows('products_productcategories', 'product_id = :p AND category_id = :c', [':p' => $newId, ':c' => $categoryId]));

        $link = $this->one("SELECT page_id FROM {$this->table('products_pages')} WHERE product_id = :id", [':id' => $newId]);
        $page = $this->pages->getById((int) $link['page_id']);
        $this->assertSame("zq-brand-{$this->tag}-model-{$this->tag}-copy", $page['url']);
        $this->assertSame("Zq Brand {$this->tag} Model {$this->tag} (Copy)", $page['title']);
        $this->assertSame($categoryPage, (int) $page['parent_page_id']);
        $this->assertSame($expectedPageId, (int) $page['id'], 'one past the highest page id in the routes file and the database');
        $this->assertNull($page['description']);
        $this->assertSame('index,follow', $page['robots']);
        $this->assertSame(1, $this->pagesService->routeRegenerations);
    }

    public function test_duplicating_a_product_with_no_category_gives_a_top_level_page(): void
    {
        $brandId = $this->createBrand("Zq Brand {$this->tag}");
        $id = $this->createTestProduct(['brand_id' => $brandId, 'model_name' => "Model {$this->tag}", 'reference' => '']);

        $newId = $this->shop->duplicate($id);

        $copy = $this->one("SELECT reference FROM {$this->table('products')} WHERE id = :id", [':id' => $newId]);
        $this->assertSame('', $copy['reference'], 'no reference to copy, so none is invented');
        $link = $this->one("SELECT page_id FROM {$this->table('products_pages')} WHERE product_id = :id", [':id' => $newId]);
        $this->assertNull($this->pages->getById((int) $link['page_id'])['parent_page_id']);
    }

    public function test_duplicating_a_product_that_does_not_exist_is_refused(): void
    {
        $this->expectExceptionMessage('Product not found');

        $this->shop->duplicate(999999999);
    }

    public function test_duplicating_the_same_product_twice_is_refused_on_the_clashing_url(): void
    {
        $brandId = $this->createBrand("Zq Brand {$this->tag}");
        $id = $this->createTestProduct(['brand_id' => $brandId, 'model_name' => "Model {$this->tag}"]);
        $this->shop->duplicate($id);

        $this->expectExceptionMessage('A page with this URL already exists');

        $this->shop->duplicate($id);
    }

    public function test_completely_deleting_removes_the_product_its_links_its_page_and_its_images(): void
    {
        $brandId = $this->createBrand("Zq Brand {$this->tag}");
        $categoryId = $this->createCategory(null);
        $id = $this->createTestProduct(['brand_id' => $brandId, 'model_name' => "Model {$this->tag}"]);
        $this->linkCategory($id, $categoryId);
        $pageId = $this->createPage("{$this->tag}-product");
        $this->products->insertPageLink($id, $pageId);

        \chdir($this->workDir);
        \mkdir("assets/images/shop/{$this->slug("Zq Brand {$this->tag}")}/{$this->slug("Model {$this->tag}")}/brochure", recursive: true);
        \file_put_contents("assets/images/shop/{$this->slug("Zq Brand {$this->tag}")}/{$this->slug("Model {$this->tag}")}/brochure/a.pdf", '%PDF');

        $this->shop->deleteCompletely($id, $this->slugger(...), $this->slugger(...));

        $this->assertSame(0, $this->rows('products', 'id = :id', [':id' => $id]));
        $this->assertSame(0, $this->rows('products_productcategories', 'product_id = :id', [':id' => $id]));
        $this->assertSame(0, $this->rows('products_pages', 'product_id = :id', [':id' => $id]));
        $this->assertNull($this->pages->getById($pageId));
        $this->assertDirectoryDoesNotExist("assets/images/shop/{$this->slug("Zq Brand {$this->tag}")}/{$this->slug("Model {$this->tag}")}");
        $this->assertSame(1, $this->pagesService->routeRegenerations);
    }

    public function test_completely_deleting_a_product_whose_name_has_no_usable_slug_keeps_every_image_folder(): void
    {
        $brandId = $this->createBrand("Zq Brand {$this->tag}");
        $id = $this->createTestProduct(['brand_id' => $brandId, 'model_name' => '!!!']);
        \chdir($this->workDir);
        $brandFolder = 'assets/images/shop/' . $this->slug("Zq Brand {$this->tag}");
        \mkdir("{$brandFolder}/other-model", recursive: true);
        \file_put_contents("{$brandFolder}/other-model/a.jpg", 'x');

        $this->shop->deleteCompletely($id, $this->slugger(...), $this->slugger(...));

        $this->assertSame(0, $this->rows('products', 'id = :id', [':id' => $id]));
        $this->assertFileExists("{$brandFolder}/other-model/a.jpg");
    }

    public function test_completely_deleting_a_product_keeps_an_image_folder_another_product_maps_to(): void
    {
        $brandId = $this->createBrand("Zq Brand {$this->tag}");
        $id = $this->createTestProduct(['brand_id' => $brandId, 'model_name' => "A&B {$this->tag}"]);
        $this->createTestProduct(['brand_id' => $brandId, 'model_name' => "AB {$this->tag}"]);
        \chdir($this->workDir);
        $folder = 'assets/images/shop/' . $this->slug("Zq Brand {$this->tag}") . '/' . $this->slug("AB {$this->tag}");
        \mkdir($folder, recursive: true);
        \file_put_contents("{$folder}/a.jpg", 'x');

        $this->shop->deleteCompletely($id, $this->slugger(...), $this->slugger(...));

        $this->assertFileExists("{$folder}/a.jpg");
    }

    private function slug(string $name): string
    {
        return $this->slugger($name);
    }

    private function slugger(string $name): string
    {
        $path = \preg_replace('/[^a-z0-9-]+/', '-', \str_replace(['/', '\\', '&'], '', \mb_strtolower(\trim($name))));

        return \trim(\preg_replace('/-+/', '-', $path), '-');
    }

    public function test_completely_deleting_a_product_with_no_page_or_images_touches_no_pages(): void
    {
        $id = $this->createTestProduct(['model_name' => "Model {$this->tag}"]);
        \chdir($this->workDir);

        $this->shop->deleteCompletely($id, fn () => 'none', fn () => 'none');

        $this->assertSame(0, $this->rows('products', 'id = :id', [':id' => $id]));
        $this->assertSame(0, $this->pagesService->routeRegenerations);
    }

    public function test_completely_deleting_is_refused_for_a_missing_or_old_product(): void
    {
        $this->assertSame('Product not found', $this->refusal(fn () => $this->shop->deleteCompletely(999999999, fn () => 'a', fn () => 'b')));

        $id = $this->createTestProduct();
        $this->db->prepare(sql: "UPDATE {$this->table('products')} SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = :id", bindings: [':id' => $id])->execute();

        $this->assertSame('Can only undo products created within the last hour', $this->refusal(fn () => $this->shop->deleteCompletely($id, fn () => 'a', fn () => 'b')));
        $this->assertSame(1, $this->rows('products', 'id = :id', [':id' => $id]));
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return '(no exception)';
    }
}
