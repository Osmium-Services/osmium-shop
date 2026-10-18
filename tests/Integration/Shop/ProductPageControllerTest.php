<?php

declare(strict_types=1);

namespace Tests\Integration\Shop {

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Osmium;
use Osmium\Core\Models\Model;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminShopOptions;
use Osmium\Modules\Shop\Core\OsmiumShop;
use Osmium\Modules\Shop\Core\OsmiumShopModel;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/library/Autoload.php';
require_once 'app/services/osmium-shop/models/AdminShopOptions.php';

/**
 * The storefront product page's controller script (shop/controllers/product-full.php): what it hands the page for a
 * real product: the title, prices with and without tax, savings, thumbnails, options, documents, structured-data
 * fields, the social image and the meta description fallback. The script runs for real against real products and
 * the shop models (rolled back); only the site around it (config, image lookups) is a stand-in.
 */
class ProductPageControllerTest extends DatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private int $brandId;
    private string $tag;
    private string $brand;
    public array $existingFiles = [];
    private array $shopConfig;
    private array $checkoutConfig;
    private AdminShopOptions $options;
    private mixed $savedPagesById;
    private array $routedPages = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;

        (new Autoload('app/config'))->register();
        $this->tag = 'zq' . \substr(\md5(\uniqid()), 0, 8);
        $this->brand = "Brand {$this->tag}";
        $this->existingFiles = [];
        $this->shopConfig = [
            'currency' => 'GBP', 'currencySign' => '£', 'taxName' => 'VAT', 'taxRatePercent' => 20, 'country' => 'GB',
            'productShowSummary' => true, 'productShowPriceExcTax' => false, 'productShowDeliveryMessage' => true,
        ];
        $this->checkoutConfig = ['deliveryCharge' => 5];

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => $this->brand]);
        $this->brandId = (int) $this->db->lastInsertId();

        $cache = new \ReflectionProperty(Model::class, 'pagesById');
        $this->savedPagesById = $cache->getValue();
        $this->routedPages = Model::pagesById(); // The product's page must be among the site's routes; this test adds its own

        $this->options = new AdminShopOptions(
            new ProductOptionType($this->db, 'productoptiontypes'),
            new ProductOptionValue($this->db, 'productoptionvalues'),
        );
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->savedPagesById);
        parent::tearDown();
    }

    private function product(array $overrides = []): int
    {
        $id = $this->createTestProduct($overrides + [
            'brand_id' => $this->brandId,
            'status_id' => $this->statusId(true),
            'model_name' => "Model {$this->tag}",
            'price_exc_tax' => 100,
            'rrp_exc_tax' => 150,
        ]);

        $pages = new Page($this->db, 'pages');
        $pageId = $pages->getNextId();
        $url = "zq-{$this->tag}-{$id}";
        $pages->insert($pageId, null, $url, 'Title', null, 'index,follow', \date('Y-m-d H:i:s'));
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $id, 'page_id' => $pageId, 'is_qr' => 0]);

        $this->routedPages[$pageId] = ['id' => $pageId, 'url' => $url, 'deleted_at' => null];
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->routedPages);

        return $id;
    }

    private function setColumn(int $id, string $column, mixed $value): void
    {
        $table = $this->db->tablePrefix() . 'products';
        $this->db->prepare(sql: "UPDATE {$table} SET {$column} = :v WHERE id = :id", bindings: [':v' => $value, ':id' => $id])->execute();
    }

    /** Runs the controller script for one product and returns the page data it built. */
    private function page(int $productId, array $template = []): array
    {
        $test = $this;
        $db = $this->db;

        $files = new class ($test) extends FileSystem {
            public function __construct(private object $test) {}

            public function fileExists(string $path): bool
            {
                return \in_array($path, $this->test->existingFiles, true);
            }
        };

        $osmium = new class ($db, $this->shopConfig, $this->checkoutConfig, $files) extends Osmium {
            public function __construct(OsmiumPDO $db, array $shop, array $checkout, FileSystem $files)
            {
                $this->dataSource = $db;
                $this->fileSystem = $files;
                $this->config = new OsmiumConfig((object) [
                    'site' => (object) ['scheme' => 'https', 'subdomain' => '', 'domain' => 'shop.test', 'imagesPath' => '/images'],
                    'shop' => (object) $shop,
                    'checkout' => (object) $checkout,
                ]);
            }

            /** The real lookup checks files on disk; here a path simply gains the images folder. */
            public function imagePath(string $partialImagePath): string
            {
                return '/images' . $partialImagePath;
            }
        };

        $shop = new OsmiumShop();
        $shop->model = new OsmiumShopModel($db, (new FileSystem())->readJSONFile('app/services/osmium-shop/modules/shop/config/models/models.json.php'));
        $shop->productID = $productId;

        $context = new class {
            public $shop;
            public $osmium;
            public array $data = [];
        };
        $context->shop = $shop;
        $context->osmium = $osmium;
        $context->data = ['shop' => ['currencySign' => '£'], 'site' => [], 'template' => $template];

        (function (): void {
            require 'app/services/osmium-shop/modules/shop/controllers/product-full.php';
        })->call($context);

        return $context->data;
    }

    private function basePath(): string
    {
        $path = \strtolower("brand-{$this->tag}");

        return $path;
    }

    public function test_the_page_gets_the_products_basic_details(): void
    {
        $id = $this->product(['reference' => 'REF-1']);
        $this->setColumn($id, 'custom_offer', 'Free cable');
        $this->setColumn($id, 'packed_weight', 2.5);

        $data = $this->page($id);

        $this->assertSame($id, $data['site']['pageProductID']);
        $product = $data['product'];
        $this->assertSame([$id, $id], [$product['ID'], $product['id']]);
        $this->assertSame("{$this->brand} Model {$this->tag}", $product['title']);
        $this->assertSame($this->brand, $product['brandName']);
        $this->assertSame('REF-1', $product['reference']);
        $this->assertSame('Free cable', $product['customOffer']);
        $this->assertEquals(2.5, $product['packedWeight']);
        $this->assertTrue($product['isNew']);
        $this->assertSame(1, $product['isAvailable']);
        $stored = $this->db->prepare(sql: 'SELECT descr_long FROM ' . $this->db->tablePrefix() . 'productstatus WHERE id = :id', bindings: [':id' => $this->statusId(true)])->single();
        $this->assertSame($stored['descr_long'], $product['statusName']);
        $this->assertNotSame('', $product['statusName']);
        $this->assertSame($this->statusId(true), $product['statusID']);
        $this->assertNull($product['kitProduct']);
        $this->assertNull($product['restockDate']);
        $this->assertNotEmpty($product['addedDate']);
    }

    public function test_the_page_knows_the_products_category(): void
    {
        $id = $this->product();
        $table = $this->db->tablePrefix();
        $this->db->insert(tableName: "{$table}productcategories", data: ['label' => $this->tag]);
        $categoryId = (int) $this->db->lastInsertId();
        $this->db->insert(tableName: "{$table}products_productcategories", data: ['product_id' => $id, 'category_id' => $categoryId]);

        $this->assertSame($categoryId, $this->page($id)['product']['CategoryID']);
    }

    public function test_a_kit_with_no_choice_left_in_one_option_shows_as_out_of_stock(): void
    {
        $outOfStock = $this->product(['model_name' => "Gone {$this->tag}", 'status_id' => $this->statusId(false)]);
        $kit = $this->product(['model_name' => "Kit {$this->tag}"]);
        $type = $this->options->addOptionType($kit, ['type_label' => 'Part', 'default_linked_product_id' => $outOfStock])['id'];
        $this->options->addOptionValue($type, ['option_descr' => 'Only other choice', 'linked_product_id' => $outOfStock]);

        $product = $this->page($kit)['product'];

        $this->assertFalse($product['kitAvailable']);
        $this->assertSame(0, $product['isAvailable']);
        $this->assertSame('OutOfStock', $product['schemaRef']);
    }

    public function test_a_kit_with_a_choice_left_stays_in_stock(): void
    {
        $inStock = $this->product(['model_name' => "Here {$this->tag}"]);
        $kit = $this->product(['model_name' => "Kit {$this->tag}"]);
        $type = $this->options->addOptionType($kit, ['type_label' => 'Part'])['id'];
        $this->options->addOptionValue($type, ['option_descr' => 'Fine', 'linked_product_id' => $inStock]);

        $product = $this->page($kit)['product'];

        $this->assertTrue($product['kitAvailable']);
        $this->assertSame(1, $product['isAvailable']);
    }

    public function test_the_page_switches_come_from_the_shop_settings(): void
    {
        $this->shopConfig['productShowSummary'] = false;
        $this->shopConfig['productShowPriceExcTax'] = true;
        $this->shopConfig['productShowDeliveryMessage'] = false;

        $product = $this->page($this->product())['shop']['product'];

        $this->assertSame([false, true, false], [$product['showSummary'], $product['showPriceExcTax'], $product['showDeliveryMessage']]);

        $this->shopConfig['productShowSummary'] = true;
        $this->shopConfig['productShowPriceExcTax'] = false;
        $this->shopConfig['productShowDeliveryMessage'] = true;

        $product = $this->page($this->product())['shop']['product'];

        $this->assertSame([true, false, true], [$product['showSummary'], $product['showPriceExcTax'], $product['showDeliveryMessage']]);
    }

    public function test_prices_are_shown_with_and_without_tax_with_savings_against_the_rrp(): void
    {
        $product = $this->page($this->product(['price_exc_tax' => 100, 'rrp_exc_tax' => 150]))['product'];

        $this->assertSame('120.00', $product['priceIncTax']);
        $this->assertSame('180.00', $product['RRPIncTax']);
        $this->assertSame('100.00', $product['priceExcTax']);
        $this->assertSame('150.00', $product['RRPExcTax']);
        $this->assertSame('120.00', $product['schemaPriceIncTax']);
        $this->assertSame($product['discount'], $product['getSavingsOnRRP']);
        $this->assertSame(['savings' => '60.00', 'savingsPercent' => 33], $product['discount']);
    }

    public function test_the_view_item_event_carries_the_product_and_its_tax_inclusive_price(): void
    {
        $id = $this->product(['price_exc_tax' => 50]);

        $event = $this->page($id)['product']['shopEvent'];

        $this->assertSame(['product_id' => $id, 'title' => "{$this->brand} Model {$this->tag}", 'value' => 60.0, 'currency' => 'GBP'], $event);
    }

    public function test_the_delivery_charge_for_the_structured_data_includes_tax(): void
    {
        $this->assertSame('6.00', $this->page($this->product())['shop']['schemaDeliveryChargeIncTax']);

        $this->checkoutConfig = [];
        $this->assertSame('0.00', $this->page($this->product())['shop']['schemaDeliveryChargeIncTax']);
    }

    public function test_the_page_lists_the_thumbnails_that_exist_with_retina_versions(): void
    {
        $base = "shop/{$this->basePath()}/model-{$this->tag}";
        $name = "{$this->basePath()}-model-{$this->tag}";
        $this->existingFiles = [
            "images/{$base}/thumb/{$name}",
            "images/{$base}/thumb/{$name}-extra2",
        ];

        $thumbs = $this->page($this->product())['product']['thumbnails'];

        $this->assertSame(['main', 'extra2'], \array_column($thumbs, 'slot'));
        $this->assertSame("/images/{$base}/thumb/{$name}", $thumbs[0]['thumb']);
        $this->assertSame("/images/{$base}/thumb/{$name}@2x", $thumbs[0]['retinaThumb']);
        $this->assertSame("/images/{$base}/large/{$name}", $thumbs[0]['large'], 'the first image shown is the large one');
        $this->assertSame("/images/{$base}/large/{$name}@2x", $thumbs[0]['retinaLarge']);
        $this->assertSame("/images/{$base}/extra/{$name}-extra2", $thumbs[1]['large'], 'the rest use the extra size');
        $this->assertSame("/images/{$base}/extra/{$name}-extra2@2x", $thumbs[1]['retinaLarge']);
    }

    public function test_a_missing_main_image_makes_every_listed_one_an_extra(): void
    {
        $base = "shop/{$this->basePath()}/model-{$this->tag}";
        $name = "{$this->basePath()}-model-{$this->tag}";
        $this->existingFiles = ["images/{$base}/thumb/{$name}-warranty"];

        $thumbs = $this->page($this->product())['product']['thumbnails'];

        $this->assertSame(['warranty'], \array_column($thumbs, 'slot'));
        $this->assertSame("/images/{$base}/extra/{$name}-warranty", $thumbs[0]['large']);
    }

    public function test_a_product_with_no_images_has_an_empty_list(): void
    {
        $this->assertSame([], $this->page($this->product())['product']['thumbnails']);
    }

    public function test_image_paths_for_the_page_checkout_and_structured_data(): void
    {
        $product = $this->page($this->product())['product'];
        $base = "shop/{$this->basePath()}/model-{$this->tag}";
        $name = "{$this->basePath()}-model-{$this->tag}";

        $this->assertSame("/images/{$base}/large/{$name}", $product['largeImage']);
        $this->assertSame("/images/{$base}/large/{$name}@2x", $product['largeImageRetina']);
        $this->assertSame("/images/{$base}/large/{$name}.jpg", $product['schemaPhoto']);
        $this->assertSame("{$this->basePath()}/model-{$this->tag}/thumb/{$name}.jpg", $product['checkoutThumb']);
    }

    public function test_the_social_image_is_a_full_address(): void
    {
        $data = $this->page($this->product());

        $this->assertSame("https://shop.test/images/shop/{$this->basePath()}/model-{$this->tag}/social/{$this->basePath()}-model-{$this->tag}.jpg", $data['site']['socialImage']);
    }

    public function test_options_are_built_for_the_page_with_their_json_and_legacy_forms(): void
    {
        $id = $this->product();
        $colour = $this->options->addOptionType($id, ['type_label' => 'Colour'])['id'];
        $this->options->addOptionValue($colour, ['option_descr' => 'Red']);
        $this->options->addOptionValue($colour, ['option_descr' => 'Blue']);

        $product = $this->page($id)['product'];

        $this->assertCount(1, $product['optionTypes']);
        $this->assertSame('Colour', $product['optionTypes'][0]['label']);
        $this->assertSame(['Red', 'Blue'], \array_column($product['optionTypes'][0]['values'], 'option_descr'));
        $this->assertJson($product['optionTypesJSON']);
        $this->assertStringContainsString('Red', $product['optionTypesJSON']);
        $this->assertStringContainsString('Blue', $product['optionTypesJSON']);
        $this->assertNotEmpty($product['options']);
        foreach (['optionLabel', 'optionDefault', 'optionWeights', 'optionPremiums', 'optionRRPs', 'suboptions', 'suboptionLabel', 'suboptionWeights', 'suboptionPremiums', 'suboptionRRPs'] as $key) {
            $this->assertArrayHasKey($key, $product, $key);
        }
    }

    public function test_an_option_that_is_another_product_shows_that_products_thumbnail(): void
    {
        $part = $this->product(['model_name' => "Linked {$this->tag}"]);
        $id = $this->product();
        $this->options->addOptionType($id, ['type_label' => 'Extra', 'linked_product_id' => $part]);

        $types = $this->page($id)['product']['optionTypes'];

        $brandPath = $this->basePath();
        $this->assertSame($part, $types[0]['linkedProductId']);
        $this->assertSame("/images/shop/{$brandPath}/linked-{$this->tag}/thumb/{$brandPath}-linked-{$this->tag}.jpg", $types[0]['linkedProductImage'] ?? null);
    }

    public function test_a_product_with_no_options_has_none(): void
    {
        $product = $this->page($this->product())['product'];

        $this->assertSame([], $product['optionTypes']);
        $this->assertEmpty($product['options']);
    }

    public function test_a_kit_page_names_the_product_it_is_a_kit_of(): void
    {
        $part = $this->product(['model_name' => "Part {$this->tag}"]);
        $kit = $this->product(['model_name' => "Kit {$this->tag}"]);
        $this->setColumn($kit, 'kit_id', $part);

        $product = $this->page($kit)['product'];

        $this->assertSame($part, $product['kitId']);
        $this->assertSame("{$this->brand} Part {$this->tag}", $product['kitProduct']['name']);
    }

    public function test_content_fields_reach_the_page_with_faq_pairs_and_spec_properties(): void
    {
        $id = $this->product(['descr' => '<p>First</p>', 'descr2' => '<p>Second</p>', 'descr3' => '<p>Third</p>']);
        $this->setColumn($id, 'video_url', 'https://video.test/x');
        $this->setColumn($id, 'faq', '<h5 class="accordion">Why?</h5><div><p>Because.</p></div>');
        $this->setColumn($id, 'techspec_json', \json_encode([['specs' => [['label' => 'Weight', 'value' => '2kg'], ['label' => '', 'value' => 'skipped']]]]));
        $this->setColumn($id, 'techspec', '<table></table>');
        $this->setColumn($id, 'descr_image', '<img src="/a.jpg">');
        $this->setColumn($id, 'descr3_image', '<img src="/c.jpg">');
        $this->setColumn($id, 'discounts', '5:10');

        $product = $this->page($id)['product'];

        $this->assertSame('https://video.test/x', $product['videoURL']);
        $this->assertSame('<table></table>', $product['techSpec']);
        $this->assertSame([['name' => 'Weight', 'value' => '2kg']], $product['specProperties']);
        $this->assertCount(1, $product['faqPairs']);
        $this->assertStringContainsString('Why?', \json_encode($product['faqPairs']));
        $this->assertSame(['<p>First</p>', '<p>Second</p>', '<p>Third</p>'], [$product['descriptionText1'], $product['descriptionText2'], $product['descriptionText3']]);
        $this->assertSame('<img src="/a.jpg">', $product['descriptionImage']);
        $this->assertSame('<img src="/c.jpg">', $product['descriptionImage3']);
        $this->assertSame('5:10', $product['quantityDiscounts']);
        $this->assertStringContainsString('Why?', $product['FAQ']);
    }

    public function test_documents_are_linked_by_their_standard_paths(): void
    {
        $product = $this->page($this->product())['product'];
        $base = "/images/shop/{$this->basePath()}/model-{$this->tag}";
        $name = "{$this->basePath()}-model-{$this->tag}";

        $this->assertSame("{$base}/brochure/{$name}-brochure.pdf", $product['brochurePath']);
        $this->assertSame("{$base}/datasheet/{$name}-datasheet.pdf", $product['datasheetPath']);
        $this->assertSame("{$base}/instructions/{$name}-instructions.pdf", $product['instructionsPath']);
        $this->assertSame("{$base}/information/{$name}-information.pdf", $product['informationPath']);
    }

    public function test_the_meta_description_falls_back_to_the_start_of_the_description(): void
    {
        $id = $this->product(['descr' => '<p>Hello   <b>world</b></p>']);

        $this->assertSame('Hello world', $this->page($id)['template']['metaDescription']);
        $this->assertSame('Set by hand', $this->page($id, ['metaDescription' => 'Set by hand'])['template']['metaDescription']);
    }

    public function test_service_data_reaches_the_product_through_the_page_hook(): void
    {
        $product = $this->page($this->product())['product'];

        $this->assertIsArray($product, 'any installed service answers the hook without breaking the page');
        $this->assertArrayHasKey('title', $product);
    }

    public function test_the_script_can_run_more_than_once_in_a_process(): void
    {
        $this->page($this->product());
        $this->page($this->product());

        $this->assertTrue(true);
    }
}
}

namespace Osmium\Modules\Shop\Core {
    /**
     * Stands in for the real shop class, which is not loaded in tests (its file registers a global alias that clashes
     * with the shared test stand-in). The product page script only needs a model and a product id from it.
     */
    if (!\class_exists(OsmiumShop::class, false)) {
        class OsmiumShop
        {
            public mixed $model = null;
            public ?int $productID = null;
        }
    }
}
