<?php

declare(strict_types=1);

namespace {
    require_once 'app/services/osmium-shop/tests/Helpers/OsmiumShopStandIn.php'; // The controller builds the shop module's global-alias class
}

namespace Tests\Integration\Admin {

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\ProductsController;
use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminPresence;
use Osmium\Modules\Admin\Services\AdminShop;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\ShopAdminServices;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/services/osmium-shop/models/AdminShop.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/controllers/ProductsController.php';

/**
 * The product screen's action endpoint: what each action does, who may do it,
 * and what a bad request gets back. Real controller, real models, rolled back.
 * Actions that rewrite the live routes file (duplicate) or need a real HTTP
 * upload (pdf_upload) are not driven from here.
 */
class ProductsControllerActionTest extends ShopDatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private string $savedDisplayErrors;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $_POST = [];
        $_FILES = [];
        $this->savedServer = $_SERVER;
        $this->savedDisplayErrors = (string) \ini_get('display_errors');
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'product', \Osmium\Modules\Admin\Models\Product::class, 'products'); // The shop service's model

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
            public ?array $response = null;

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth, AdminShop $shop)
            {
                $this->model = $model;
                $this->auth = $auth;
                ShopAdminServices::override($this, 'shop', $shop);
                $this->presence = new class extends AdminPresence {
                    public function __construct() {}

                    public function handleAction(string $action, array $input, string $recordType): bool
                    {
                        return false;
                    }
                };
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
        \ini_set('display_errors', $this->savedDisplayErrors); // The controller switches it off
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function signInAs(string $role): void
    {
        $_SESSION['admin_user_id'] = 1;
        $_SESSION['admin_user_role'] = $role;
        $_SESSION['admin_user_username'] = "{$role}-tester";
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function call(array $input): array
    {
        $this->auth->input = $input + ['csrf_token' => 'good-token'];
        $data = [];
        $osmium = new class ($this->db) extends Osmium {
            public function __construct(OsmiumPDO $db)
            {
                $this->dataSource = $db;
            }
        };

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent')); // The controller sets a JSON header; the CLI has already printed progress dots
        try {
            (new ProductsController($osmium, $this->admin, $data))->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function createBrand(string $name): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => $name]);

        return (int) $this->db->lastInsertId();
    }

    private function stored(int $id): array
    {
        $table = $this->db->tablePrefix() . 'products';

        return $this->db->prepare(sql: "SELECT * FROM {$table} WHERE id = :id", bindings: [':id' => $id])->single() ?: [];
    }

    private function changelogFor(int $id): array
    {
        $table = $this->db->tablePrefix() . 'changelog';

        return \array_column($this->db->prepare(sql: "SELECT description FROM {$table} WHERE record_type = 'product' AND record_id = :id", bindings: [':id' => $id])->resultset(), 'description');
    }

    // ---- request checks -----------------------------------------------------------

    public function test_only_a_post_is_accepted(): void
    {
        $this->signInAs('admin');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertSame('Method not allowed', $this->call(['action' => 'delete', 'id' => 1])['error']);
    }

    public function test_a_wrong_token_changes_nothing(): void
    {
        $this->signInAs('admin');
        $id = $this->createTestProduct();

        $response = $this->call(['action' => 'delete', 'id' => $id, 'csrf_token' => 'forged']);

        $this->assertStringContainsString('Invalid request token', $response['error']);
        $this->assertNull($this->stored($id)['deleted_at']);
    }

    public function test_a_request_without_an_action_or_a_product_is_refused_cleanly(): void
    {
        $this->signInAs('admin');
        $id = $this->createTestProduct();

        $this->assertSame('Missing parameters', $this->call(['id' => $id])['error'], 'no action');
        $this->assertSame('Missing parameters', $this->call(['action' => 'delete'])['error'], 'no product');
        $this->assertSame('Unknown action', $this->call(['action' => 'explode', 'id' => $id])['error']);
    }

    public function test_the_document_and_technical_actions_need_a_signed_in_user(): void
    {
        foreach (['pdf_upload', 'pdf_delete', 'pdf_get', 'get_technical'] as $action) {
            $this->assertSame('Not authenticated', $this->call(['action' => $action])['error'], $action);
        }
    }

    // ---- what the actions do ------------------------------------------------------

    public function test_deleting_and_restoring_a_product_is_recorded_in_the_changelog(): void
    {
        $this->signInAs('editor');
        $id = $this->createTestProduct(['model_name' => 'Zz Widget']);

        $this->assertTrue($this->call(['action' => 'delete', 'id' => $id])['ok']);
        $this->assertNotNull($this->stored($id)['deleted_at']);

        $restored = $this->call(['action' => 'restore', 'id' => $id]);
        $this->assertTrue($restored['ok']);
        $this->assertStringContainsString('check the product status', $restored['body']['message']);
        $this->assertNull($this->stored($id)['deleted_at']);

        $this->assertSame(['Deleted product: Zz Widget', 'Restored product: Zz Widget'], $this->changelogFor($id));
    }

    public function test_only_an_admin_can_discontinue_a_product(): void
    {
        $id = $this->createTestProduct(['status_id' => 1]);

        $this->signInAs('editor');
        $this->assertSame('Permission denied', $this->call(['action' => 'discontinue', 'id' => $id])['error']);
        $this->assertSame(1, (int) $this->stored($id)['status_id']);

        $this->signInAs('admin');
        $this->assertTrue($this->call(['action' => 'discontinue', 'id' => $id])['ok']);
        $this->assertSame(3, (int) $this->stored($id)['status_id']);
    }

    public function test_a_product_older_than_an_hour_cannot_be_deleted_completely(): void
    {
        $this->signInAs('editor');
        $id = $this->createTestProduct();
        $table = $this->db->tablePrefix() . 'products';
        $this->db->prepare(sql: "UPDATE {$table} SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = :id", bindings: [':id' => $id])->execute();

        $response = $this->call(['action' => 'delete_completely', 'id' => $id]);

        $this->assertSame('Can only undo products created within the last hour', $response['error']);
        $this->assertNotEmpty($this->stored($id), 'the product is still there');
    }

    public function test_a_product_just_created_can_be_undone_completely(): void
    {
        $this->signInAs('editor');
        $brandId = $this->createBrand('Zz Undo Brand ' . \uniqid());
        $id = $this->createTestProduct(['brand_id' => $brandId, 'model_name' => 'Zz Undo Me ' . \uniqid()]); // No page and no images, so nothing outside the database is touched

        $response = $this->call(['action' => 'delete_completely', 'id' => $id]);

        $this->assertTrue($response['ok'], (string) ($response['error'] ?? ''));
        $this->assertEmpty($this->stored($id), 'the row is gone, not just hidden');
        $this->assertStringStartsWith('Permanently deleted product: Zz Undo Me', $this->changelogFor($id)[0]);
    }

    public function test_completely_deleting_a_product_that_does_not_exist_is_refused(): void
    {
        $this->signInAs('editor');

        $this->assertSame('Product not found', $this->call(['action' => 'delete_completely', 'id' => 999999999])['error']);
    }

    public function test_technical_details_are_copied_from_another_product(): void
    {
        $this->signInAs('editor');
        $source = $this->createTestProduct();
        $table = $this->db->tablePrefix() . 'products';
        $this->db->prepare(sql: "UPDATE {$table} SET techspec = :t WHERE id = :id", bindings: [':t' => '{"weight":"2kg"}', ':id' => $source])->execute();

        $this->assertSame('Source product ID required', $this->call(['action' => 'get_technical'])['error']);

        $response = $this->call(['action' => 'get_technical', 'source_product_id' => $source]);
        $this->assertTrue($response['ok']);
        $this->assertSame('{"weight":"2kg"}', $response['body']['techspec']);
    }

    public function test_looking_up_documents_needs_both_paths(): void
    {
        $this->signInAs('editor');

        $this->assertSame('Brand and model paths are required', $this->call(['action' => 'pdf_get', 'brand_path' => 'acme'])['error']);
        $this->assertTrue($this->call(['action' => 'pdf_get', 'brand_path' => 'zz-no-brand', 'model_path' => 'zz-no-model'])['ok']);
    }
}

}
