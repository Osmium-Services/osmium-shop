<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Osmium;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Controllers\BasketsController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/services/osmium-shop/models/Basket.php';
require_once 'app/services/osmium-shop/controllers/BasketsController.php';

/**
 * The Baskets screen: active, abandoned and converted baskets split by the configured "abandoned after" hours,
 * the figures at the top, and the item / delete / clear-old actions. Real controller and models, rolled back. The dev
 * database holds real baskets, so lists are filtered to this test's own session ids and the totals are compared
 * before and after.
 */
class BasketsControllerTest extends DatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private string $tag;
    private int $abandonedHours = 2;
    private array $savedServer;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->tag = 'zq' . \substr(\md5(\uniqid()), 0, 8);

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $this->model = new OsmiumAdminModel($this->db, (object) \array_intersect_key((array) $all, ['changelog' => 1]));
        $this->model->addModel($this->db, 'basket', \Osmium\Modules\Admin\Models\Basket::class, 'checkout_baskets'); // The shop service's model

        $auth = new class ($this->model, new RecordingAdminMailer(), new Password()) extends AdminAuth {
            public array $input = [];

            public function getJsonInput(): array
            {
                return $this->input;
            }
        };

        $this->admin = new class ($this->model, $auth) extends OsmiumAdmin {
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

    private function table(string $name): string
    {
        return $this->db->tablePrefix() . $name;
    }

    /** Makes a basket and its items; $ago is a SQL interval such as "30 MINUTE". Items are [quantity, unit price]. */
    private function basket(string $name, string $status, string $ago, array $items = []): int
    {
        $this->db->prepare(
            sql: "INSERT INTO {$this->table('checkout_baskets')} (session_id, status, created_at, updated_at) VALUES (:s, :st, DATE_SUB(NOW(), INTERVAL {$ago}), DATE_SUB(NOW(), INTERVAL {$ago}))",
            bindings: [':s' => "{$this->tag}-{$name}-session-id", ':st' => $status],
        )->execute();
        $id = (int) $this->db->lastInsertId();

        foreach ($items as $n => [$quantity, $price]) {
            $this->db->prepare(
                sql: "INSERT INTO {$this->table('checkout_basket_items')} (basket_id, product_id, product_ref, product_title, product_options, quantity, unit_price_exc_tax, weight) VALUES (:b, 1, :ref, :title, :opt, :q, :p, 1.5)",
                bindings: [':b' => $id, ':ref' => "REF{$n}", ':title' => "Item {$n}", ':opt' => $n === 0 ? 'Red' : null, ':q' => $quantity, ':p' => $price],
            )->execute();
        }

        return $id;
    }

    private function controller(array &$data): BasketsController
    {
        $hours = $this->abandonedHours;
        $osmium = new class ($this->db, $hours) extends Osmium {
            public function __construct(OsmiumPDO $db, int $hours)
            {
                $this->dataSource = $db;
                $this->config = new OsmiumConfig((object) ['site' => (object) ['scheme' => 'http', 'subdomain' => '', 'domain' => 'x.test', 'hostName' => 'x.test'], 'shop' => (object) ['basketAbandonedHours' => $hours]]);
            }
        };

        return new BasketsController($osmium, $this->admin, $data);
    }

    private function index(): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = ['admin' => ['basePath' => '/admin/']];
        $this->controller($data)->index();

        return $data;
    }

    /** @return array<string, array> this test's baskets in a list, by name */
    private function mine(array $list): array
    {
        $rows = [];
        foreach ($list as $row) {
            if (\str_starts_with($row['session_id'], $this->tag)) $rows[\explode('-', $row['session_id'])[1]] = $row;
        }

        return $rows;
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function call(array $input, bool $validToken = true): array
    {
        $this->admin->auth->input = $input + ($validToken ? ['csrf_token' => 'good-token'] : []);
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

    public function test_baskets_are_split_into_active_abandoned_and_converted_by_age_and_status(): void
    {
        $this->basket('fresh', 'active', '30 MINUTE', [[1, 10]]);
        $this->basket('stale', 'active', '3 HOUR', [[1, 10]]);
        $this->basket('done', 'converted', '2 DAY', [[1, 10]]);
        $this->basket('oldsale', 'converted', '10 DAY', [[1, 10]]);

        $admin = $this->index()['admin'];

        $this->assertSame(['fresh'], \array_keys($this->mine($admin['activeBaskets'])));
        $this->assertSame(['stale'], \array_keys($this->mine($admin['abandonedBaskets'])));
        $this->assertSame(['done'], \array_keys($this->mine($admin['convertedBaskets'])), 'converted baskets are listed for 7 days');
    }

    public function test_the_abandoned_threshold_comes_from_the_shop_settings(): void
    {
        $this->basket('threehours', 'active', '3 HOUR');
        $this->abandonedHours = 5;

        $admin = $this->index()['admin'];

        $this->assertSame(['threehours'], \array_keys($this->mine($admin['activeBaskets'])));
        $this->assertSame([], $this->mine($admin['abandonedBaskets']));
        $this->assertSame(5, $admin['stats']['abandoned_hours']);
    }

    public function test_each_basket_is_prepared_for_display(): void
    {
        $this->basket('shown', 'active', '30 MINUTE', [[2, 10.5], [1, 3]]);

        $row = $this->mine($this->index()['admin']['activeBaskets'])['shown'];

        $this->assertSame(2, (int) $row['item_count']);
        $this->assertSame(3, (int) $row['total_items']);
        $this->assertSame('£24.00', $row['total_value_formatted']);
        $this->assertSame(\substr("{$this->tag}-shown-session-id", 0, 12), $row['session_id_short'], 'the session id is cut to 12 characters');
        $this->assertSame(12, \strlen($row['session_id_short']));
        $this->assertMatchesRegularExpression('/^\d{2} [A-Z][a-z]{2} \d{2}:\d{2}$/', $row['updated_at_formatted']);
        $this->assertMatchesRegularExpression('/^\d{2} [A-Z][a-z]{2} \d{2}:\d{2}$/', $row['created_at_formatted']);
    }

    public function test_abandoned_and_converted_baskets_are_prepared_for_display_too(): void
    {
        $this->basket('stale', 'active', '3 HOUR', [[3, 2.5]]);
        $this->basket('sold', 'converted', '1 DAY', [[1, 99.99]]);

        $admin = $this->index()['admin'];

        foreach ([[$admin['abandonedBaskets'], 'stale', '£7.50'], [$admin['convertedBaskets'], 'sold', '£99.99']] as [$list, $name, $total]) {
            $row = $this->mine($list)[$name];
            $this->assertSame($total, $row['total_value_formatted'], $name);
            $this->assertSame(12, \strlen($row['session_id_short']), $name);
            $this->assertNotSame('-', $row['updated_at_formatted'], $name);
        }
    }

    public function test_an_empty_basket_shows_zero_not_nothing(): void
    {
        $this->basket('empty', 'active', '30 MINUTE');

        $row = $this->mine($this->index()['admin']['activeBaskets'])['empty'];

        $this->assertSame(0, (int) $row['item_count']);
        $this->assertSame('£0.00', $row['total_value_formatted']);
    }

    public function test_the_figures_at_the_top_count_active_abandoned_and_recently_converted_baskets(): void
    {
        $before = $this->index()['admin']['stats'];

        $this->basket('a1', 'active', '30 MINUTE', [[2, 10]]);
        $this->basket('a2', 'active', '90 MINUTE', [[1, 5.5]]);
        $this->basket('ab', 'active', '5 HOUR', [[4, 100]]);
        $this->basket('c1', 'converted', '2 HOUR', [[1, 1]]);
        $this->basket('c2', 'converted', '2 DAY', [[1, 1]]);

        $after = $this->index()['admin']['stats'];

        $this->assertSame($before['active_count'] + 3, $after['active_count'], 'active includes abandoned');
        $this->assertSame($before['abandoned_now'] + 1, $after['abandoned_now']);
        $this->assertSame($before['converted_24h'] + 1, $after['converted_24h'], 'only the sale inside 24 hours');
        $this->assertSame(2, $after['abandoned_hours']);

        $beforeValue = (float) \str_replace(['£', ','], '', $before['active_value_formatted']);
        $afterValue = (float) \str_replace(['£', ','], '', $after['active_value_formatted']);
        $this->assertEqualsWithDelta($beforeValue + 25.5 + 400, $afterValue, 0.01, 'all active baskets, abandoned too');
        $this->assertMatchesRegularExpression('/^£[\d,]+\.\d{2}$/', $after['active_value_formatted']);
    }

    public function test_the_page_view_and_list_definitions_are_set(): void
    {
        $data = $this->index();

        $this->assertSame('app/core/admin/views/baskets/index.phtml', $data['adminContent']);
        $this->assertNotEmpty($data['admin']['lists']);
    }

    public function test_the_default_threshold_is_two_hours_when_the_shop_config_has_none(): void
    {
        $osmium = new class ($this->db) extends Osmium {
            public function __construct(OsmiumPDO $db)
            {
                $this->dataSource = $db;
                $this->config = new OsmiumConfig((object) ['site' => (object) ['scheme' => 'http', 'subdomain' => '', 'domain' => 'x.test', 'hostName' => 'x.test'], 'shop' => (object) []]);
            }
        };
        $data = ['admin' => ['basePath' => '/admin/']];

        (new BasketsController($osmium, $this->admin, $data))->index();

        $this->assertSame(2, $data['admin']['stats']['abandoned_hours']);
    }

    public function test_a_basket_shows_its_items_with_prices_and_line_totals(): void
    {
        $id = $this->basket('items', 'active', '30 MINUTE', [[2, 10.5], [3, 1.25]]);

        $result = $this->call(['action' => 'get_items', 'id' => (string) $id], validToken: false);

        $this->assertTrue($result['ok']);
        $items = $result['body']['items'];
        $this->assertCount(2, $items);
        $this->assertSame(['REF0', 'Item 0', 'Red', 2], [$items[0]['product_ref'], $items[0]['product_title'], $items[0]['product_options'], (int) $items[0]['quantity']]);
        $this->assertSame(['£10.50', '£21.00'], [$items[0]['unit_price_formatted'], $items[0]['line_total_formatted']]);
        $this->assertSame(['£1.25', '£3.75'], [$items[1]['unit_price_formatted'], $items[1]['line_total_formatted']]);
        $this->assertNull($items[1]['product_options']);
    }

    public function test_looking_at_items_needs_a_basket_id_but_no_token(): void
    {
        $this->assertSame('Missing basket ID', $this->call(['action' => 'get_items'], validToken: false)['error']);
    }

    public function test_deleting_a_basket_removes_it_and_its_items(): void
    {
        $id = $this->basket('gone', 'active', '30 MINUTE', [[1, 1]]);
        $keep = $this->basket('kept', 'active', '30 MINUTE', [[1, 1]]);

        $result = $this->call(['action' => 'delete', 'id' => $id]);

        $this->assertSame(['success' => true, 'action' => 'delete', 'id' => $id], $result['body']);
        $this->assertSame(0, $this->rowsWhere('checkout_baskets', 'id', $id));
        $this->assertSame(0, $this->rowsWhere('checkout_basket_items', 'basket_id', $id));
        $this->assertSame(1, $this->rowsWhere('checkout_baskets', 'id', $keep));
    }

    private function rowsWhere(string $table, string $column, int $value): int
    {
        return (int) $this->db->prepare(sql: "SELECT COUNT(*) AS c FROM {$this->table($table)} WHERE {$column} = :v", bindings: [':v' => $value])->single()['c'];
    }

    public function test_deleting_needs_a_basket_id(): void
    {
        $this->assertSame('Missing basket ID', $this->call(['action' => 'delete'])['error']);
    }

    public function test_clearing_old_baskets_removes_only_active_ones_untouched_for_that_many_days(): void
    {
        $oldActive = $this->basket('oldactive', 'active', '40 DAY', [[1, 1]]);
        $newActive = $this->basket('newactive', 'active', '10 DAY');
        $oldSale = $this->basket('oldsale', 'converted', '40 DAY');

        $result = $this->call(['action' => 'delete_old', 'days' => '30']);

        $this->assertTrue($result['body']['success']);
        $this->assertGreaterThanOrEqual(1, $result['body']['deleted']);
        $this->assertSame(0, $this->rowsWhere('checkout_baskets', 'id', $oldActive));
        $this->assertSame(1, $this->rowsWhere('checkout_baskets', 'id', $newActive));
        $this->assertSame(1, $this->rowsWhere('checkout_baskets', 'id', $oldSale), 'a completed sale is never cleared');
    }

    public function test_clearing_old_baskets_defaults_to_thirty_days(): void
    {
        $forty = $this->basket('forty', 'active', '40 DAY');
        $twenty = $this->basket('twenty', 'active', '20 DAY');

        $this->call(['action' => 'delete_old']);

        $this->assertSame(0, $this->rowsWhere('checkout_baskets', 'id', $forty));
        $this->assertSame(1, $this->rowsWhere('checkout_baskets', 'id', $twenty));
    }

    public function test_changes_need_a_valid_token_and_reads_do_not(): void
    {
        $id = $this->basket('safe', 'active', '40 DAY');

        foreach (['delete', 'delete_old', 'anything'] as $action) {
            $this->assertSame('Invalid request token. Please refresh the page and try again.', $this->call(['action' => $action, 'id' => $id], validToken: false)['error'], $action);
            $this->assertSame('Invalid request token. Please refresh the page and try again.', $this->call(['action' => $action, 'id' => $id, 'csrf_token' => 'forged'], validToken: false)['error'], $action);
        }
        $this->assertSame(1, $this->rowsWhere('checkout_baskets', 'id', $id));
    }

    public function test_only_post_and_known_actions_are_accepted(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame('Method not allowed', $this->call(['action' => 'get_items', 'id' => 1])['error']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame('Unknown action', $this->call(['action' => 'explode'])['error']);
        $this->assertSame('Unknown action', $this->call([])['error']);
    }
}
