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
use Osmium\Modules\Admin\Controllers\StatusesController;
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
require_once 'app/services/osmium-shop/models/Status.php';
require_once 'app/services/osmium-shop/controllers/StatusesController.php';

/**
 * The product statuses screen: the form that creates and edits a status (what is stored, what is refused), the
 * action endpoint (bin, restore, permanent delete, who may do them and when a status is still in use) and the
 * lists. Real controller, real models, rolled back.
 *
 * productstatus.id is a tinyint and a rolled-back insert still uses up an id, so tearDown puts the counter back.
 */
class StatusesControllerTest extends DatabaseTestCase
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
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog', 'listRows']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'status', \Osmium\Modules\Admin\Models\Status::class, 'productstatus'); // The shop service's model

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

        $table = $this->db->tablePrefix() . 'productstatus';
        $next = (int) $this->db->prepare("SELECT COALESCE(MAX(id), 0) + 1 AS next FROM {$table}")->single()['next'];
        $this->db->prepare("ALTER TABLE {$table} AUTO_INCREMENT = {$next}")->execute();
    }

    private function controller(array &$data): StatusesController
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

        return new StatusesController($osmium, $this->admin, $data);
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
            'descr_short' => "{$this->runTag} Available",
            'descr_long' => 'Ready to ship',
            'product_available' => '1',
            'enabled' => '1',
        ];
    }

    private function stored(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'productstatus WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function statusCount(): int
    {
        return (int) $this->db->prepare('SELECT COUNT(*) AS total FROM ' . $this->db->tablePrefix() . 'productstatus')->single()['total'];
    }

    private function createdId(array $result): int
    {
        $this->assertMatchesRegularExpression('#^/admin/shop/statuses/edit/\?id=\d+$#', (string) $result['redirect']);

        return (int) \substr((string) $result['redirect'], \strrpos((string) $result['redirect'], '=') + 1);
    }

    private function changelogFor(int $id): array
    {
        return \array_column($this->db->prepare(
            sql: "SELECT description FROM " . $this->db->tablePrefix() . "changelog WHERE record_type = 'status' AND record_id = :id ORDER BY id",
            bindings: [':id' => $id],
        )->resultset(), 'description');
    }

    private function useStatus(int $statusId, bool $thenBinProduct = false): int
    {
        $productId = $this->createTestProduct(['status_id' => $statusId]);
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
        $before = $this->statusCount();

        $result = $this->submit($this->form(), validToken: false);

        $this->assertNull($result['redirect']);
        $this->assertSame('Invalid form submission. Please try again.', $result['data']['admin']['error']);
        $this->assertSame($before, $this->statusCount());
    }

    public function test_a_status_is_stored_with_its_switches_and_the_user_is_sent_to_its_edit_page(): void
    {
        $id = $this->createdId($this->submit($this->form([
            'restocking' => '1',
            'schema_ref' => 'InStock',
            'list' => '1',
        ])));

        $row = $this->stored($id);
        $this->assertSame("{$this->runTag} Available", $row['descr_short']);
        $this->assertSame('Ready to ship', $row['descr_long']);
        $this->assertSame(1, (int) $row['product_available']);
        $this->assertSame(1, (int) $row['restocking']);
        $this->assertSame('InStock', $row['schema_ref']);
        $this->assertSame(1, (int) $row['list']);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertTrue($_SESSION['status_saved']);
        $this->assertSame(["Created status: {$this->runTag} Available"], $this->changelogFor($id));
    }

    public function test_unticked_switches_are_saved_off(): void
    {
        $fields = $this->form();
        unset($fields['product_available'], $fields['enabled']);

        $id = $this->createdId($this->submit($fields));

        $row = $this->stored($id);
        $this->assertSame(0, (int) $row['product_available']);
        $this->assertSame(0, (int) $row['restocking']);
        $this->assertSame(0, (int) $row['list']);
        $this->assertSame(0, (int) $row['enabled']);
    }

    public function test_both_descriptions_are_required(): void
    {
        $before = $this->statusCount();

        foreach (['descr_short', 'descr_long'] as $field) {
            $result = $this->submit($this->form([$field => '']));

            $this->assertNull($result['redirect']);
            $this->assertSame('Short and long descriptions are required', $result['data']['admin']['error'], $field);
        }
        $this->assertSame($before, $this->statusCount());
    }

    public function test_a_description_longer_than_its_column_is_refused_with_a_message_not_a_database_error(): void
    {
        $before = $this->statusCount();

        foreach (['descr_short' => 21, 'schema_ref' => 21, 'descr_long' => 51] as $field => $length) {
            $result = $this->submit($this->form([$field => \str_repeat('x', $length)]));

            $this->assertNull($result['redirect'], $field);
            $this->assertStringContainsString('up to 20 characters', $result['data']['admin']['error'], $field);
        }
        $this->assertSame($before, $this->statusCount());

        $edge = $this->submit($this->form(['descr_short' => \str_repeat('x', 20), 'schema_ref' => \str_repeat('y', 20), 'descr_long' => \str_repeat('z', 50)]));
        $this->assertNotNull($edge['redirect'], 'the longest values that fit are accepted');
    }

    // ---- the form: editing --------------------------------------------------------

    public function test_editing_a_status_updates_it_and_logs_the_change(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $result = $this->submit($this->form(['id' => (string) $id, 'descr_short' => "{$this->runTag} Renamed", 'product_available' => '', 'restocking' => '1']));

        $this->assertSame("/admin/shop/statuses/edit/?id={$id}", $result['redirect']);
        $row = $this->stored($id);
        $this->assertSame("{$this->runTag} Renamed", $row['descr_short']);
        $this->assertSame(1, (int) $row['restocking']);
        $this->assertContains("Updated status: {$this->runTag} Renamed", $this->changelogFor($id));
    }

    public function test_a_refused_edit_leaves_the_stored_status_alone(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->submit($this->form(['id' => (string) $id, 'descr_short' => 'Changed', 'descr_long' => '']));

        $this->assertSame("{$this->runTag} Available", $this->stored($id)['descr_short']);
    }

    // ---- the form: showing it -----------------------------------------------------

    public function test_a_new_status_form_is_empty(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        $this->controller($data)->edit();

        $this->assertTrue($data['admin']['isNew']);
        $this->assertNull($data['admin']['status']);
        $this->assertFalse($data['admin']['showSavedMessage']);
    }

    public function test_an_existing_status_form_is_filled_in_and_the_saved_message_shows_once(): void
    {
        $id = $this->createdId($this->submit($this->form()));
        $this->stashedId = $id;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $data = $this->signInAs('admin');
        $this->controller($data)->edit();
        $this->assertFalse($data['admin']['isNew']);
        $this->assertSame("{$this->runTag} Available", $data['admin']['status']['descr_short']);
        $this->assertTrue($data['admin']['showSavedMessage']);

        $data = $this->signInAs('admin');
        $this->controller($data)->edit();
        $this->assertFalse($data['admin']['showSavedMessage']);
    }

    public function test_asking_for_a_status_that_does_not_exist_goes_back_to_the_list(): void
    {
        $this->stashedId = 999999999;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        try {
            $this->controller($data)->edit();
            $this->fail('expected a redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('/admin/shop/statuses/', $e->getMessage());
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

    public function test_a_request_without_an_action_or_a_status_is_refused(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->assertSame('Missing parameters', $this->act(['id' => $id])['error'], 'no action');
        $this->assertSame('Missing parameters', $this->act(['action' => 'delete'])['error'], 'no status');
        $this->assertSame('Unknown action', $this->act(['action' => 'explode', 'id' => $id])['error']);
    }

    public function test_an_unused_status_is_binned_and_restored(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->assertTrue($this->act(['action' => 'delete', 'id' => $id], role: 'editor')['ok']);
        $this->assertNotNull($this->stored($id)['deleted_at']);

        $this->assertTrue($this->act(['action' => 'restore', 'id' => $id], role: 'editor')['ok']);
        $this->assertNull($this->stored($id)['deleted_at']);
        $this->assertSame(
            ["Created status: {$this->runTag} Available", "Deleted status: {$this->runTag} Available", "Restored status: {$this->runTag} Available"],
            $this->changelogFor($id),
        );
    }

    public function test_a_status_a_live_product_uses_cannot_be_binned(): void
    {
        $id = $this->createdId($this->submit($this->form()));
        $this->useStatus($id);

        $response = $this->act(['action' => 'delete', 'id' => $id]);

        $this->assertSame('Cannot delete: 1 product(s) are using this status', $response['error']);
        $this->assertNull($this->stored($id)['deleted_at']);
    }

    public function test_a_status_only_a_binned_product_uses_can_be_binned_but_not_deleted_for_good(): void
    {
        $id = $this->createdId($this->submit($this->form()));
        $this->useStatus($id, thenBinProduct: true);

        $this->assertTrue($this->act(['action' => 'delete', 'id' => $id])['ok']);

        $forGood = $this->act(['action' => 'permanently_delete', 'id' => $id], role: 'admin');
        $this->assertSame('Cannot delete: 1 product(s) are using this status', $forGood['error']);
        $this->assertNotSame([], $this->stored($id));
    }

    public function test_only_an_admin_can_delete_a_status_for_good(): void
    {
        $id = $this->createdId($this->submit($this->form()));

        $this->assertSame('Permission denied', $this->act(['action' => 'permanently_delete', 'id' => $id], role: 'editor')['error']);
        $this->assertNotSame([], $this->stored($id));

        $this->assertTrue($this->act(['action' => 'permanently_delete', 'id' => $id], role: 'admin')['ok']);
        $this->assertSame([], $this->stored($id));
    }

    // ---- the list -----------------------------------------------------------------

    public function test_the_list_shows_live_and_binned_statuses_and_who_may_empty_the_bin(): void
    {
        $live = $this->createdId($this->submit($this->form()));
        $binned = $this->createdId($this->submit($this->form(['descr_short' => "{$this->runTag} Binned"])));
        $this->act(['action' => 'delete', 'id' => $binned]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        $this->controller($data)->index();

        $this->assertContains($live, \array_map('intval', \array_column($data['admin']['statuses'], 'id')));
        $this->assertNotContains($binned, \array_map('intval', \array_column($data['admin']['statuses'], 'id')));
        $this->assertContains($binned, \array_map('intval', \array_column($data['admin']['deletedStatuses'], 'id')));
        $this->assertTrue($data['admin']['isAdmin']);
    }
}
