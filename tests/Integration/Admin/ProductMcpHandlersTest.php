<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Services\ProductMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/ProductMcp.php';

/** ProductMcp with the service notification recorded instead of sent. */
class RecordingProductMcp extends ProductMcp
{
    /** @var int[] */
    public static array $notified = [];
    public static bool $succeeds = true;

    protected static function notifyServices(array $call, int $productId): bool
    {
        self::$notified[] = $productId;

        return self::$succeeds;
    }
}

/**
 * The product tools run for real against the tables: the checks that stop a slipped id or name changing the
 * wrong product, the restock date rules, the category add, the changelog entry under the client's name, and that
 * every refusal leaves the database as it was.
 */
class ProductMcpHandlersTest extends DatabaseTestCase
{
    private static bool $autoloadRegistered = false;

    private string $runTag;
    private int $brandId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$autoloadRegistered) {
            (new Autoload('app/config'))->register(); // The services find the models by their short names
            self::$autoloadRegistered = true;
        }
        $this->runTag = 'zq' . \substr(\uniqid(), -6);
        RecordingProductMcp::$notified = [];
        RecordingProductMcp::$succeeds = true;

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "{$this->runTag}Brand"]);
        $this->brandId = (int) $this->db->lastInsertId();
    }

    private function call(array $arguments): array
    {
        return ['dataSource' => $this->db, 'arguments' => $arguments, 'actor' => 'Tester via MCP', 'osmium' => null];
    }

    private function product(bool $restocking = true, array $overrides = []): int
    {
        $prefix = $this->db->tablePrefix();
        $statusId = $this->statusId(true);
        $this->db->prepare(sql: "UPDATE {$prefix}productstatus SET restocking = :r WHERE id = :id", bindings: [':r' => $restocking ? 1 : 0, ':id' => $statusId])->execute(); // Rolled back with the test

        return $this->createTestProduct($overrides + ['brand_id' => $this->brandId, 'status_id' => $statusId, 'model_name' => "{$this->runTag} Widget"]);
    }

    private function fullName(): string
    {
        return "{$this->runTag}Brand {$this->runTag} Widget";
    }

    private function row(int $id): array
    {
        return $this->db->prepare(sql: "SELECT * FROM {$this->db->tablePrefix()}products WHERE id = :id", bindings: [':id' => $id])->single();
    }

    private function category(string $label, ?int $parentId = null, bool $deleted = false): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productcategories', data: ['label' => $label, 'parent_id' => $parentId, 'deleted_at' => $deleted ? '2026-01-01 00:00:00' : null]);

        return (int) $this->db->lastInsertId();
    }

    private function assigned(int $productId): int
    {
        return (int) $this->db->prepare(sql: "SELECT COUNT(*) AS n FROM {$this->db->tablePrefix()}products_productcategories WHERE product_id = :id", bindings: [':id' => $productId])->single()['n'];
    }

    private function changes(int $productId): array
    {
        return $this->db->prepare(
            sql: "SELECT description, user_name FROM {$this->db->tablePrefix()}changelog WHERE record_type = 'product' AND record_id = :id",
            bindings: [':id' => $productId],
        )->resultset();
    }

    private function refused(callable $attempt, string $messagePart): void
    {
        try {
            $attempt();
        } catch (McpToolException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }
        $this->fail("Expected a refusal containing: {$messagePart}");
    }

    private function future(int $days = 30): string
    {
        return \date('Y-m-d', \strtotime("+{$days} days"));
    }

    // ---- categories list ----------------------------------------------------------

    public function test_the_category_list_shows_live_categories_with_their_parent(): void
    {
        $parent = $this->category("{$this->runTag} Parent");
        $child = $this->category("{$this->runTag} Child", $parent);
        $gone = $this->category("{$this->runTag} Gone", null, deleted: true);

        $list = ProductMcp::categoriesList($this->call([]));

        $byId = \array_column($list['categories'], null, 'id');
        $this->assertSame("{$this->runTag} Parent", $byId[$parent]['label']);
        $this->assertNull($byId[$parent]['parent']);
        $this->assertSame("{$this->runTag} Parent", $byId[$child]['parent']);
        $this->assertArrayNotHasKey($gone, $byId, 'a binned category is not offered');
        $this->assertSame(\count($list['categories']), $list['total']);
    }

    // ---- restock date -------------------------------------------------------------

    public function test_the_restock_date_is_set_and_logged_under_the_client_name(): void
    {
        $id = $this->product();
        $date = $this->future();

        $result = RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'restock_at' => $date]));

        $this->assertSame($date, $this->row($id)['restock_at']);
        $this->assertSame(['updated' => true, 'id' => $id, 'name' => $this->fullName(), 'restock_at' => $date, 'was' => null, 'services_notified' => true], $result);
        $this->assertSame([['description' => "Updated product: {$this->fullName()}", 'user_name' => 'Tester via MCP']], $this->changes($id));
        $this->assertSame([$id], RecordingProductMcp::$notified);
    }

    public function test_the_result_reports_the_old_date_and_whether_services_were_told(): void
    {
        $id = $this->product();
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET restock_at = '2027-03-04' WHERE id = :id", bindings: [':id' => $id])->execute();
        RecordingProductMcp::$succeeds = false;

        $result = RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'restock_at' => $this->future()]));

        $this->assertSame('2027-03-04', $result['was']);
        $this->assertFalse($result['services_notified']);
    }

    public function test_a_blank_date_clears_it_and_surrounding_spaces_are_ignored(): void
    {
        $id = $this->product();
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET restock_at = '2027-03-04' WHERE id = :id", bindings: [':id' => $id])->execute();

        $result = RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => "  {$this->fullName()}  ", 'restock_at' => '   ']));

        $this->assertNull($this->row($id)['restock_at']);
        $this->assertNull($result['restock_at']);

        $date = $this->future(5);
        RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'restock_at' => " {$date} "]));
        $this->assertSame($date, $this->row($id)['restock_at']);
    }

    public function test_a_product_not_in_a_restocking_status_is_refused(): void
    {
        $id = $this->product(restocking: false);

        $this->refused(fn() => RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'restock_at' => $this->future()])), 'not in a restocking status');
        $this->assertNull($this->row($id)['restock_at']);
        $this->assertSame([], RecordingProductMcp::$notified);
        $this->assertSame([], $this->changes($id));
    }

    public function test_a_bad_date_is_refused_and_nothing_changes(): void
    {
        $id = $this->product();
        $call = fn(mixed $date) => RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'restock_at' => $date]));

        $this->refused(fn() => $call('2020-01-01'), 'cannot be in the past');
        $this->refused(fn() => $call('2031-01-01'), 'more than two years');
        $this->refused(fn() => $call('next week'), 'real date');
        $this->refused(fn() => $call(null), 'Give restock_at');
        $this->refused(fn() => $call(20271010), 'Give restock_at');
        $this->assertNull($this->row($id)['restock_at']);
        $this->assertSame([], RecordingProductMcp::$notified);
        $this->assertSame([], $this->changes($id));
    }

    public function test_the_wrong_name_or_a_missing_or_binned_product_is_refused(): void
    {
        $id = $this->product();
        $date = $this->future();

        $this->refused(fn() => RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => 'Some Other Product', 'restock_at' => $date])), 'does not match product');
        $this->refused(fn() => RecordingProductMcp::setRestockDate($this->call(['id' => 999999999, 'expected_name' => $this->fullName(), 'restock_at' => $date])), 'No live product with id 999999999');

        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $id])->execute();
        $this->refused(fn() => RecordingProductMcp::setRestockDate($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'restock_at' => $date])), 'No live product');
        $this->assertNull($this->row($id)['restock_at']);
    }

    public function test_the_id_and_name_must_be_given_properly(): void
    {
        foreach ([[], ['id' => '5', 'expected_name' => 'x'], ['id' => 0, 'expected_name' => 'x'], ['id' => 5, 'expected_name' => ''], ['id' => 5, 'expected_name' => '   '], ['id' => 5]] as $arguments) {
            $this->refused(fn() => RecordingProductMcp::setRestockDate($this->call($arguments + ['restock_at' => $this->future()])), 'Give the product id');
            $this->refused(fn() => RecordingProductMcp::addCategory($this->call($arguments)), 'Give the product id');
        }
    }

    // ---- add to category ----------------------------------------------------------

    public function test_a_product_is_added_to_a_category_and_logged(): void
    {
        $id = $this->product();
        $category = $this->category("{$this->runTag} Panels");

        $result = RecordingProductMcp::addCategory($this->call(['id' => $id, 'expected_name' => $this->fullName(), 'category_id' => $category, 'expected_category' => " {$this->runTag} Panels "]));

        $this->assertSame(1, $this->assigned($id));
        $this->assertSame(['added' => true, 'id' => $id, 'name' => $this->fullName(), 'category_id' => $category, 'category' => "{$this->runTag} Panels", 'undo' => 'Remove it from the category in the admin'], $result);
        $this->assertSame([['description' => "Added product {$this->fullName()} to category {$this->runTag} Panels", 'user_name' => 'Tester via MCP']], $this->changes($id));
    }

    public function test_adding_a_category_needs_a_whole_number_id_and_a_label(): void
    {
        $id = $this->product();
        $category = $this->category("{$this->runTag} Panels");
        $base = ['id' => $id, 'expected_name' => $this->fullName()];

        foreach ([['category_id' => (string) $category, 'expected_category' => "{$this->runTag} Panels"], ['category_id' => 0, 'expected_category' => 'x'], ['category_id' => $category, 'expected_category' => '  '], ['expected_category' => 'x'], ['category_id' => $category]] as $extra) {
            $this->refused(fn() => RecordingProductMcp::addCategory($this->call($base + $extra)), 'Give the category_id');
        }
        $this->assertSame(0, $this->assigned($id));
    }

    public function test_a_missing_binned_or_misnamed_category_is_refused(): void
    {
        $id = $this->product();
        $live = $this->category("{$this->runTag} Panels");
        $binned = $this->category("{$this->runTag} Binned", null, deleted: true);
        $base = ['id' => $id, 'expected_name' => $this->fullName()];

        $this->refused(fn() => RecordingProductMcp::addCategory($this->call($base + ['category_id' => 999999999, 'expected_category' => 'x'])), 'No live category with id 999999999');
        $this->refused(fn() => RecordingProductMcp::addCategory($this->call($base + ['category_id' => $binned, 'expected_category' => "{$this->runTag} Binned"])), 'No live category');
        $this->refused(fn() => RecordingProductMcp::addCategory($this->call($base + ['category_id' => $live, 'expected_category' => 'Wrong label'])), 'label does not match');
        $this->assertSame(0, $this->assigned($id));
        $this->assertSame([], $this->changes($id));
    }

    public function test_a_product_already_in_the_category_is_refused_and_not_added_twice(): void
    {
        $id = $this->product();
        $category = $this->category("{$this->runTag} Panels");
        $arguments = ['id' => $id, 'expected_name' => $this->fullName(), 'category_id' => $category, 'expected_category' => "{$this->runTag} Panels"];
        RecordingProductMcp::addCategory($this->call($arguments));

        $this->refused(fn() => RecordingProductMcp::addCategory($this->call($arguments)), 'already in that category');
        $this->assertSame(1, $this->assigned($id));
        $this->assertCount(1, $this->changes($id));
    }

    public function test_adding_a_category_checks_the_product_first(): void
    {
        $category = $this->category("{$this->runTag} Panels");

        $this->refused(fn() => RecordingProductMcp::addCategory($this->call(['id' => 999999999, 'expected_name' => 'x', 'category_id' => $category, 'expected_category' => "{$this->runTag} Panels"])), 'No live product');
    }
}
