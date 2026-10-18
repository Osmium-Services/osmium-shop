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
use Osmium\Modules\Admin\Controllers\BrandsController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminPresence;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/core/admin/models/ListRows.php';
require_once 'app/services/osmium-shop/models/Brand.php';
require_once 'app/services/osmium-shop/controllers/BrandsController.php';

/**
 * The brands screen: the form that creates and edits a brand (what is stored, what is refused), the action
 * endpoint (bin, restore, permanent delete, who may do them and when a brand is still in use) and the lists.
 * Real controller, real models, rolled back.
 */
class BrandsControllerTest extends DatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private string $runTag;
    private int $stashedId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $_POST = [];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->runTag = 'ZQ' . \strtoupper(\substr(\uniqid(), -6));

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog', 'listRows', 'product']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'brand', \Osmium\Modules\Admin\Models\Brand::class, 'productbrands'); // The shop service's model

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

        $this->admin = new class ($this->model, $this->auth) extends OsmiumAdmin {
            public ?array $response = null;

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
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
        $_SESSION = [];
        $_POST = [];
        $_SERVER = $this->savedServer;
        parent::tearDown(); // Rolls back; the ids those inserts used are free again
    }

    private function controller(array &$data): BrandsController
    {
        $osmium = new class ($this->db, $this->stashedId) extends Osmium {
            public function __construct(OsmiumPDO $db, private int $stashedId)
            {
                $this->dataSource = $db;
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

            public function getStashedParam(string $key, mixed $default = null): mixed
            {
                return $key === 'id' && $this->stashedId ? $this->stashedId : $default;
            }

            public function clearStashedQuerystring(): void {}
        };

        return new BrandsController($osmium, $this->admin, $data);
    }

    private function signInAs(string $role): array
    {
        $_SESSION['admin_user_id'] = 1;
        $_SESSION['admin_user_role'] = $role;
        $_SESSION['admin_user_username'] = "{$role}-tester";

        return ['admin' => ['basePath' => '/admin/', 'user' => ['role' => $role]]];
    }

    /**
     * Posts the edit form.
     *
     * @return array{redirect: ?string, data: array}
     */
    private function submit(array $fields, bool $validToken = true, string $role = 'admin'): array
    {
        $data = $this->signInAs($role);
        $_POST = $fields + ['token' => $validToken ? 'good-token' : 'forged'];
        $redirect = null;

        try {
            $this->controller($data)->edit();
        } catch (\RuntimeException $e) {
            $redirect = $e->getMessage();
        }

        return ['redirect' => $redirect, 'data' => $data];
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function act(array $input, string $role = 'admin', bool $validToken = true): array
    {
        $data = $this->signInAs($role);
        $this->auth->input = $input + ['csrf_token' => $validToken ? 'good-token' : 'forged'];

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent')); // The controller sets a JSON header; the CLI has already printed progress dots
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => "{$this->runTag} Victron",
            'page_url' => 'victron',
            'info_external' => 'https://example.test/victron',
        ];
    }

    private function stored(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'productbrands WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function brandCount(): int
    {
        return (int) $this->db->prepare('SELECT COUNT(*) AS total FROM ' . $this->db->tablePrefix() . 'productbrands')->single()['total'];
    }

    private function createdId(array $result): int
    {
        $this->assertMatchesRegularExpression('#^/admin/shop/brands/edit/\?id=\d+$#', (string) $result['redirect']);

        return (int) \substr((string) $result['redirect'], \strrpos((string) $result['redirect'], '=') + 1);
    }

    private function changelogFor(int $id): array
    {
        return \array_column($this->db->prepare(
            sql: "SELECT description FROM " . $this->db->tablePrefix() . "changelog WHERE record_type = 'brand' AND record_id = :id ORDER BY id",
            bindings: [':id' => $id],
        )->resultset(), 'description');
    }

    private function useBrand(int $brandId, bool $thenBinProduct = false): int
    {
        $productId = $this->createTestProduct(['brand_id' => $brandId]);
        if ($thenBinProduct) {
            $this->db->prepare(
                sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id',
                bindings: [':id' => $productId],
            )->execute();
        }

        return $productId;
    }

    // ---- the form: creating -------------------------------------------------------

    public function test_a_forged_token_creates_nothing(): void
    {
        $before = $this->brandCount();

        $result = $this->submit($this->form(), validToken: false);

        $this->assertNull($result['redirect']);
        $this->assertSame('Invalid form submission. Please try again.', $result['data']['admin']['error']);
        $this->assertSame($before, $this->brandCount());
    }

    public function test_a_brand_is_stored_and_the_user_is_sent_to_its_edit_page(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $row = $this->stored($id);
        $this->assertSame("{$this->runTag} Victron", $row['name']);
        $this->assertSame('victron', $row['page_url']);
        $this->assertSame('https://example.test/victron', $row['info_external']);
        $this->assertTrue($_SESSION['brand_saved']);
        $this->assertSame(["Created brand: {$this->runTag} Victron"], $this->changelogFor($id));
    }

    public function test_the_optional_fields_are_stored_as_nothing_when_left_blank(): void
    {
        $id = $this->createdId($this->submit($this->form(['page_url' => '', 'info_external' => ''])));

        $row = $this->stored($id);
        $this->assertNull($row['page_url']);
        $this->assertNull($row['info_external']);
    }

    public function test_a_brand_needs_a_name(): void
    {
        $before = $this->brandCount();

        $result = $this->submit($this->form(['name' => '']));

        $this->assertNull($result['redirect']);
        $this->assertSame('Brand name is required', $result['data']['admin']['error']);
        $this->assertSame($before, $this->brandCount());
    }

    public function test_a_value_longer_than_its_column_is_refused_with_a_message_not_a_database_error(): void
    {
        $before = $this->brandCount();

        foreach (['name' => 51, 'page_url' => 101, 'info_external' => 256] as $field => $length) {
            $result = $this->submit($this->form([$field => \str_repeat('x', $length)]));

            $this->assertNull($result['redirect'], $field);
            $this->assertStringContainsString('up to 50 characters', $result['data']['admin']['error'], $field);
        }
        $this->assertSame($before, $this->brandCount());

        $edge = $this->submit($this->form(['name' => \str_repeat('x', 50), 'page_url' => \str_repeat('y', 100), 'info_external' => 'https://example.test/' . \str_repeat('z', 234)]));
        $this->assertNotNull($edge['redirect'], 'the longest values that fit are accepted');
    }

    public function test_an_external_info_link_must_be_a_web_address(): void
    {
        $before = $this->brandCount();

        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.test/a', '//example.test', 'example.test', 'https://exa mple.test'] as $bad) {
            $result = $this->submit($this->form(['info_external' => $bad]));

            $this->assertNull($result['redirect'], $bad);
            $this->assertStringContainsString('http:// or https://', $result['data']['admin']['error'], $bad);
        }
        $this->assertSame($before, $this->brandCount());

        $ok = $this->submit($this->form(['info_external' => 'http://example.test/brand?a=1']));
        $this->assertNotNull($ok['redirect'], 'http links are accepted');
    }

    // ---- the form: editing --------------------------------------------------------

    public function test_editing_a_brand_updates_it_and_logs_the_change(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $result = $this->submit($this->form(['id' => (string) $id, 'name' => "{$this->runTag} Renamed", 'page_url' => '']));

        $this->assertSame("/admin/shop/brands/edit/?id={$id}", $result['redirect']);
        $row = $this->stored($id);
        $this->assertSame("{$this->runTag} Renamed", $row['name']);
        $this->assertNull($row['page_url']);
        $this->assertContains("Updated brand: {$this->runTag} Renamed", $this->changelogFor($id));
    }

    public function test_a_refused_edit_leaves_the_stored_brand_alone(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->submit($this->form(['id' => (string) $id, 'name' => '']));

        $this->assertSame("{$this->runTag} Victron", $this->stored($id)['name']);
    }

    // ---- the form: showing it -----------------------------------------------------

    public function test_a_new_brand_form_starts_empty(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        $this->controller($data)->edit();

        $this->assertTrue($data['admin']['isNew']);
        $this->assertSame('', $data['admin']['brand']['name']);
        $this->assertFalse($data['admin']['showSavedMessage']);
    }

    public function test_an_existing_brand_form_is_filled_in_and_the_saved_message_shows_once(): void
    {
        $id = $this->createdId($this->submit($this->form()));
        $this->stashedId = $id;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $data = $this->signInAs('admin');
        $this->controller($data)->edit();
        $this->assertFalse($data['admin']['isNew']);
        $this->assertSame("{$this->runTag} Victron", $data['admin']['brand']['name']);
        $this->assertTrue($data['admin']['showSavedMessage']);

        $data = $this->signInAs('admin');
        $this->controller($data)->edit();
        $this->assertFalse($data['admin']['showSavedMessage']);
    }

    public function test_asking_for_a_brand_that_does_not_exist_goes_back_to_the_list(): void
    {
        $this->stashedId = 999999999;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        try {
            $this->controller($data)->edit();
            $this->fail('expected a redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('/admin/shop/brands/', $e->getMessage());
        }
    }

    // ---- the action endpoint ------------------------------------------------------

    public function test_only_a_post_is_accepted_by_the_action_endpoint(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertSame('Method not allowed', $this->act(['action' => 'delete', 'id' => 1])['error']);
    }

    public function test_a_wrong_token_changes_nothing_on_the_action_endpoint(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $response = $this->act(['action' => 'delete', 'id' => $id], validToken: false);

        $this->assertStringContainsString('Invalid request token', $response['error']);
        $this->assertNull($this->stored($id)['deleted_at']);
    }

    public function test_a_request_without_an_action_or_a_brand_is_refused(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->assertSame('Missing parameters', $this->act(['id' => $id])['error'], 'no action');
        $this->assertSame('Missing parameters', $this->act(['action' => 'delete'])['error'], 'no brand');
        $this->assertSame('Unknown action', $this->act(['action' => 'explode', 'id' => $id])['error']);
    }

    public function test_an_unused_brand_is_binned_and_restored(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->assertTrue($this->act(['action' => 'delete', 'id' => $id], role: 'editor')['ok']);
        $this->assertNotNull($this->stored($id)['deleted_at']);

        $this->assertTrue($this->act(['action' => 'restore', 'id' => $id], role: 'editor')['ok']);
        $this->assertNull($this->stored($id)['deleted_at']);
        $this->assertSame(
            ["Created brand: {$this->runTag} Victron", "Deleted brand: {$this->runTag} Victron", "Restored brand: {$this->runTag} Victron"],
            $this->changelogFor($id),
        );
    }

    public function test_a_brand_a_live_product_uses_cannot_be_binned(): void
    {
        $id = $this->createdId($this->submit($this->form()));
        $this->useBrand($id);

        $response = $this->act(['action' => 'delete', 'id' => $id]);

        $this->assertSame('Cannot delete: 1 product(s) are using this brand', $response['error']);
        $this->assertNull($this->stored($id)['deleted_at']);
    }

    public function test_a_brand_only_a_binned_product_uses_can_be_binned_but_not_deleted_for_good(): void
    {
        $id = $this->createdId($this->submit($this->form()));
        $this->useBrand($id, thenBinProduct: true);

        $this->assertTrue($this->act(['action' => 'delete', 'id' => $id])['ok']);

        $forGood = $this->act(['action' => 'permanently_delete', 'id' => $id], role: 'admin');
        $this->assertSame('Cannot delete: 1 product(s) are linked to this brand', $forGood['error']);
        $this->assertNotSame([], $this->stored($id));
    }

    public function test_only_an_admin_can_delete_a_brand_for_good(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->assertSame('Permission denied', $this->act(['action' => 'permanently_delete', 'id' => $id], role: 'editor')['error']);
        $this->assertNotSame([], $this->stored($id));

        $this->assertTrue($this->act(['action' => 'permanently_delete', 'id' => $id], role: 'admin')['ok']);
        $this->assertSame([], $this->stored($id));
    }

    // ---- the list -----------------------------------------------------------------

    public function test_the_list_shows_live_and_binned_brands_and_who_may_empty_the_bin(): void
    {
        $live = $this->createdId($this->submit($this->form()));
        $binned = $this->createdId($this->submit($this->form(['name' => "{$this->runTag} Binned"])));
        $this->act(['action' => 'delete', 'id' => $binned]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        $this->controller($data)->index();

        $this->assertContains($live, \array_map('intval', \array_column($data['admin']['brands'], 'id')));
        $this->assertNotContains($binned, \array_map('intval', \array_column($data['admin']['brands'], 'id')));
        $this->assertContains($binned, \array_map('intval', \array_column($data['admin']['deletedBrands'], 'id')));
        $this->assertTrue($data['admin']['isAdmin']);
    }
}
