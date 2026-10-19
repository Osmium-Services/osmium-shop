<?php

declare(strict_types=1);

namespace {
    require_once 'app/services/osmium-shop/tests/Helpers/OsmiumShopStandIn.php'; // The controller builds the shop module's global-alias class
}

namespace Tests\Integration\Admin {

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Header;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\Url;
use Osmium\Core\Models\ServiceModel;
use Osmium\Core\Osmium;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Controllers\ProductsController;
use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Core\Models\Model;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminPages;
use Osmium\Modules\Admin\Services\AdminPresence;
use Osmium\Modules\Admin\Services\AdminShop;
use Osmium\Modules\Admin\Services\AdminShopImages;
use Osmium\Modules\Admin\Services\AdminShopOptions;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\ShopAdminServices;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/services/osmium-shop/models/AdminShop.php';
require_once 'app/services/osmium-shop/models/AdminShopImages.php';
require_once 'app/services/osmium-shop/models/AdminShopOptions.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/controllers/ProductsController.php';

/**
 * Stands in for the redirect that ends a real request. An Error, not an Exception, so the controller's own
 * catch blocks do not swallow it.
 */
final class RedirectSignal extends \Error {}

/**
 * A fixture service that hears about saved products.
 */
final class ProductSavedListener
{
    /** @var int[] */
    public static array $saved = [];

    public static function saved(array $payload): void
    {
        self::$saved[] = (int) $payload['productId'];
    }
}

/** The products screen with the pages service stood in: the real one rewrites the dev site's routes.php. */
class ProductsControllerWithoutRoutesFile extends ProductsController
{
    protected function pagesService(): AdminPages
    {
        $page = $this->admin->model->page;

        return new class ($page, false) extends AdminPages {
            public function __construct(private Page $pageModel, bool $urlTrailingSlash)
            {
                parent::__construct(page: $pageModel, urlTrailingSlash: $urlTrailingSlash);
            }

            /** Instead of rewriting the dev site's routes.php, refresh the in-memory page list the models read. */
            public function regenerateRoutesFile(): void
            {
                $byId = \array_column($this->pageModel->getAll(), null, 'id');
                (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $byId);
            }
        };
    }
}

/**
 * The product list, add form and edit form: what is created and stored, the page and category that come with a
 * new product, what an edit changes (price history, techspec, page address), and what the screens show. Real
 * controller, real models, rolled back. The edit page's file work (renaming images, uploads) is not driven from
 * here, and the CSV export ends the request with exit.
 */
class ProductsControllerFormsTest extends DatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private string $runTag;
    private string $serviceDir = '';
    private array $stashed = [];
    private ?array $savedPagesById = null;
    private string $errorLog;
    private string $savedErrorLog;

    protected function setUp(): void
    {
        $this->savedPagesById = (new \ReflectionProperty(Model::class, 'pagesById'))->getValue();
        parent::setUp();
        self::$keepAlive[] = $this->db;

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $_POST = [];
        $_GET = [];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['DOCUMENT_ROOT'] = \sys_get_temp_dir() . '/osmium-products-docroot-' . \uniqid();
        $this->runTag = \strtolower('zq' . \substr(\uniqid(), -6));
        $this->stashed = [];
        ProductSavedListener::$saved = [];

        $this->savedErrorLog = (string) \ini_get('error_log');
        $this->errorLog = \sys_get_temp_dir() . '/osmium-products-errors-' . \uniqid() . '.log';
        \ini_set('error_log', $this->errorLog);

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $wanted = ['brand', 'status', 'page', 'changelog', 'user'];
        $models = (object) \array_intersect_key((array) $all, \array_flip($wanted));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'product', \Osmium\Modules\Admin\Models\Product::class, 'products'); // The shop service's model
        $this->model->addModel($this->db, 'status', \Osmium\Modules\Admin\Models\Status::class, 'productstatus'); // Supplied by the Osmium Shop service
        $this->model->addModel($this->db, 'brand', \Osmium\Modules\Admin\Models\Brand::class, 'productbrands');
        $this->model->addModel($this->db, 'productOptionType', \Osmium\Modules\Admin\Models\ProductOptionType::class, 'productoptiontypes');
        $this->model->addModel($this->db, 'productOptionValue', \Osmium\Modules\Admin\Models\ProductOptionValue::class, 'productoptionvalues');
        $this->model->addModel($this->db, 'productPriceHistory', \Osmium\Modules\Admin\Models\ProductPriceHistory::class, 'product_price_history');
        $this->model->addModel($this->db, 'productCategory', \Osmium\Modules\Admin\Models\ProductCategory::class, 'productcategories');
        $this->model->addModel($this->db, 'productCategoryAssignment', \Osmium\Modules\Admin\Models\ProductCategoryAssignment::class, 'products_productcategories');

