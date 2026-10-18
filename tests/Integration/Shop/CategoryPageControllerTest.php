<?php

declare(strict_types=1);

namespace Tests\Integration\Shop;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;
use Osmium\Core\Osmium;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Models\Page;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/library/Autoload.php';
require_once 'tests/Helpers/OsmiumShopStandIn.php';

/**
 * The storefront category page's controller script (shop/controllers/category.php): the category's own text and
 * social image, the shop display switches, the subcategories worth showing (only those with products or children,
 * not binned) with their images, the products in the category in their sorted order with prices, savings and
 * images, the counts and the one-line summary, and what an empty category looks like. The script runs for real
 * over real categories, products and the shop models (rolled back); only the site around it is a stand-in.
 */
class CategoryPageControllerTest extends DatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private string $tag;
    private int $brandId;
    private int $statusId;
    private mixed $savedPagesById;
    private array $routedPages = [];
    private array $shopConfig;

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;
        (new Autoload('app/config'))->register();

        $this->tag = 'zq' . \substr(\md5(\uniqid()), 0, 8);
        $this->shopConfig = ['categoryShowCounts' => true, 'categoryShowSummaryText' => true, 'categoryShowCallToAction' => false, 'taxRatePercent' => 20];
        $this->savedPagesById = (new \ReflectionProperty(Model::class, 'pagesById'))->getValue();
        $this->routedPages = Model::pagesById();

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "Brand {$this->tag}"]);
        $this->brandId = (int) $this->db->lastInsertId();
        $this->statusId = $this->statusId(true);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->savedPagesById);
        parent::tearDown();
    }

    private function category(string $suffix, ?int $parent = null, int $sort = 0, array $columns = []): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productcategories', data: $columns + [
            'parent_id' => $parent, 'label' => "{$this->tag} {$suffix}", 'title' => "Title {$suffix}", 'subtitle' => "Subtitle {$suffix}",
            'image' => "img-{$suffix}", 'social_image' => "social-{$suffix}.jpg", 'sort' => $sort, 'description' => "<p>About {$suffix}</p>",
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Gives a category its page, so it can be listed as a subcategory. */
    private function categoryPage(int $categoryId, string $suffix): void
    {
        $pages = new Page($this->db, 'pages');
        $pageId = $pages->getNextId();
        $url = "zq-{$this->tag}-cat-{$suffix}";
        $pages->insert($pageId, null, $url, 'Category page', null, 'index,follow', \date('Y-m-d H:i:s'));
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productcategories_pages', data: ['productcategory_id' => $categoryId, 'page_id' => $pageId]);
        $this->routedPages[$pageId] = ['id' => $pageId, 'url' => $url, 'deleted_at' => null];
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->routedPages);
    }

    /** A product in a category, with its own page unless told otherwise. */
    private function product(int $categoryId, int $sort, array $overrides = [], bool $withPage = true): int
    {
        $id = $this->createTestProduct($overrides + [
            'brand_id' => $this->brandId, 'status_id' => $this->statusId, 'model_name' => "Model {$sort} {$this->tag}",
            'price_exc_tax' => 100, 'rrp_exc_tax' => 0,
        ]);
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_productcategories', data: ['product_id' => $id, 'category_id' => $categoryId, 'sort' => $sort]);

        if ($withPage) {
            $pages = new Page($this->db, 'pages');
            $pageId = $pages->getNextId();
            $url = "zq-{$this->tag}-p{$id}";
            $pages->insert($pageId, null, $url, 'Title', null, 'index,follow', \date('Y-m-d H:i:s'));
            $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $id, 'page_id' => $pageId, 'is_qr' => 0]);
            $this->routedPages[$pageId] = ['id' => $pageId, 'url' => $url, 'deleted_at' => null];
            (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->routedPages);
        }

        return $id;
    }

    /** Runs the category script and returns the page data it built. */
    private function page(int $categoryId): array
    {
        $osmium = new class ($this->db, $this->shopConfig) extends Osmium {
            public function __construct(OsmiumPDO $db, array $shop)
            {
                $this->dataSource = $db;
                $this->config = new OsmiumConfig((object) [
                    'site' => (object) ['scheme' => 'https', 'subdomain' => '', 'domain' => 'shop.test'],
                    'shop' => (object) $shop,
                ]);
            }

            public function imagePath(string $partialImagePath): string
            {
                return '/images' . $partialImagePath;
            }
        };

        $shop = new \OsmiumShop($osmium);
        $shop->model->productCategory->loadByID($categoryId);

        $context = new class {
            public $shop;
            public $osmium;
            public array $data = [];
        };
        $context->shop = $shop;
        $context->osmium = $osmium;
        $context->data = ['site' => ['FQDN' => 'https://shop.test']];

        $script = function (): void {
            require 'app/services/osmium-shop/modules/shop/controllers/category.php';
        };
        \Closure::bind($script, $context, $context)();

        return $context->data;
    }

    // ---- the category itself ------------------------------------------------------

    public function test_the_page_has_the_categorys_own_text_and_social_image(): void
    {
        $id = $this->category('Solar', columns: ['description' => '<p>Panels &amp; more</p>']);

        $data = $this->page($id);

        $this->assertSame($id, $data['category']['ID']);
        $this->assertSame("{$this->tag} Solar", $data['category']['label']);
        $this->assertSame('Title Solar', $data['category']['title']);
        $this->assertSame('Subtitle Solar', $data['category']['subtitle']);
        $this->assertSame('<p>Panels &amp; more</p>', $data['category']['description']);
        $this->assertSame('https://shop.test/images/social/social-Solar.jpg', $data['site']['socialImage']);
    }

    public function test_the_display_switches_come_from_the_shop_settings(): void
    {
        $id = $this->category('Switches');

        $data = $this->page($id);
        $this->assertSame([true, true, false], [$data['category']['showCounts'], $data['category']['showSummaryText'], $data['category']['showCTA']]);

        $this->shopConfig = ['categoryShowCounts' => false, 'categoryShowSummaryText' => false, 'categoryShowCallToAction' => true, 'taxRatePercent' => 20];
        $data = $this->page($id);
        $this->assertSame([false, false, true], [$data['category']['showCounts'], $data['category']['showSummaryText'], $data['category']['showCTA']]);
    }

    // ---- subcategories ------------------------------------------------------------

    public function test_only_subcategories_with_products_or_children_are_listed_in_order(): void
    {
        $parent = $this->category('Parent');
        $withProducts = $this->category('Stocked', $parent, sort: 2);
        $this->product($withProducts, 1);
        $withChild = $this->category('Branch', $parent, sort: 1);
        $this->category('Leaf', $withChild);
        $empty = $this->category('Empty', $parent, sort: 3);
        $binned = $this->category('Binned', $parent, sort: 4, columns: ['deleted_at' => '2026-01-01 00:00:00']);
        $this->product($binned, 1);
        foreach ([[$withProducts, 'Stocked'], [$withChild, 'Branch'], [$empty, 'Empty'], [$binned, 'Binned']] as [$categoryId, $suffix]) {
            $this->categoryPage($categoryId, $suffix);
        }

        $data = $this->page($parent);

        $this->assertSame(["{$this->tag} Branch", "{$this->tag} Stocked"], \array_column($data['subcategories'], 'label'));
        $this->assertSame(2, $data['subcategoryCount']);
        $this->assertSame('lighting', $data['subcategoryClass']);
        $this->assertSame('/images/shop/categories/img-Branch/img-Branch', $data['subcategories'][0]['image']);
        $this->assertSame('/images/shop/categories/img-Stocked/img-Stocked', $data['subcategories'][1]['image']);
        $this->assertSame("zq-{$this->tag}-cat-Branch", $data['subcategories'][0]['link']);
    }

    // ---- products -----------------------------------------------------------------

    public function test_the_products_come_in_their_sorted_order_with_prices_and_images(): void
    {
        $category = $this->category('Range');
        $second = $this->product($category, 2, ['price_exc_tax' => 50, 'model_name' => "Beta {$this->tag}"]);
        $first = $this->product($category, 1, ['price_exc_tax' => 100, 'model_name' => "Alpha {$this->tag}"]);

        $data = $this->page($category);

        $this->assertSame([$first, $second], \array_keys($data['category']['products']));
        $this->assertSame("{$first},{$second}", $data['category']['range']['products']);
        $alpha = $data['category']['products'][$first];
        $this->assertSame($first, $alpha['id']);
        $this->assertSame("Brand {$this->tag} Alpha {$this->tag}", $alpha['title']);
        $this->assertSame(['100.00', '120.00'], [$alpha['priceExcTax'], $alpha['priceIncTax']]);
        $base = "/shop/{$alpha['brandPath']}/{$alpha['modelPath']}/medium/{$alpha['brandPath']}-{$alpha['modelPath']}";
        $this->assertSame('/images' . $base, $alpha['image']);
        $this->assertSame('/images' . $base . '@2x', $alpha['image2x']);
        $this->assertSame('60.00', $data['category']['products'][$second]['priceIncTax']);
    }

    public function test_the_tax_rate_in_the_shop_settings_is_used_for_the_prices(): void
    {
        $category = $this->category('Taxed');
        $id = $this->product($category, 1, ['price_exc_tax' => 100]);
        $this->shopConfig['taxRatePercent'] = '5';

        $this->assertSame('105.00', $this->page($category)['category']['products'][$id]['priceIncTax']);
    }

    public function test_a_saving_is_shown_only_when_there_is_an_rrp(): void
    {
        $category = $this->category('Savings');
        $noRrp = $this->product($category, 1, ['price_exc_tax' => 100, 'rrp_exc_tax' => 0]);
        $withRrp = $this->product($category, 2, ['price_exc_tax' => 80, 'rrp_exc_tax' => 100]);

        $products = $this->page($category)['category']['products'];

        $this->assertArrayNotHasKey('savingsOnRRP', $products[$noRrp]);
        $this->assertSame(['savings' => '24.00', 'savingsPercent' => 20], $products[$withRrp]['savingsOnRRP']);
    }

    public function test_the_counts_and_summary_cover_products_and_subcategories(): void
    {
        $parent = $this->category('Mixed');
        $this->product($parent, 1);
        $this->product($parent, 2);
        $child = $this->category('Child', $parent);
        $this->product($child, 1);
        $this->categoryPage($child, 'Child');

        $data = $this->page($parent);

        $this->assertSame(2, $data['category']['productCount']);
        $this->assertSame('2 products and 1 subcategory', $data['category']['summary']);
    }

    public function test_one_product_reads_as_singular(): void
    {
        $category = $this->category('Single');
        $this->product($category, 1);

        $this->assertSame('1 product', $this->page($category)['category']['summary']);
    }

    public function test_binned_products_are_not_counted_or_shown(): void
    {
        $category = $this->category('Binning');
        $kept = $this->product($category, 1);
        $binned = $this->product($category, 2);
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $binned])->execute();

        $data = $this->page($category);

        $this->assertSame(1, $data['category']['productCount']);
        $this->assertSame([$kept], \array_keys($data['category']['products']));
    }

    public function test_a_product_whose_category_link_is_deleted_is_not_counted_or_shown(): void
    {
        $category = $this->category('Unlinking');
        $kept = $this->product($category, 1);
        $unlinked = $this->product($category, 2);
        $this->db->prepare(
            sql: "UPDATE {$this->db->tablePrefix()}products_productcategories SET deleted_at = NOW() WHERE product_id = :id AND category_id = :category",
            bindings: [':id' => $unlinked, ':category' => $category],
        )->execute();

        $data = $this->page($category);

        $this->assertSame(1, $data['category']['productCount']);
        $this->assertSame([$kept], \array_keys($data['category']['products']));
    }

    public function test_a_subcategory_whose_only_product_link_is_deleted_is_not_listed(): void
    {
        $parent = $this->category('Parent2');
        $stocked = $this->category('Stocked2', $parent, sort: 1);
        $this->product($stocked, 1);
        $emptied = $this->category('Emptied2', $parent, sort: 2);
        $unlinked = $this->product($emptied, 1);
        $this->db->prepare(
            sql: "UPDATE {$this->db->tablePrefix()}products_productcategories SET deleted_at = NOW() WHERE product_id = :id",
            bindings: [':id' => $unlinked],
        )->execute();
        $this->categoryPage($stocked, 'Stocked2');
        $this->categoryPage($emptied, 'Emptied2');

        $data = $this->page($parent);

        $this->assertSame(["{$this->tag} Stocked2"], \array_column($data['subcategories'], 'label'));
    }

    public function test_products_with_status_three_are_left_out(): void
    {
        $prefix = $this->db->tablePrefix();
        $exists = $this->db->prepare(sql: "SELECT id FROM {$prefix}productstatus WHERE id = 3")->single();
        if (!$exists) { // An explicit id never moves the auto-increment counter up, and the row is rolled back
            $this->db->prepare(sql: "INSERT INTO {$prefix}productstatus (id, descr_short, descr_long, product_available, restocking, schema_ref, list, enabled) VALUES (3, 'Gone', 'Gone', 0, 0, '', 1, 1)")->execute();
        }
        $category = $this->category('Statuses');
        $kept = $this->product($category, 1);
        $this->product($category, 2, ['status_id' => 3]);

        $data = $this->page($category);

        $this->assertSame(1, $data['category']['productCount']);
        $this->assertSame([$kept], \array_keys($data['category']['products']));
    }

    public function test_a_product_with_no_live_page_is_counted_but_not_shown(): void
    {
        $category = $this->category('Pages');
        $shown = $this->product($category, 1);
        $this->product($category, 2, withPage: false);

        $data = $this->page($category);

        $this->assertSame(2, $data['category']['productCount'], 'the count comes from the category listing');
        $this->assertSame([$shown], \array_keys($data['category']['products']));
    }

    // ---- an empty category --------------------------------------------------------

    public function test_a_category_with_nothing_in_it_has_no_products_and_an_empty_summary(): void
    {
        $category = $this->category('Bare');

        $data = $this->page($category);

        $this->assertSame(0, $data['category']['productCount']);
        $this->assertSame('', $data['category']['summary']);
        $this->assertSame([], $data['subcategories']);
        $this->assertArrayNotHasKey('products', $data['category']);
    }

    public function test_a_category_with_only_subcategories_says_so_and_loads_no_products(): void
    {
        $parent = $this->category('Only subs');
        $child = $this->category('Sub', $parent);
        $this->product($child, 1);
        $this->categoryPage($child, 'Sub');

        $data = $this->page($parent);

        $this->assertSame('1 subcategory', $data['category']['summary']);
        $this->assertArrayNotHasKey('products', $data['category']);
    }
}
