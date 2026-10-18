<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\OrdersController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/models/Order.php';
require_once 'app/services/osmium-shop/controllers/OrdersController.php';

/**
 * The order screen's write actions: marking dispatched, recording a refund and
 * adding a note. The money rules are in SQL (only a paid order can be
 * dispatched, only a paid or dispatched one refunded), so they are checked
 * against the real table; the controller adds the test-payment refusal, the
 * token check and the audit trail. A successful dispatch also emails the
 * customer, so that step is covered at the model level only. Rolled back.
 */
class OrdersControllerActionTest extends DatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'order', \Osmium\Modules\Admin\Models\Order::class, 'shop_orders'); // The shop service's model

        $this->auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new \Osmium\Core\Library\Password(),
        ) extends AdminAuth {
            public array $input = [];

            public function getJsonInput(): array
            {
                return $this->input;
            }
        };

        $this->admin = new class ($this->model, $this->auth) extends OsmiumAdmin {
            public ?array $response = null;

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
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
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function createOrder(string $status, bool $testPayment = false): int
    {
        $ref = 'ZZ' . \strtoupper(\substr(\uniqid(), -8));
        $this->db->insert(tableName: $this->db->tablePrefix() . 'shop_orders', data: [
            'order_ref' => $ref, 'status' => $status, 'customer_name' => 'Test Customer',
            'customer_email' => "{$ref}@example.test", 'is_test_payment' => $testPayment ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function orderStatus(int $id): string
    {
        return $this->model->order->getById($id)['status'];
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function call(array $input): array
    {
        $this->auth->input = $input + ['csrf_token' => 'good-token'];
        $data = ['admin' => ['user' => ['username' => 'tester']]];
        $osmium = new class ($this->db) extends Osmium {
            public function __construct(\Osmium\Core\Library\OsmiumPDO $db)
            {
                $this->dataSource = $db;
            }
        };

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent')); // The controller sets a JSON header; the CLI has already printed progress dots
        try {
            (new OrdersController($osmium, $this->admin, $data))->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    // ---- the model's money rules ---------------------------------------------------

    public function test_only_a_paid_order_can_be_marked_dispatched(): void
    {
        foreach (['pending', 'failed', 'cancelled', 'dispatched', 'refunded'] as $status) {
            $id = $this->createOrder($status);
            $this->assertFalse($this->model->order->markDispatched($id, 'DPD', 'TRACK1'), $status);
            $this->assertSame($status, $this->orderStatus($id));
        }

        $paid = $this->createOrder('paid');
        $this->assertTrue($this->model->order->markDispatched($paid, 'DPD', 'TRACK1'));

        $order = $this->model->order->getById($paid);
        $this->assertSame('dispatched', $order['status']);
        $this->assertSame('DPD', $order['tracking_carrier']);
        $this->assertSame('TRACK1', $order['tracking_ref']);
        $this->assertNotNull($order['dispatched_at']);
    }

    public function test_only_a_paid_or_dispatched_order_can_be_refunded(): void
    {
        foreach (['pending', 'failed', 'cancelled', 'refunded'] as $status) {
            $id = $this->createOrder($status);
            $this->assertFalse($this->model->order->recordRefund($id), $status);
            $this->assertSame($status, $this->orderStatus($id));
        }

        foreach (['paid', 'dispatched'] as $status) {
            $id = $this->createOrder($status);
            $this->assertTrue($this->model->order->recordRefund($id), $status);
            $this->assertSame('refunded', $this->orderStatus($id));
        }
    }

    // ---- the controller ------------------------------------------------------------

    public function test_a_test_mode_payment_is_never_shipped(): void
    {
        $id = $this->createOrder('paid', testPayment: true);

        $response = $this->call(['action' => 'mark_dispatched', 'id' => $id]);

        $this->assertFalse($response['ok']);
        $this->assertStringContainsString('test-mode payment', $response['error']);
        $this->assertSame('paid', $this->orderStatus($id));
    }

    public function test_dispatching_an_unpaid_order_is_refused_with_a_clear_message(): void
    {
        $id = $this->createOrder('pending');

        $response = $this->call(['action' => 'mark_dispatched', 'id' => $id]);

        $this->assertSame('Only a paid order can be marked dispatched.', $response['error']);
        $this->assertSame('pending', $this->orderStatus($id));
    }

    public function test_recording_a_refund_changes_the_status_and_leaves_an_audit_entry(): void
    {
        $id = $this->createOrder('paid');

        $response = $this->call(['action' => 'record_refund', 'id' => $id]);

        $this->assertTrue($response['ok']);
        $this->assertSame('refunded', $this->orderStatus($id));
        $this->db->query('SELECT description FROM ' . $this->db->tablePrefix() . "changelog WHERE record_type = 'order' AND record_id = {$id}");
        $this->assertSame('Recorded refund against order ' . $id, $this->db->single()['description']);
    }

    public function test_a_second_refund_or_a_refund_on_an_unpaid_order_is_refused(): void
    {
        $paid = $this->createOrder('paid');
        $this->call(['action' => 'record_refund', 'id' => $paid]);

        $again = $this->call(['action' => 'record_refund', 'id' => $paid]);
        $this->assertSame('Only a paid or dispatched order can be refunded.', $again['error']);

        $pending = $this->createOrder('pending');
        $this->assertSame('Only a paid or dispatched order can be refunded.', $this->call(['action' => 'record_refund', 'id' => $pending])['error']);
        $this->assertSame('pending', $this->orderStatus($pending));
    }

    public function test_notes_are_stamped_and_added_to_the_earlier_ones(): void
    {
        $id = $this->createOrder('paid');

        $first = $this->call(['action' => 'add_note', 'id' => $id, 'note' => '  Customer rang about delivery  ']);
        $second = $this->call(['action' => 'add_note', 'id' => $id, 'note' => 'Told them Friday']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} \(tester\): Customer rang about delivery$/', $first['body']['note']);
        $notes = \explode("\n", $this->model->order->getById($id)['admin_notes']);
        $this->assertCount(2, $notes);
        $this->assertStringEndsWith('Customer rang about delivery', $notes[0]);
        $this->assertStringEndsWith('Told them Friday', $notes[1]);
        $this->assertTrue($second['ok']);
    }

    public function test_an_empty_note_or_a_missing_order_id_is_refused(): void
    {
        $id = $this->createOrder('paid');

        $this->assertSame('Please enter a note.', $this->call(['action' => 'add_note', 'id' => $id, 'note' => '   '])['error']);
        $this->assertSame('Missing order ID', $this->call(['action' => 'add_note', 'note' => 'x'])['error']);
        $this->assertSame('Missing order ID', $this->call(['action' => 'record_refund'])['error']);
        $this->assertSame('Missing order ID', $this->call(['action' => 'mark_dispatched'])['error']);
        $this->assertNull($this->model->order->getById($id)['admin_notes']);
    }

    public function test_a_wrong_token_changes_nothing(): void
    {
        $id = $this->createOrder('paid');

        $response = $this->call(['action' => 'record_refund', 'id' => $id, 'csrf_token' => 'forged']);

        $this->assertStringContainsString('Invalid request token', $response['error']);
        $this->assertSame('paid', $this->orderStatus($id));
    }

    public function test_only_a_post_is_accepted_and_unknown_actions_are_refused(): void
    {
        $id = $this->createOrder('paid');
        $this->assertSame('Unknown action', $this->call(['action' => 'delete_everything', 'id' => $id])['error']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame('Method not allowed', $this->call(['action' => 'record_refund', 'id' => $id])['error']);
        $this->assertSame('paid', $this->orderStatus($id));
    }
}