        $this->auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new Password(),
        ) extends AdminAuth {
            public array $input = [];

            public function getJsonInput(): array
            {
                return $this->input;
            }
        };
        $this->model->changelog->setGetUser(fn () => $this->auth->getCurrentUser());

        $shop = new AdminShop(
            new Product($this->db, 'products'),
            new Brand($this->db, 'brands'),
            new ProductOptionType($this->db, 'productoptiontypes'),
            new ProductOptionValue($this->db, 'productoptionvalues'),
        );

        $this->admin = new class ($this->model, $this->auth, $shop) extends OsmiumAdmin {
            public array $renamed = [];

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth, AdminShop $shop)
            {
                $this->model = $model;
                $this->auth = $auth;
                ShopAdminServices::override($this, 'shop', $shop);
                ShopAdminServices::override($this, 'options', new AdminShopOptions($model->productOptionType, $model->productOptionValue));
                $test = $this;
                ShopAdminServices::override($this, 'images', new class ($model->product, $test) extends AdminShopImages {
                    public function __construct(\Osmium\Modules\Admin\Models\Product $product, private object $test) {}

                    public function renameProductImages(string $brandName, string $oldModelName, string $newModelName): array
                    {
                        $this->test->renamed[] = [$brandName, $oldModelName, $newModelName];

                        return ['success' => true];
                    }
                });
                $this->presence = new class extends AdminPresence {
                    public function __construct() {}

                    public function handleAction(string $action, array $input, string $recordType): bool
                    {
                        return false;
                    }

                    public function registerCurrentUser(string $recordType, int $recordId): void {}

                    public function list(int $currentUserId, string $recordType): array
                    {
                        return [];
                    }
                };
            }
        };
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->savedPagesById);
        \ini_set('error_log', $this->savedErrorLog);
        if (\file_exists($this->errorLog)) \unlink($this->errorLog);
        if ($this->serviceDir !== '' && \is_dir($this->serviceDir)) {
            \array_map('unlink', \glob($this->serviceDir . '/*') ?: []);
            \rmdir($this->serviceDir);
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function controller(array &$data): ProductsController
    {
        $osmium = new class ($this->db, $this->stashed) extends Osmium {
            public function __construct(OsmiumPDO $db, private array $stashed)
            {
                $this->dataSource = $db;
                $this->fileSystem = new FileSystem();
                $this->config = new OsmiumConfig((object) [
                    'site' => (object) ['scheme' => 'http', 'subdomain' => '', 'domain' => 'x.test', 'hostName' => 'x.test', 'trustProxyHeaders' => false, 'imagesPath' => '/images', 'urlTrailingSlash' => false],
                    'company' => (object) ['brandName' => 'HouseBrand'],
                ]);
                $this->url = new class extends Url {
                    public function __construct() {}

                    public function to(string $path): string
                    {
                        return $path;
                    }
                };
                $this->header = new class extends Header {
                    public function __construct() {}

                    public function redirect(string $targetURL, int $statusCode = 302): void
                    {
                        throw new RedirectSignal($targetURL);
                    }
                };
            }

            public function getStashedParam(string $key, mixed $default = null): mixed
            {
                return $this->stashed[$key] ?? $default;
            }

            public function clearStashedQuerystring(): void {}

            public function imagePath(string $partialImagePath): string
            {
                return '/images' . $partialImagePath;
            }
        };

        return new ProductsControllerWithoutRoutesFile($osmium, $this->admin, $data);
    }

    private function signInAs(string $role): array
    {
        $_SESSION['admin_user_id'] = 1;
        $_SESSION['admin_user_role'] = $role;
        $_SESSION['admin_user_username'] = "{$role}-tester";

        return ['admin' => ['basePath' => '/admin/', 'avatarPath' => '/a/', 'user' => ['role' => $role]], 'shop' => ['taxRate' => 20]];
    }

    /**
     * Runs one screen, as a visitor of the given role.
     *
     * @return array{redirect: ?string, data: array}
     */
    private function screen(string $method, array $post = [], string $role = 'admin', bool $isPost = true): array
    {
        $data = $this->signInAs($role);
        $_SERVER['REQUEST_METHOD'] = $isPost ? 'POST' : 'GET';
        $_POST = $post;
        $redirect = null;

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        try {
            $this->controller($data)->$method();
        } catch (RedirectSignal $e) {
            $redirect = $e->getMessage();
        } finally {
            \restore_error_handler();
        }

        return ['redirect' => $redirect, 'data' => $data];
    }

    private function brand(string $name): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => $name]);

        return (int) $this->db->lastInsertId();
    }

    private function row(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'products WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function productIdFor(string $modelName): int
    {
        $row = $this->db->prepare(
            sql: 'SELECT id FROM ' . $this->db->tablePrefix() . 'products WHERE model_name = :name',
            bindings: [':name' => $modelName],
        )->single();

        return $row ? (int) $row['id'] : 0;
    }

    private function pageRow(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'pages WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function pageOf(int $productId): array
    {
        $link = $this->db->prepare(
            sql: 'SELECT page_id FROM ' . $this->db->tablePrefix() . 'products_pages WHERE product_id = :id',
            bindings: [':id' => $productId],
        )->single();

        return $link ? $this->pageRow((int) $link['page_id']) : [];
    }

    private function categoryIdsOf(int $productId): array
    {
        return \array_map('intval', \array_column($this->db->prepare(
            sql: 'SELECT category_id FROM ' . $this->db->tablePrefix() . 'products_productcategories WHERE product_id = :id',
            bindings: [':id' => $productId],
        )->resultset(), 'category_id'));
    }

    private function changelogFor(int $id): array
    {
        return \array_column($this->db->prepare(
            sql: "SELECT description FROM " . $this->db->tablePrefix() . "changelog WHERE record_type = 'product' AND record_id = :id ORDER BY id",
            bindings: [':id' => $id],
        )->resultset(), 'description');
    }

    private function priceHistory(int $productId): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'product_price_history WHERE product_id = :id ORDER BY id',
            bindings: [':id' => $productId],
        )->resultset();
    }

    private function addForm(array $overrides = []): array
    {
        return $overrides + [
            'brand_id' => (string) $this->brand("{$this->runTag} Brand"),
            'model_name' => "{$this->runTag} Widget",
            'status_id' => (string) $this->statusId(true),
            'price_exc_tax' => '49.99',
        ];
    }

    private function addProduct(array $overrides = []): int
    {
        $form = $this->addForm($overrides);
        $result = $this->screen('add', $form);
        $this->assertMatchesRegularExpression('#^/admin/shop/products/edit/\?id=\d+$#', (string) $result['redirect'], $_SESSION['product_error'] ?? '');

        return (int) \substr((string) $result['redirect'], \strrpos((string) $result['redirect'], '=') + 1);
    }

    /** The fields the edit form posts, filled in from a stored product. */
    private function editForm(int $id, array $overrides = []): array
    {
        $row = $this->row($id);

        return $overrides + [
            'token' => 'good-token',
            'id' => (string) $id,
            'brand_id' => (string) $row['brand_id'],
            'model_name' => $row['model_name'],
            'reference' => (string) $row['reference'],
            'barcode' => (string) $row['barcode'],
            'status_id' => (string) $row['status_id'],
            'price_exc_tax' => (string) $row['price_exc_tax'],
            'rrp_exc_tax' => $row['rrp_exc_tax'] === null ? '' : (string) $row['rrp_exc_tax'],
        ];
    }

    private function installListener(): void
    {
        $this->serviceDir = \sys_get_temp_dir() . '/osmium-product-listener-' . \uniqid();
        \mkdir($this->serviceDir);
        \file_put_contents($this->serviceDir . '/service.json', \json_encode([
            'id' => 'product-listener',
            'name' => 'Product Listener',
            'version' => '1.0.0',
            'description' => 'Test service',
            'autoload' => [],
            'hooks' => ['product.saved' => [ProductSavedListener::class . '::saved']],
        ]));
        (new ServiceModel($this->db))->insert(
            serviceId: 'product-listener',
            name: 'Product Listener',
            version: '1.0.0',
            description: null,
            authorName: null,
            githubRepo: null,
            path: $this->serviceDir,
            config: null,
            installedBy: null,
        );
    }

    // ---- add ----------------------------------------------------------------------

    public function test_the_add_form_offers_brands_statuses_categories_and_parent_pages(): void
    {
        $brand = $this->brand("{$this->runTag} Listed");

        $result = $this->screen('add', isPost: false);

        $admin = $result['data']['admin'];
        $this->assertNull($result['redirect']);
        $this->assertTrue($admin['isAddMode']);
        $this->assertContains($brand, \array_map('intval', \array_column($admin['brands'], 'id')));
        $this->assertNotEmpty($admin['statuses']);
        $this->assertArrayHasKey('allCategories', $admin);
        $this->assertArrayHasKey('parentPages', $admin);
        $this->assertSame(['taxRate' => 20], $admin['addConfig']);
        $this->assertSame('', $admin['productError']);
    }

    public function test_a_new_product_is_stored_with_its_own_page_and_logged(): void
    {
        $form = $this->addForm(['model_name' => "{$this->runTag} Inverter 3000"]);
        $result = $this->screen('add', $form);

        $this->assertMatchesRegularExpression('#^/admin/shop/products/edit/\?id=\d+$#', (string) $result['redirect']);
        $id = $this->productIdFor("{$this->runTag} Inverter 3000");
        $row = $this->row($id);
        $this->assertSame((int) $form['brand_id'], (int) $row['brand_id']);
        $this->assertEqualsWithDelta(49.99, (float) $row['price_exc_tax'], 0.001);
        $this->assertSame(1, (int) ($_SESSION['product_saved'] ?? 0) ?: 1);
        $this->assertTrue($_SESSION['product_saved']);

        $page = $this->pageOf($id);
        $this->assertSame("{$this->runTag}-brand-{$this->runTag}-inverter-3000", $page['url'], 'brand and model, in lower case with hyphens');
        $this->assertSame("{$this->runTag} Inverter 3000", $page['title']);
        $this->assertSame('index,follow', $page['robots']);
        $this->assertSame(["Created product: {$this->runTag} Inverter 3000"], $this->changelogFor($id));
    }

    public function test_the_company_own_brand_is_left_out_of_the_address(): void
    {
        $brandId = $this->brand('HouseBrand');

        $id = $this->addProduct(['brand_id' => (string) $brandId, 'model_name' => "{$this->runTag} Own Make"]);

        $this->assertSame("{$this->runTag}-own-make", $this->pageOf($id)['url']);
    }

    public function test_a_product_added_with_a_category_is_filed_in_it_and_sits_under_its_page(): void
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'pages', data: ['id' => 1999, 'url' => "{$this->runTag}-cat", 'title' => 'Category page', 'robots' => 'index,follow']);
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productcategories', data: ['label' => "{$this->runTag} Category", 'page_id' => 1999]);
        $categoryId = (int) $this->db->lastInsertId();

        $id = $this->addProduct(['category_id' => (string) $categoryId]);

        $this->assertSame([$categoryId], $this->categoryIdsOf($id));
        $this->assertSame(1999, (int) $this->pageOf($id)['parent_page_id']);
    }

    public function test_a_parent_page_chosen_by_hand_beats_the_categorys_page(): void
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'pages', data: ['id' => 1998, 'url' => "{$this->runTag}-manual", 'title' => 'Manual parent', 'robots' => 'index,follow']);

        $id = $this->addProduct(['parent_page_id' => '1998']);

        $this->assertSame(1998, (int) $this->pageOf($id)['parent_page_id']);
    }

    public function test_a_product_added_without_a_category_has_none(): void
    {
        $id = $this->addProduct();

        $this->assertSame([], $this->categoryIdsOf($id));
        $this->assertNull($this->pageOf($id)['parent_page_id']);
    }

    public function test_an_add_that_cannot_make_its_page_goes_back_to_the_form_with_the_reason_and_creates_nothing(): void
    {
        $this->addProduct(['model_name' => "{$this->runTag} Twin"]);
        $brandName = "{$this->runTag} Brand";
        $sameBrand = (int) $this->db->prepare(
            sql: 'SELECT id FROM ' . $this->db->tablePrefix() . 'productbrands WHERE name = :name',
            bindings: [':name' => $brandName],
        )->single()['id'];
        $before = $this->db->prepare('SELECT COUNT(*) AS n FROM ' . $this->db->tablePrefix() . 'products')->single()['n'];

        $result = $this->screen('add', $this->addForm(['brand_id' => (string) $sameBrand, 'model_name' => "{$this->runTag} Twin"]));

        $this->assertSame('/admin/shop/products/add/', $result['redirect']);
        $this->assertSame('A page with this URL already exists', $_SESSION['product_error']);
        $this->assertSame($before, $this->db->prepare('SELECT COUNT(*) AS n FROM ' . $this->db->tablePrefix() . 'products')->single()['n']);
    }

    public function test_the_error_from_a_failed_add_is_shown_once(): void
    {
        $_SESSION['product_error'] = 'Something went wrong';

        $first = $this->screen('add', isPost: false);
        $this->assertSame('Something went wrong', $first['data']['admin']['productError']);

        $second = $this->screen('add', isPost: false);
        $this->assertSame('', $second['data']['admin']['productError']);
    }

    public function test_services_are_told_about_a_new_product(): void
    {
        $this->installListener();

        $id = $this->addProduct();

        $this->assertSame([$id], ProductSavedListener::$saved);
    }

    // ---- edit: showing it ---------------------------------------------------------

    public function test_the_edit_form_shows_the_product_and_what_it_can_be_changed_to(): void
    {
        $id = $this->addProduct();
        unset($_SESSION['product_saved']); // The add left its saved message behind
        $this->stashed = ['id' => $id];

        $result = $this->screen('edit', isPost: false);

        $admin = $result['data']['admin'];
        $this->assertNull($result['redirect']);
        $this->assertSame($id, $admin['productId']);
        $this->assertSame("{$this->runTag} Widget", $admin['product']['model_name']);
        $this->assertSame("{$this->runTag} Brand", $admin['currentBrand']['name']);
        $this->assertNotEmpty($admin['statuses']);
        $this->assertTrue($admin['canDeleteCompletely'], 'a product made just now can still be undone');
        $this->assertSame($id, $admin['editConfig']['productId']);
        $this->assertSame(20, $admin['editConfig']['taxRate']);
        $this->assertNotEmpty($admin['productPage']);
        $this->assertFalse($admin['productSaved']);
    }

    public function test_the_saved_message_shows_once_after_an_add(): void
    {
        $id = $this->addProduct();
        $this->stashed = ['id' => $id];

        $first = $this->screen('edit', isPost: false);
        $this->assertTrue($first['data']['admin']['productSaved']);

        $second = $this->screen('edit', isPost: false);
        $this->assertFalse($second['data']['admin']['productSaved']);
    }

    public function test_an_old_product_can_no_longer_be_undone(): void
    {
        $id = $this->addProduct();
        $this->db->prepare(
            sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = :id',
            bindings: [':id' => $id],
        )->execute();
        $this->stashed = ['id' => $id];

        $this->assertFalse($this->screen('edit', isPost: false)['data']['admin']['canDeleteCompletely']);
    }

    public function test_asking_to_edit_nothing_or_a_missing_product_goes_back_to_the_list(): void
    {
        $this->assertSame('/admin/shop/products/', $this->screen('edit', isPost: false)['redirect']);

        $this->stashed = ['id' => 999999999];
        $this->assertSame('/admin/shop/products/', $this->screen('edit', isPost: false)['redirect']);
    }

    // ---- edit: saving it ----------------------------------------------------------

    public function test_a_wrong_token_changes_nothing(): void
    {
        $id = $this->addProduct();
        $this->stashed = ['id' => $id];

        $result = $this->screen('edit', $this->editForm($id, ['token' => 'forged', 'reference' => 'HACKED']));

        $this->assertSame("/admin/shop/products/edit/?id={$id}", $result['redirect']);
        $this->assertSame('', $this->row($id)['reference']);
    }

    public function test_saving_stores_every_field_and_returns_to_the_form_with_a_saved_message(): void
    {
        $id = $this->addProduct();
        $_SESSION['product_saved'] = false;

        $result = $this->screen('edit', $this->editForm($id, [
            'reference' => 'REF-123',
            'barcode' => '5012345678900',
            'price_exc_tax' => '59.50',
            'rrp_exc_tax' => '75',
            'custom_offer' => 'Free cable',
            'descr' => 'Short words',
            'descr2' => 'More words',
            'descr3' => 'Even more',
            'video_url' => 'https://example.test/video',
            'faq' => 'Q and A',
            'packed_weight' => '2.5',
            'option_label' => 'Colour',
            'option_default' => 'Red',
            'restock_at' => '2030-01-01',
        ]));

        $this->assertSame("/admin/shop/products/edit/?id={$id}", $result['redirect']);
        $this->assertTrue($_SESSION['product_saved']);
        $row = $this->row($id);
        $this->assertSame('REF-123', $row['reference']);
        $this->assertSame('5012345678900', $row['barcode']);
        $this->assertEqualsWithDelta(59.50, (float) $row['price_exc_tax'], 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $row['rrp_exc_tax'], 0.001);
        $this->assertSame('Free cable', $row['custom_offer']);
        $this->assertSame('Short words', $row['descr']);
        $this->assertSame('More words', $row['descr2']);
        $this->assertSame('Even more', $row['descr3']);
        $this->assertSame('https://example.test/video', $row['video_url']);
        $this->assertSame('Q and A', $row['faq']);
        $this->assertEqualsWithDelta(2.5, (float) $row['packed_weight'], 0.001);
        $this->assertSame('Colour', $row['option_label']);
        $this->assertSame('Red', $row['option_default']);
        $this->assertStringStartsWith('2030-01-01', (string) $row['restock_at']);
        $this->assertContains("Updated product: {$this->runTag} Widget", $this->changelogFor($id));
    }

    public function test_blank_optional_fields_are_stored_as_nothing(): void
    {
        $id = $this->addProduct();
        $this->screen('edit', $this->editForm($id, ['rrp_exc_tax' => '75', 'restock_at' => '2030-01-01']));

        $this->screen('edit', $this->editForm($id, ['rrp_exc_tax' => '', 'restock_at' => '', 'kit_id' => '']));

        $row = $this->row($id);
        $this->assertEqualsWithDelta(0.0, (float) $row['rrp_exc_tax'], 0.001, 'no RRP is stored as 0: the column cannot hold nothing');
        $this->assertNull($row['restock_at']);
        $this->assertNull($row['kit_id']);
    }

    public function test_a_product_can_be_made_part_of_a_kit(): void
    {
        $id = $this->addProduct();
        $kit = $this->createTestProduct();

        $this->screen('edit', $this->editForm($id, ['kit_id' => (string) $kit]));

        $this->assertSame($kit, (int) $this->row($id)['kit_id']);
    }

    public function test_the_techspec_table_is_kept_and_a_machine_readable_copy_made_from_it(): void
    {
        $id = $this->addProduct();
        $table = '<table><tbody><tr><td colspan="2"><strong>Electrical</strong></td></tr><tr><td>Voltage</td><td>12V</td></tr></tbody></table>';

        $this->screen('edit', $this->editForm($id, ['techspec' => $table]));

        $row = $this->row($id);
        $this->assertSame($table, $row['techspec']);
        $json = \json_decode((string) $row['techspec_json'], true);
        $this->assertIsArray($json);
        $this->assertStringContainsString('Voltage', (string) $row['techspec_json']);

        $this->screen('edit', $this->editForm($id, ['techspec' => '<p><br></p>']));
        $this->assertNull($this->row($id)['techspec_json'], 'nothing parseable means no copy');
    }

    public function test_an_edit_without_a_product_id_changes_nothing_and_goes_to_the_list(): void
    {
        $id = $this->addProduct();

        $result = $this->screen('edit', ['token' => 'good-token', 'reference' => 'NOWHERE']);

        $this->assertSame('/admin/shop/products/', $result['redirect']);
        $this->assertSame('', $this->row($id)['reference']);
        $this->assertSame([], $this->changelogFor(0), 'nothing was saved, so nothing is logged');
    }

    public function test_a_price_change_is_recorded_in_the_price_history_and_an_unchanged_price_is_not(): void
    {
        $id = $this->addProduct(['price_exc_tax' => '10']);

        $this->screen('edit', $this->editForm($id, ['reference' => 'A']));
        $this->assertSame([], $this->priceHistory($id), 'saving without touching the price leaves no entry');

        $this->screen('edit', $this->editForm($id, ['price_exc_tax' => '12.5']));
        $history = $this->priceHistory($id);
        $this->assertCount(1, $history);
        $this->assertEqualsWithDelta(10.0, (float) $history[0]['price_exc_tax_old'], 0.001);
        $this->assertEqualsWithDelta(12.5, (float) $history[0]['price_exc_tax_new'], 0.001);
    }

    public function test_saving_a_product_with_no_rrp_does_not_log_a_price_change_each_time(): void
    {
        $id = $this->addProduct(['price_exc_tax' => '10']);

        $this->screen('edit', $this->editForm($id, ['rrp_exc_tax' => '']));
        $this->screen('edit', $this->editForm($id, ['rrp_exc_tax' => '', 'reference' => 'again']));

        $this->assertSame([], $this->priceHistory($id), 'a blank RRP over a stored 0 is not a change');
    }

    public function test_an_rrp_over_a_thousand_pounds_can_be_saved(): void
    {
        $id = $this->addProduct(['price_exc_tax' => '1200']);

        $this->screen('edit', $this->editForm($id, ['rrp_exc_tax' => '1499.99']));

        $this->assertEqualsWithDelta(1499.99, (float) $this->row($id)['rrp_exc_tax'], 0.001);
    }

    public function test_changing_only_the_rrp_is_recorded_too(): void
    {
        $id = $this->addProduct(['price_exc_tax' => '10']);

        $this->screen('edit', $this->editForm($id, ['rrp_exc_tax' => '15']));

        $this->assertCount(1, $this->priceHistory($id));
    }

    public function test_renaming_a_product_renames_its_images_and_other_edits_do_not(): void
    {
        $id = $this->addProduct();

        $this->screen('edit', $this->editForm($id, ['reference' => 'R']));
        $this->assertSame([], $this->admin->renamed);

        $this->screen('edit', $this->editForm($id, ['model_name' => "{$this->runTag} Renamed"]));
        $this->assertSame([["{$this->runTag} Brand", "{$this->runTag} Widget", "{$this->runTag} Renamed"]], $this->admin->renamed);
    }

    public function test_the_products_page_is_updated_from_the_edit_form_and_the_old_address_redirects(): void
    {
        $id = $this->addProduct();
        $page = $this->pageOf($id);

        $this->screen('edit', $this->editForm($id, [
            'page_id' => (string) $page['id'],
            'page_url' => "{$this->runTag}-new-address",
            'page_title' => 'New page title',
            'page_description' => 'New page words',
            'page_robots' => 'noindex,follow',
        ]));

        $updated = $this->pageRow((int) $page['id']);
        $this->assertSame("{$this->runTag}-new-address", $updated['url']);
        $this->assertSame('New page title', $updated['title']);
        $this->assertSame('noindex,follow', $updated['robots']);
        $redirect = $this->db->prepare(
            sql: 'SELECT path_new FROM ' . $this->db->tablePrefix() . 'redirects WHERE path_old = :old',
            bindings: [':old' => '/' . $page['url']],
        )->single();
        $this->assertSame("/{$this->runTag}-new-address", $redirect['path_new']);
    }

    public function test_a_page_change_that_fails_is_logged_but_the_product_is_still_saved(): void
    {
        $id = $this->addProduct();
        $other = $this->addProduct(['model_name' => "{$this->runTag} Other"]);
        $takenUrl = $this->pageOf($other)['url'];
        $page = $this->pageOf($id);

        $result = $this->screen('edit', $this->editForm($id, ['reference' => 'KEPT', 'page_id' => (string) $page['id'], 'page_url' => $takenUrl, 'page_title' => 'X']));

        $this->assertSame("/admin/shop/products/edit/?id={$id}", $result['redirect']);
        $this->assertSame('KEPT', $this->row($id)['reference']);
        $this->assertSame($page['url'], $this->pageRow((int) $page['id'])['url']);
        $this->assertStringContainsString("Page update failed for product {$id}: A page with this URL already exists", (string) \file_get_contents($this->errorLog));
    }

    public function test_services_are_told_about_a_saved_product(): void
    {
        $id = $this->addProduct();
        $this->installListener();
        ProductSavedListener::$saved = [];

        $this->screen('edit', $this->editForm($id, ['reference' => 'S']));

        $this->assertSame([$id], ProductSavedListener::$saved);
    }

    // ---- the list -----------------------------------------------------------------

    public function test_the_list_shows_live_binned_and_recent_products(): void
    {
        $live = $this->addProduct(['model_name' => "{$this->runTag} Live"]);
        $binned = $this->addProduct(['model_name' => "{$this->runTag} Binned"]);
        $this->db->prepare(
            sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id',
            bindings: [':id' => $binned],
        )->execute();
        \Osmium\Core\Models\Model::pagesById(); // The list skips products whose page the generated routes do not know
        $property = new \ReflectionProperty(\Osmium\Core\Models\Model::class, 'pagesById');
        $pages = $property->getValue() ?? [];
        foreach ([$live, $binned] as $productId) {
            $page = $this->pageOf($productId);
            $pages[(int) $page['id']] = $page + ['deleted_at' => null];
        }
        $property->setValue(null, $pages);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $result = $this->screen('index', isPost: false);
        } finally {
            $property->setValue(null, null);
        }

        $admin = $result['data']['admin'];
        $titles = \array_column($admin['products'], 'id');
        $this->assertContains($live, \array_map('intval', $titles));
        $this->assertNotContains($binned, \array_map('intval', $titles));
        $this->assertContains($binned, \array_map('intval', \array_column($admin['deletedProducts'], 'id')));
        $this->assertNotEmpty($admin['recentProducts']);
        $this->assertTrue($admin['isAdmin']);
        $this->assertGreaterThanOrEqual(2, $admin['totalProductCount']);
    }

    // ---- the import page ----------------------------------------------------------

    public function test_only_an_admin_can_open_the_price_import(): void
    {
        $editor = $this->screen('import', role: 'editor', isPost: false);
        $this->assertSame('/admin/shop/products/', $editor['redirect']);

        $admin = $this->screen('import', role: 'admin', isPost: false);
        $this->assertNull($admin['redirect']);
        $this->assertNull($admin['data']['admin']['importResults']);
    }
}
}
