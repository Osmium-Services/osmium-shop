<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Header;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Library\Url;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\ProductOptionsController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminShopOptions;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/services/AdminShopOptions.php';
require_once 'app/services/osmium-shop/models/ProductOptionType.php';
require_once 'app/services/osmium-shop/models/ProductOptionValue.php';
require_once 'app/services/osmium-shop/controllers/ProductOptionsController.php';

/** Stands in for the redirect that ends a real request; an Error so the controller's own catch blocks let it through. */
final class OptionsRedirectSignal extends \Error {}

/**
 * The product options API: who may call it (a signed-in admin user, POST only, a valid token for anything that
 * changes data), and that each action reaches the right option rule and answers in the shape the configurator
 * reads. The rules themselves are in AdminShopOptionsTest. Real controller and models, rolled back.
 */
class ProductOptionsControllerTest extends DatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private int $productId;
    private string $runTag;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token', 'admin_user_id' => 1];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->runTag = \strtolower('zq' . \substr(\uniqid(), -6));

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $wanted = [];
        $this->model = new OsmiumAdminModel($this->db, (object) \array_intersect_key((array) $all, \array_flip($wanted)));
        $this->model->addModel($this->db, 'productOptionType', \Osmium\Modules\Admin\Models\ProductOptionType::class, 'productoptiontypes'); // The shop service's model
        $this->model->addModel($this->db, 'productOptionValue', \Osmium\Modules\Admin\Models\ProductOptionValue::class, 'productoptionvalues'); // The shop service's model

        $this->auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new Password(),
        ) extends AdminAuth {};

        $this->admin = new class ($this->model, $this->auth) extends OsmiumAdmin {
            public array $input = [];
            public ?array $response = null;

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
                $this->shopOptions = new AdminShopOptions($model->productOptionType, $model->productOptionValue);
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

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "Brand {$this->runTag}"]);
        $this->productId = $this->createTestProduct(['brand_id' => (int) $this->db->lastInsertId(), 'model_name' => "Model {$this->runTag}"]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function controller(): ProductOptionsController
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
                        throw new OptionsRedirectSignal($targetURL);
                    }
                };
            }
        };
        $data = ['admin' => ['basePath' => '/admin/']];

        return new ProductOptionsController($osmium, $this->admin, $data);
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function act(array $input, bool $validToken = true): array
    {
        $this->admin->input = $input + ['csrf_token' => $validToken ? 'good-token' : 'forged'];
        $this->admin->response = null;

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        try {
            $this->controller()->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function types(): array
    {
        return $this->admin->shopOptions->getProductOptions($this->productId);
    }

    // ---- who may call it ----------------------------------------------------------

    public function test_only_a_post_from_a_signed_in_user_is_accepted(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame('Method not allowed', $this->act(['action' => 'get_options', 'product_id' => $this->productId])['error']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SESSION['admin_user_id']);
        $this->assertSame('Not authenticated', $this->act(['action' => 'get_options', 'product_id' => $this->productId])['error']);
        $this->assertSame('Not authenticated', $this->act(['action' => 'add_type', 'product_id' => $this->productId, 'type_label' => 'X'])['error']);
        $this->assertSame([], $this->types());
    }

    public function test_changes_need_a_valid_token_but_reading_does_not(): void
    {
        $actions = [
            ['action' => 'add_type', 'product_id' => $this->productId, 'type_label' => 'Forged'],
            ['action' => 'update_type', 'type_id' => 1, 'type_label' => 'Forged'],
            ['action' => 'delete_type', 'type_id' => 1],
            ['action' => 'add_value', 'type_id' => 1, 'option_descr' => 'Forged'],
            ['action' => 'update_value', 'value_id' => 1, 'option_descr' => 'Forged'],
            ['action' => 'delete_value', 'value_id' => 1],
            ['action' => 'reorder_types', 'items' => [['id' => 1, 'sort' => 1]]],
            ['action' => 'reorder_values', 'items' => [['id' => 1, 'sort' => 1]]],
            ['action' => 'copy_from_product', 'source_product_id' => 1, 'target_product_id' => $this->productId],
        ];

        foreach ($actions as $input) {
            $this->assertStringContainsString('Invalid request token', $this->act($input, validToken: false)['error'], $input['action']);
        }
        $this->assertSame([], $this->types());
        $this->assertTrue($this->act(['action' => 'get_options', 'product_id' => $this->productId], validToken: false)['ok']);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->assertSame('Unknown action', $this->act(['action' => 'explode'])['error']);
        $this->assertSame('Unknown action', $this->act([])['error']);
    }

    public function test_the_index_sends_you_to_the_products_list(): void
    {
        try {
            $this->controller()->index();
            $this->fail('Expected a redirect');
        } catch (OptionsRedirectSignal $e) {
            $this->assertSame('/admin/shop/products/', $e->getMessage());
        }
    }

    // ---- what each action does ----------------------------------------------------

    public function test_a_type_and_its_choices_are_added_read_changed_and_removed(): void
    {
        $added = $this->act(['action' => 'add_type', 'product_id' => $this->productId, 'type_label' => 'Colour']);
        $this->assertTrue($added['ok'], $added['error'] ?? '');
        $this->assertSame('colour', $added['body']['type_slug']);
        $this->assertTrue($added['body']['success']);
        $typeId = $added['body']['id'];

        $value = $this->act(['action' => 'add_value', 'type_id' => $typeId, 'option_descr' => 'Red', 'option_premium' => 4]);
        $this->assertTrue($value['body']['success']);
        $valueId = $value['body']['id'];

        $read = $this->act(['action' => 'get_options', 'product_id' => $this->productId]);
        $this->assertSame(['Colour'], \array_column($read['body']['optionTypes'], 'type_label'));
        $this->assertSame(['Red'], \array_column($read['body']['optionTypes'][0]['values'], 'option_descr'));

        $this->assertSame(['success' => true], $this->act(['action' => 'update_type', 'type_id' => $typeId, 'type_label' => 'Colours'])['body']);
        $this->assertSame(['success' => true], $this->act(['action' => 'update_value', 'value_id' => $valueId, 'option_descr' => 'Crimson'])['body']);
        $this->assertSame('Colours', $this->types()[0]['type_label']);
        $this->assertSame('Crimson', $this->types()[0]['values'][0]['option_descr']);

        $this->assertTrue($this->act(['action' => 'delete_value', 'value_id' => $valueId])['ok']);
        $this->assertSame([], $this->types()[0]['values']);
        $this->assertTrue($this->act(['action' => 'delete_type', 'type_id' => $typeId])['ok']);
        $this->assertSame([], $this->types());
    }

    public function test_reordering_and_copying_reach_the_option_rules(): void
    {
        $a = $this->act(['action' => 'add_type', 'product_id' => $this->productId, 'type_label' => 'A'])['body']['id'];
        $b = $this->act(['action' => 'add_type', 'product_id' => $this->productId, 'type_label' => 'B'])['body']['id'];
        $x = $this->act(['action' => 'add_value', 'type_id' => $a, 'option_descr' => 'X'])['body']['id'];
        $y = $this->act(['action' => 'add_value', 'type_id' => $a, 'option_descr' => 'Y'])['body']['id'];

        $this->act(['action' => 'reorder_types', 'items' => [['id' => $a, 'sort' => 1], ['id' => $b, 'sort' => 0]]]);
        $this->act(['action' => 'reorder_values', 'items' => [['id' => $x, 'sort' => 1], ['id' => $y, 'sort' => 0]]]);
        $types = $this->types();
        $this->assertSame(['B', 'A'], \array_column($types, 'type_label'));
        $this->assertSame(['Y', 'X'], \array_column($types[1]['values'], 'option_descr'));

        $target = $this->createTestProduct(['brand_id' => 1]);
        $copied = $this->act(['action' => 'copy_from_product', 'source_product_id' => $this->productId, 'target_product_id' => $target]);
        $this->assertSame(['success' => true, 'types_copied' => 2, 'values_copied' => 2], $copied['body']);
    }

    public function test_a_rule_refusal_comes_back_as_the_error_message(): void
    {
        $this->assertSame('Type label is required', $this->act(['action' => 'add_type', 'product_id' => $this->productId, 'type_label' => ''])['error']);
        $this->assertSame('Product ID is required', $this->act(['action' => 'get_options'])['error']);
        $this->assertSame('Type ID is required', $this->act(['action' => 'update_type', 'type_label' => 'X'])['error']);
        $this->assertSame('Value ID is required', $this->act(['action' => 'delete_value'])['error']);
        $this->assertSame('No items provided', $this->act(['action' => 'reorder_types'])['error']);
        $this->assertSame('Cannot copy to same product', $this->act(['action' => 'copy_from_product', 'source_product_id' => $this->productId, 'target_product_id' => $this->productId])['error']);
    }
}
