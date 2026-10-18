<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Header;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Library\Url;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\ProductImagesController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminShopImages;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/services/AdminShopImages.php';
require_once 'app/services/osmium-shop/controllers/ProductImagesController.php';

/**
 * The product image API (/admin/shop/product-images/action): method, sign-in and token checks, routing of each
 * action to the real AdminShopImages (real GD and files under a throwaway document root, real product rows rolled
 * back), and what comes back as JSON. The image processing itself is AdminShopImagesTest's job.
 */
class ProductImagesControllerTest extends DatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private string $root;
    private array $savedServer;
    private string $logFile;
    private int $logSize;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token', 'admin_user_id' => 1];
        $_FILES = [];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->root = \sys_get_temp_dir() . '/osmium_productimages_test_' . \uniqid();
        \mkdir($this->root);
        $_SERVER['DOCUMENT_ROOT'] = $this->root;

        $this->logFile = \dirname(__DIR__, 3) . '/app/logs/image-upload.log';
        $this->logSize = \is_file($this->logFile) ? (int) \filesize($this->logFile) : -1;

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $this->model = new OsmiumAdminModel($this->db, (object) \array_intersect_key((array) $all, \array_flip(['product', 'user'])));

        $auth = new AdminAuth($this->model, new RecordingAdminMailer(), new Password());

        $this->admin = new class ($this->model, $auth) extends OsmiumAdmin {
            public ?array $response = null;
            public array $input = [];

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
                $this->shopImages = new AdminShopImages($model->product);
            }

            public function getJsonInput(): array
            {
                return $this->input;
            }

            public function jsonSuccess(array $result, int $code = 200): void
            {
                $this->response = ['ok' => true, 'body' => $result];
                throw new ResponseSentException();
            }

            public function jsonError(string $message, int $code = 200, array $extra = []): void
            {
                $this->response = ['ok' => false, 'error' => $message];
                throw new ResponseSentException();
            }
        };
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $_SESSION = [];
        $_FILES = [];
        $_SERVER = $this->savedServer;

        if ($this->logSize === -1) {
            @\unlink($this->logFile);
        } elseif (\is_file($this->logFile)) {
            $handle = \fopen($this->logFile, 'r+');
            \ftruncate($handle, $this->logSize);
            \fclose($handle);
        }
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

    private function controller(array &$data): ProductImagesController
    {
        $osmium = new class extends Osmium {
            public function __construct()
            {
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
                        throw new \RuntimeException($targetURL);
                    }
                };
            }
        };

        return new ProductImagesController($osmium, $this->admin, $data);
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function call(array $input, bool $signedToken = true): array
    {
        $this->admin->input = $input + ($signedToken ? ['csrf_token' => 'good-token'] : []);
        $this->admin->response = null;
        $data = ['admin' => ['basePath' => '/admin/']];

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function shop(string $relative = ''): string
    {
        return "{$this->root}/assets/images/shop/{$relative}";
    }

    private function place(string $relative): void
    {
        @\mkdir(\dirname($this->shop($relative)), 0755, true);
        \file_put_contents($this->shop($relative), 'x');
    }

    public function test_the_bare_page_goes_back_to_the_products_list(): void
    {
        $data = ['admin' => ['basePath' => '/admin/']];

        try {
            $this->controller($data)->index();
            $this->fail('expected a redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('/admin/shop/products/', $e->getMessage());
        }
    }

    public function test_it_only_answers_post_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertSame('Method not allowed', $this->call(['action' => 'get_images'])['error']);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        unset($_SESSION['admin_user_id']);

        $this->assertSame('Not authenticated', $this->call(['action' => 'get_images'])['error']);
    }

    public function test_a_change_with_no_token_at_all_is_refused(): void
    {
        $change = ['action' => 'update_descr_image', 'product_id' => $this->createTestProduct(), 'field' => 'descr_image', 'value' => ''];

        $this->assertSame('Invalid request token. Please refresh the page and try again.', $this->call($change, signedToken: false)['error']);
    }

    public function test_a_wrong_or_missing_token_is_refused_for_every_change(): void
    {
        $product = $this->createTestProduct();

        foreach (['upload', 'delete', 'update_descr_image', 'regenerate', 'anything'] as $action) {
            $this->admin->input = ['action' => $action, 'product_id' => $product, 'csrf_token' => 'forged', 'token' => 'also-forged'];
            $this->admin->response = null;
            $data = ['admin' => ['basePath' => '/admin/']];
            try {
                $this->controller($data)->action();
            } catch (ResponseSentException) {
            }

            $this->assertSame('Invalid request token. Please refresh the page and try again.', $this->admin->response['error'], $action);
        }
    }

    public function test_the_form_style_token_field_is_also_accepted(): void
    {
        $this->admin->input = ['action' => 'update_descr_image', 'product_id' => $this->createTestProduct(), 'field' => 'descr_image', 'value' => '', 'token' => 'good-token'];

        $data = ['admin' => ['basePath' => '/admin/']];
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        }

        $this->assertTrue($this->admin->response['ok']);
    }

    public function test_an_empty_session_token_never_matches_an_empty_posted_one(): void
    {
        $_SESSION['token'] = '';

        $this->assertSame('Invalid request token. Please refresh the page and try again.', $this->call(['action' => 'delete', 'csrf_token' => ''], signedToken: false)['error']);
    }

    public function test_listing_images_needs_no_token(): void
    {
        $product = $this->createTestProduct();
        $this->place('acme/widget/large/acme-widget.jpg');

        $result = $this->call(['action' => 'get_images', 'product_id' => $product, 'brand_path' => 'acme', 'model_path' => 'widget'], signedToken: false);

        $this->assertTrue($result['ok']);
        $this->assertSame(['success' => true], ['success' => $result['body']['success']]);
        $this->assertSame('/assets/images/shop/acme/widget/large/acme-widget.jpg', $result['body']['images']['main']);
        $this->assertNull($result['body']['images']['extra1']);
    }

    public function test_listing_images_for_a_missing_product_or_paths_is_refused(): void
    {
        $this->assertSame('Product ID is required', $this->call(['action' => 'get_images', 'brand_path' => 'a', 'model_path' => 'b'])['error']);
        $this->assertSame('Brand and model paths are required', $this->call(['action' => 'get_images', 'product_id' => $this->createTestProduct()])['error']);
    }

    public function test_an_upload_is_saved_and_reported(): void
    {
        $product = $this->createTestProduct();
        $path = "{$this->root}/upload.png";
        \imagepng(\imagecreatetruecolor(900, 900), $path);
        $_FILES['image'] = ['name' => 'p.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) \filesize($path)];

        $result = $this->call(['action' => 'upload', 'product_id' => (string) $product, 'slot' => 'extra1', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame(['success' => true, 'slot' => 'extra1', 'imagePath' => '/shop/acme/widget/large/acme-widget-extra1.jpg'], $result['body']);
        $this->assertFileExists($this->shop('acme/widget/large/acme-widget-extra1.jpg'));
    }

    public function test_an_upload_with_no_file_says_so(): void
    {
        $result = $this->call(['action' => 'upload', 'product_id' => $this->createTestProduct(), 'brand_path' => 'a', 'model_path' => 'b']);

        $this->assertSame(['ok' => false, 'error' => 'No file was uploaded'], $result);
    }

    public function test_deleting_an_image_removes_its_files(): void
    {
        $product = $this->createTestProduct();
        $this->place('acme/widget/large/acme-widget.jpg');
        $this->place('acme/widget/thumb/acme-widget.jpg');

        $result = $this->call(['action' => 'delete', 'product_id' => $product, 'slot' => 'main', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame(['success' => true, 'slot' => 'main', 'deleted' => 2], $result['body']);
        $this->assertFileDoesNotExist($this->shop('acme/widget/large/acme-widget.jpg'));
    }

    public function test_a_description_image_is_saved_on_the_product(): void
    {
        $product = $this->createTestProduct();

        $result = $this->call(['action' => 'update_descr_image', 'product_id' => $product, 'field' => 'descr_image', 'value' => '/shop/a/b/large/a-b.jpg']);

        $this->assertTrue($result['body']['success']);
        $this->assertStringContainsString('/assets/images/shop/a/b/large/a-b.jpg', $result['body']['html']);
        $table = $this->db->tablePrefix() . 'products';
        $stored = $this->db->prepare(sql: "SELECT descr_image FROM {$table} WHERE id = :id", bindings: [':id' => $product])->single();
        $this->assertSame($result['body']['html'], $stored['descr_image']);
    }

    public function test_regenerating_hands_back_the_processors_answer_as_it_is(): void
    {
        $product = $this->createTestProduct();

        $missing = $this->call(['action' => 'regenerate', 'product_id' => $product, 'brand_path' => 'acme', 'model_path' => 'widget']);
        $this->assertSame(['success' => false, 'error' => 'Large image not found'], $missing['body'], 'a refusal from the processor is still a 200 with success false');

        \mkdir($this->shop('acme/widget/large'), 0755, true);
        \imagejpeg(\imagecreatetruecolor(300, 300), $this->shop('acme/widget/large/acme-widget.jpg'));

        $done = $this->call(['action' => 'regenerate', 'product_id' => $product, 'brand_path' => 'acme', 'model_path' => 'widget']);
        $this->assertTrue($done['body']['success']);
        $this->assertFileExists($this->shop('acme/widget/thumb/acme-widget.jpg'));
    }

    public function test_an_unknown_or_missing_action_is_refused_after_the_token_check(): void
    {
        $this->assertSame('Unknown action', $this->call(['action' => 'explode', 'product_id' => $this->createTestProduct()])['error']);
        $this->assertSame('Unknown action', $this->call([])['error']);
    }

    public function test_any_output_the_services_print_is_thrown_away(): void
    {
        $product = $this->createTestProduct();

        \ob_start();
        $result = $this->call(['action' => 'delete', 'product_id' => $product, 'slot' => 'main', 'brand_path' => 'a', 'model_path' => 'b']);
        $leaked = \ob_get_clean();

        $this->assertTrue($result['ok']);
        $this->assertSame('', $leaked);
    }
}
