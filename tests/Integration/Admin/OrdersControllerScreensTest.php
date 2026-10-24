<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Header;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\StoreFinance;
use Osmium\Core\Library\Url;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\OrdersController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Osmium\Core\Library\PaymentProviders;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/models/StoreFinance.php';
require_once 'app/services/osmium-shop/models/PaymentProviders.php';
require_once 'app/services/osmium-shop/models/Order.php';
require_once 'app/services/osmium-shop/controllers/OrdersController.php';

/**
 * Stands in for the redirect that ends a real request. An Error, not an Exception, so the controller's own
 * catch blocks do not swallow it.
 */
final class OrdersRedirectSignal extends \Error {}

/**
 * The order screens and the parts of the dispatch action the action test leaves out: the list and its status
 * filter, the order page (what may be refunded or printed, the dispatch-email banner, service panels), a
 * successful dispatch with its customer email, and services answering their own actions. The PDF documents end
 * the request with exit, so only their refusals are driven. Real controller and models, rolled back; the mailer
 * and the installed services are stood in.
 */
class OrdersControllerScreensTest extends DatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private string $runTag;
    private array $stashed = [];
    private array $savedProviders = [];

    /** What the stand-in mailer was asked, and what it answers */
    public array $emails = [];
    public array $emailAnswer = ['success' => true];

    /** Handlers the stand-in services offer: event => callable(payload) */
    public array $serviceHandlers = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->runTag = \strtoupper('ZQ' . \substr(\uniqid(), -6));
        $this->stashed = [];
        $this->emails = [];
        $this->emailAnswer = ['success' => true];
        $this->serviceHandlers = [];

        $memo = new \ReflectionProperty(PaymentProviders::class, 'externalMemo');
        $this->savedProviders = [$memo->getValue()];
        $memo->setValue(null, ['zqpay' => ['id' => 'zqpay', 'label' => 'Test Pay', 'ready' => true, 'frontend' => [], 'settingsRoute' => null]]);

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'order', \Osmium\Modules\Admin\Models\Order::class, 'shop_orders'); // The shop service's model

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
        (new \ReflectionProperty(PaymentProviders::class, 'externalMemo'))->setValue(null, $this->savedProviders[0]);
        $_SESSION = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function controller(array &$data): OrdersController
    {
        $osmium = new class ($this->db, $this->stashed) extends Osmium {
            public function __construct(OsmiumPDO $db, private array $stashed)
            {
                $this->dataSource = $db;
                StoreFinance::useFor($this, new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'));
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
                        throw new OrdersRedirectSignal($targetURL);
                    }
                };
            }

            public function getStashedParam(string $key, mixed $default = null): mixed
            {
                return $this->stashed[$key] ?? $default;
            }

            public function clearStashedQuerystring(): void {}
        };

        $test = $this;

        return new class ($osmium, $this->admin, $data, $test) extends OrdersController {
            public function __construct(Osmium $osmium, object $admin, array &$data, private object $test)
            {
                parent::__construct($osmium, $admin, $data);
            }

            protected function mailer(): ShopOrderMailer
            {
                return new class ($this->test) extends ShopOrderMailer {
                    public function __construct(private object $test) {}

                    public function sendOrderDispatchedEmail(string $toEmail, string $customerName, string $orderRef, ?string $carrier, ?string $trackingRef, bool $respectSettings = true): array
                    {
                        $this->test->emails[] = compact('toEmail', 'customerName', 'orderRef', 'carrier', 'trackingRef');

                        return $this->test->emailAnswer;
                    }
                };
            }

            protected function serviceHooks(): ServiceHooks
            {
                return new class ($this->test) extends ServiceHooks {
                    public function __construct(private object $test) {}

                    public function collect(string $event, array $payload = []): array
                    {
                        $handler = $this->test->serviceHandlers[$event] ?? null;
                        $result = $handler ? $handler($payload) : null;

                        return $result === null ? [] : [$result];
                    }

                    public function handle(string $event, array $payload = []): mixed
                    {
                        $handler = $this->test->serviceHandlers[$event] ?? null;

                        return $handler ? $handler($payload) : null;
                    }
                };
            }
        };
    }

    private function signIn(): array
    {
        $_SESSION['admin_user_id'] = 1;
        $_SESSION['admin_user_username'] = 'order-tester';

        return [
            'admin' => ['basePath' => '/admin/', 'user' => ['username' => 'order-tester']],
            'adminUrl' => fn (string $path): string => '/admin/' . $path,
        ];
    }

    /** @return array{redirect: ?string, data: array} */
    private function screen(string $method, array $stashed = []): array
    {
        $this->stashed = $stashed;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signIn();
        $redirect = null;

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        try {
            $this->controller($data)->{$method}();
        } catch (OrdersRedirectSignal $e) {
            $redirect = $e->getMessage();
        } finally {
            \restore_error_handler();
        }

        return ['redirect' => $redirect, 'data' => $data];
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function act(array $input): array
    {
        $this->auth->input = $input + ['csrf_token' => 'good-token'];
        $this->admin->response = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $data = $this->signIn();

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function createOrder(string $status, array $extra = []): int
    {
        static $n = 0;
        $ref = $this->runTag . \str_pad((string) ++$n, 3, '0', STR_PAD_LEFT);
        $this->db->insert(tableName: $this->db->tablePrefix() . 'shop_orders', data: $extra + [
            'order_ref' => $ref, 'status' => $status, 'customer_name' => 'Pat Customer',
            'customer_email' => \strtolower($ref) . '@example.test', 'is_test_payment' => 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function addItem(int $orderId, array $extra = []): void
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'shop_order_items', data: $extra + [
            'order_id' => $orderId, 'product_id' => 1, 'product_ref' => 'REF', 'product_title' => 'Widget',
            'quantity' => 2, 'unit_price_exc_tax' => 10.00, 'line_total_exc_tax' => 20.00, 'line_tax_amount' => 4.00,
        ]);
    }

    private function stats(): array
    {
        return $this->screen('index')['data']['admin']['orderStats'];
    }

    // ---- the list -----------------------------------------------------------------

    public function test_the_list_prepares_each_order_for_display(): void
    {
        $id = $this->createOrder('paid', [
            'total_inc_tax' => 1234.5, 'invoice_number' => 42, 'payment_provider' => 'zqpay', 'is_test_payment' => 1,
            'created_at' => '2099-05-06 07:08:09', 'paid_at' => null,
        ]);

        $orders = $this->screen('index')['data']['admin']['orders'];

        $byId = [];
        foreach ($orders as $order) {
            $byId[(int) $order['id']] = $order;
        }
        $order = $byId[$id];
        $this->assertSame('£1,234.50', $order['total_formatted']);
        $this->assertSame('06 May 2099, 07:08', $order['created_at_formatted']);
        $this->assertSame('—', $order['paid_at_formatted'], 'no date is a dash, not 1 Jan 1970');
        $this->assertSame('Paid', $order['status_label']);
        $this->assertSame('bg-success', $order['status_class']);
        $this->assertSame('INV-42', $order['invoice_display']);
        $this->assertSame('Test Pay', $order['provider_label'], 'the provider\'s own label');
        $this->assertTrue($order['is_test_payment']);
    }

    public function test_each_status_has_its_own_badge_and_an_order_with_no_invoice_or_provider_shows_a_dash(): void
    {
        $expected = ['paid' => 'bg-success', 'dispatched' => 'bg-primary', 'pending' => 'bg-warning text-dark', 'refunded' => 'bg-info text-dark', 'failed' => 'bg-danger', 'cancelled' => 'bg-danger'];
        $ids = [];
        foreach ($expected as $status => $class) {
            $ids[$status] = $this->createOrder($status);
        }

        $orders = $this->screen('index')['data']['admin']['orders'];

        $byId = [];
        foreach ($orders as $order) {
            $byId[(int) $order['id']] = $order;
        }
        foreach ($expected as $status => $class) {
            $this->assertSame($class, $byId[$ids[$status]]['status_class'], $status);
        }
        $this->assertSame('—', $byId[$ids['paid']]['invoice_display']);
        $this->assertSame('—', $byId[$ids['paid']]['provider_label']);
    }

    public function test_the_list_can_be_filtered_by_a_known_status_only(): void
    {
        $paid = $this->createOrder('paid');
        $pending = $this->createOrder('pending');

        $filtered = $this->screen('index', ['status' => 'pending'])['data']['admin'];
        $ids = \array_map('intval', \array_column($filtered['orders'], 'id'));
        $this->assertContains($pending, $ids);
        $this->assertNotContains($paid, $ids);
        $this->assertSame('pending', $filtered['statusFilter']);
        $this->assertSame(['pending', 'paid', 'failed', 'cancelled', 'dispatched', 'refunded'], $filtered['statuses']);

        $everything = $this->screen('index')['data']['admin'];
        $this->assertContains($paid, \array_map('intval', \array_column($everything['orders'], 'id')));
        $this->assertSame('', $everything['statusFilter']);
    }

    public function test_a_status_that_is_not_on_the_list_is_ignored_rather_than_put_in_the_query(): void
    {
        $id = $this->createOrder('paid');

        foreach (["paid' OR '1'='1", 'PAID', 'nonsense', "paid'; DROP TABLE x; --"] as $attempt) {
            $admin = $this->screen('index', ['status' => $attempt])['data']['admin'];

            $this->assertSame('', $admin['statusFilter'], $attempt);
            $this->assertContains($id, \array_map('intval', \array_column($admin['orders'], 'id')), 'shows everything, not an error or a half-filtered list');
        }
    }

    public function test_the_strip_counts_orders_by_status_and_adds_up_thirty_days_of_paid_ones(): void
    {
        $before = $this->stats();
        $this->createOrder('pending');
        $this->createOrder('paid', ['paid_at' => \date('Y-m-d H:i:s'), 'total_inc_tax' => 100.00]);
        $this->createOrder('dispatched', ['paid_at' => \date('Y-m-d H:i:s'), 'total_inc_tax' => 50.25]);
        $this->createOrder('dispatched', ['paid_at' => '2001-01-01 00:00:00', 'total_inc_tax' => 999.00]);
        $this->createOrder('refunded', ['paid_at' => \date('Y-m-d H:i:s'), 'total_inc_tax' => 500.00]);

        $after = $this->stats();

        $this->assertSame($before['total'] + 5, $after['total']);
        $this->assertSame($before['pending'] + 1, $after['pending']);
        $this->assertSame($before['awaiting_dispatch'] + 1, $after['awaiting_dispatch']);
        $this->assertSame($before['dispatched'] + 2, $after['dispatched']);
        $this->assertSame($before['refunded'] + 1, $after['refunded']);
        $this->assertSame($before['paid_30d'] + 2, $after['paid_30d'], 'an old order and a refunded one are not takings');
        $value = static fn (array $stats): float => (float) \str_replace(['£', ','], '', $stats['paid_30d_value']);
        $this->assertEqualsWithDelta(150.25, $value($after) - $value($before), 0.001);
    }

    public function test_a_paid_test_payment_is_neither_awaiting_dispatch_nor_takings(): void
    {
        $before = $this->stats();
        $this->createOrder('paid', ['paid_at' => \date('Y-m-d H:i:s'), 'total_inc_tax' => 100.00, 'is_test_payment' => 1]);
        $this->createOrder('paid', ['paid_at' => \date('Y-m-d H:i:s'), 'total_inc_tax' => 100.00]);

        $after = $this->stats();

        $this->assertSame($before['awaiting_dispatch'] + 1, $after['awaiting_dispatch'], 'only the real one is waiting to be sent');
        $this->assertSame($before['paid_30d'] + 1, $after['paid_30d'], 'sandbox payments are not takings');
        $value = static fn (array $stats): float => (float) \str_replace(['£', ','], '', $stats['paid_30d_value']);
        $this->assertEqualsWithDelta(100.00, $value($after) - $value($before), 0.001);
    }

    // ---- the order page -----------------------------------------------------------

    public function test_the_order_page_goes_back_to_the_list_without_a_real_order(): void
    {
        $this->assertSame('/admin/shop/orders/', $this->screen('view')['redirect'], 'no id');
        $this->assertSame('/admin/shop/orders/', $this->screen('view', ['id' => 'abc'])['redirect'], 'not a number');
        $this->assertSame('/admin/shop/orders/', $this->screen('view', ['id' => '4000000'])['redirect'], 'no such order');
    }

    public function test_a_paid_order_can_be_refunded_and_printed_and_its_items_are_priced(): void
    {
        $id = $this->createOrder('paid', ['payment_provider' => 'zqpay', 'admin_notes' => 'Rang up', 'total_inc_tax' => 24.00]);
        $this->addItem($id);

        $result = $this->screen('view', ['id' => (string) $id]);

        $this->assertNull($result['redirect']);
        $admin = $result['data']['admin'];
        $this->assertSame('app/core/admin/views/orders/view.phtml', $result['data']['adminContent']);
        $this->assertSame(['isPaid' => true, 'isDispatched' => false, 'canRefund' => true, 'canPrintDespatchNote' => true, 'refundVenue' => 'Test Pay', 'hasNotes' => true], $admin['orderView']);
        $this->assertSame(['orderId' => $id, 'endpoint' => '/admin/shop/orders/action/'], $admin['orderConfig']);
        $this->assertCount(1, $admin['orderItems']);
        $this->assertSame('£10.00', $admin['orderItems'][0]['unit_price_formatted']);
        $this->assertSame('£20.00', $admin['orderItems'][0]['line_total_formatted']);
        $this->assertSame('£4.00', $admin['orderItems'][0]['line_tax_formatted']);
        $this->assertSame('£24.00', $admin['order']['total_formatted']);
    }

    public function test_which_orders_can_be_refunded_or_printed_follows_the_status(): void
    {
        $expected = ['paid' => true, 'dispatched' => true, 'pending' => false, 'refunded' => false, 'failed' => false, 'cancelled' => false];

        foreach ($expected as $status => $allowed) {
            $id = $this->createOrder($status);

            $view = $this->screen('view', ['id' => (string) $id])['data']['admin']['orderView'];

            $this->assertSame($allowed, $view['canRefund'], "refund {$status}");
            $this->assertSame($allowed, $view['canPrintDespatchNote'], "despatch note {$status}");
            $this->assertSame($status === 'dispatched', $view['isDispatched'], $status);
        }
    }

    public function test_an_order_with_no_provider_or_notes_still_reads_as_a_sentence(): void
    {
        $id = $this->createOrder('paid');

        $view = $this->screen('view', ['id' => (string) $id])['data']['admin']['orderView'];

        $this->assertSame('the payment provider', $view['refundVenue']);
        $this->assertFalse($view['hasNotes']);
    }

    public function test_the_dispatch_email_banner_says_sent_failed_or_nothing(): void
    {
        $id = (string) $this->createOrder('dispatched');

        $this->assertNull($this->screen('view', ['id' => $id])['data']['admin']['dispatchEmailSent'], 'no email was tried');
        $sent = $this->screen('view', ['id' => $id, 'email_sent' => '1'])['data']['admin'];
        $this->assertTrue($sent['dispatchEmailSent']);
        $failed = $this->screen('view', ['id' => $id, 'email_sent' => '0', 'email_error' => 'Mailbox full'])['data']['admin'];
        $this->assertFalse($failed['dispatchEmailSent']);
        $this->assertSame('Mailbox full', $failed['dispatchEmailError']);
    }

    public function test_installed_services_can_add_panels_to_the_order_page(): void
    {
        $id = $this->createOrder('paid');
        $seen = null;
        $this->serviceHandlers['order.panels'] = function (array $payload) use (&$seen): array {
            $seen = $payload['order']['id'];

            return ['title' => 'Courier', 'html' => '<p>Booked</p>'];
        };

        $panels = $this->screen('view', ['id' => (string) $id])['data']['admin']['servicePanels'];

        $this->assertSame([['title' => 'Courier', 'html' => '<p>Booked</p>']], $panels);
        $this->assertSame($id, (int) $seen);
    }

    // ---- the documents ------------------------------------------------------------

    public function test_the_despatch_note_and_invoice_go_back_to_the_list_without_a_real_order(): void
    {
        foreach (['despatchNote', 'invoice'] as $document) {
            $this->assertSame('/admin/shop/orders/', $this->screen($document)['redirect'], "{$document}: no id");
            $this->assertSame('/admin/shop/orders/', $this->screen($document, ['id' => '4000000'])['redirect'], "{$document}: no such order");
        }
    }

    // ---- dispatching --------------------------------------------------------------

    public function test_dispatching_marks_the_order_logs_it_and_emails_the_customer_with_the_tracking(): void
    {
        $id = $this->createOrder('paid', ['customer_name' => 'Pat Customer']);

        $response = $this->act(['action' => 'mark_dispatched', 'id' => $id, 'tracking_carrier' => '  DPD ', 'tracking_ref' => ' 15501234 ']);

        $this->assertTrue($response['ok'], $response['error'] ?? '');
        $this->assertSame(['success' => true, 'action' => 'mark_dispatched', 'id' => $id, 'email_sent' => true, 'email_error' => null], $response['body']);
        $order = $this->model->order->getById($id);
        $this->assertSame('dispatched', $order['status']);
        $this->assertSame('DPD', $order['tracking_carrier']);
        $this->assertSame('15501234', $order['tracking_ref']);
        $this->assertCount(1, $this->emails);
        $this->assertSame([$order['customer_email'], 'Pat Customer', $order['order_ref'], 'DPD', '15501234'], \array_values($this->emails[0]));
        $logged = $this->db->prepare(
            sql: "SELECT description FROM " . $this->db->tablePrefix() . "changelog WHERE record_type = 'order' AND record_id = :id",
            bindings: [':id' => $id],
        )->resultset();
        $this->assertSame(["Marked order {$id} dispatched"], \array_column($logged, 'description'));
    }

    public function test_a_dispatch_with_no_carrier_or_reference_stores_and_sends_nothing_for_them(): void
    {
        $id = $this->createOrder('paid');

        $this->act(['action' => 'mark_dispatched', 'id' => $id, 'tracking_carrier' => '  ', 'tracking_ref' => '']);

        $order = $this->model->order->getById($id);
        $this->assertNull($order['tracking_carrier']);
        $this->assertNull($order['tracking_ref']);
        $this->assertNull($this->emails[0]['carrier']);
        $this->assertNull($this->emails[0]['trackingRef']);
    }

    public function test_a_failed_email_is_reported_but_the_order_stays_dispatched(): void
    {
        $id = $this->createOrder('paid');
        $this->emailAnswer = ['success' => false, 'error' => 'SMTP refused'];

        $response = $this->act(['action' => 'mark_dispatched', 'id' => $id]);

        $this->assertTrue($response['ok']);
        $this->assertFalse($response['body']['email_sent']);
        $this->assertSame('SMTP refused', $response['body']['email_error']);
        $this->assertSame('dispatched', $this->model->order->getById($id)['status']);
    }

    public function test_dispatch_emails_switched_off_in_shop_settings_say_nothing_was_sent(): void
    {
        $id = $this->createOrder('paid');
        $this->emailAnswer = ['success' => true, 'skipped' => true];

        $response = $this->act(['action' => 'mark_dispatched', 'id' => $id]);

        $this->assertNull($response['body']['email_sent'], 'neither sent nor failed');
        $this->assertSame('dispatched', $this->model->order->getById($id)['status']);
    }

    public function test_dispatching_an_order_that_does_not_exist_sends_no_email(): void
    {
        $response = $this->act(['action' => 'mark_dispatched', 'id' => 4000000]);

        $this->assertSame('Only a paid order can be marked dispatched.', $response['error']);
        $this->assertSame([], $this->emails);
    }

    public function test_a_refused_dispatch_sends_no_email_and_logs_nothing(): void
    {
        $id = $this->createOrder('pending');

        $this->act(['action' => 'mark_dispatched', 'id' => $id]);

        $this->assertSame([], $this->emails);
        $logged = $this->db->prepare(
            sql: "SELECT id FROM " . $this->db->tablePrefix() . "changelog WHERE record_type = 'order' AND record_id = :id",
            bindings: [':id' => $id],
        )->resultset();
        $this->assertSame([], $logged);
    }

    // ---- actions that belong to services ------------------------------------------

    public function test_an_action_the_core_does_not_know_is_offered_to_the_installed_services(): void
    {
        $id = $this->createOrder('paid');
        $payload = null;
        $this->serviceHandlers['order.action'] = function (array $given) use (&$payload): ?array {
            $payload = $given;

            return $given['action'] === 'book_courier' ? ['success' => true, 'booked' => $given['orderId']] : null;
        };

        $claimed = $this->act(['action' => 'book_courier', 'id' => $id, 'service' => 'dpd']);

        $this->assertTrue($claimed['ok']);
        $this->assertSame(['success' => true, 'booked' => $id], $claimed['body']);
        $this->assertSame(['book_courier', $id, 'dpd'], [$payload['action'], $payload['orderId'], $payload['input']['service']]);

        $unclaimed = $this->act(['action' => 'something_else', 'id' => $id]);
        $this->assertSame('Unknown action', $unclaimed['error']);
    }

    public function test_a_service_that_fails_shows_its_own_error(): void
    {
        $this->serviceHandlers['order.action'] = function (): array {
            throw new \RuntimeException('Courier API is down');
        };

        $this->assertSame('Courier API is down', $this->act(['action' => 'book_courier', 'id' => 1])['error']);
    }

    public function test_a_test_payment_has_no_refund_button_and_cannot_be_recorded_as_refunded(): void
    {
        $id = $this->createOrder('paid', ['is_test_payment' => 1]);

        $view = $this->screen('view', ['id' => (string) $id])['data']['admin']['orderView'];
        $response = $this->act(['action' => 'record_refund', 'id' => $id]);

        $this->assertFalse($view['canRefund']);
        $this->assertTrue($view['canPrintDespatchNote'], 'a test order can still be printed, only the refund goes');
        $this->assertSame('paid', $this->model->order->getById($id)['status'], 'not refunded');
        $this->assertStringContainsString('Test payments moved no money', (string) ($response['error'] ?? ''));
    }

    public function test_the_core_actions_are_never_handed_to_a_service(): void
    {
        $id = $this->createOrder('paid');
        $this->serviceHandlers['order.action'] = fn (): array => ['success' => true, 'hijacked' => true];

        $response = $this->act(['action' => 'record_refund', 'id' => $id]);

        $this->assertArrayNotHasKey('hijacked', $response['body']);
        $this->assertSame('refunded', $this->model->order->getById($id)['status']);
    }
}
