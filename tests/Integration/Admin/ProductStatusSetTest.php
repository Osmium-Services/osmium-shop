<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Services\ProductMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/ProductMcp.php';

/**
 * product_status_set run for real: only the status changes, the restock date is dropped when the new status is
 * not a restocking one, and every refusal leaves the product as it was.
 */
class ProductStatusSetTest extends DatabaseTestCase
{
    private static bool $autoloadRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$autoloadRegistered) {
            (new Autoload('app/config'))->register();
            self::$autoloadRegistered = true;
        }
    }

    private function makeStatus(bool $restocking, bool $enabled = true): array
    {
        $prefix = $this->db->tablePrefix();
        $label = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productstatus (descr_short, descr_long, product_available, restocking, enabled) VALUES (:s, :s, 1, :r, :e)",
            bindings: [':s' => $label, ':r' => (int) $restocking, ':e' => (int) $enabled],
        )->execute();

        return ['id' => (int) $this->db->lastInsertId(), 'label' => $label];
    }

    private function call(int $productId, string $name, int $statusId, string $label): array
    {
        $osmium = (object) ['config' => (object) ['site' => (object) ['urlTrailingSlash' => false]]];
        $arguments = ['id' => $productId, 'expected_name' => $name, 'status_id' => $statusId, 'expected_status' => $label];

        return ['dataSource' => $this->db, 'osmium' => $osmium, 'arguments' => $arguments, 'actor' => 'Tester via MCP'];
    }

    private function row(int $id): array
    {
        return $this->db->prepare(sql: "SELECT * FROM {$this->db->tablePrefix()}products WHERE id = :id", bindings: [':id' => $id])->single();
    }

    private function set(int $productId, array $status): array
    {
        $name = \trim((string) $this->db->prepare(
            sql: "SELECT CONCAT(COALESCE(b.name, ''), ' ', p.model_name) AS n FROM {$this->db->tablePrefix()}products p LEFT JOIN {$this->db->tablePrefix()}productbrands b ON b.id = p.brand_id WHERE p.id = :id",
            bindings: [':id' => $productId],
        )->single()['n']);

        return QuietProductMcp::setStatus($this->call($productId, $name, $status['id'], $status['label']));
    }

    private function refused(callable $attempt, string $messagePart): void
    {
        try {
            $attempt();
        } catch (McpToolException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }
        $this->fail('Expected a refusal');
    }

    public function test_only_the_status_changes_and_moving_off_restocking_clears_the_date(): void
    {
        $restocking = $this->makeStatus(restocking: true);
        $plain = $this->makeStatus(restocking: false);
        $id = $this->createTestProduct(['price_exc_tax' => 123.45]);
        $before = $this->row($id);

        $toRestocking = $this->set($id, $restocking);
        $this->products()->setRestockAt($id, '2030-01-05');
        $toPlain = $this->set($id, $plain);
        $after = $this->row($id);

        $this->assertTrue($toRestocking['needs_restock_date']);
        $this->assertTrue($toPlain['restock_date_cleared']);
        $this->assertSame($plain['id'], (int) $after['status_id']);
        $this->assertNull($after['restock_at']);
        foreach (['model_name', 'price_exc_tax', 'descr', 'brand_id'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }
    }

    public function test_moving_to_a_restocking_status_keeps_the_date(): void
    {
        $restocking = $this->makeStatus(restocking: true);
        $id = $this->createTestProduct();
        $this->products()->setRestockAt($id, '2030-01-05');

        $this->set($id, $restocking);

        $this->assertSame('2030-01-05', $this->row($id)['restock_at']);
    }

    public function test_refusals_leave_the_product_alone(): void
    {
        $plain = $this->makeStatus(restocking: false);
        $disabled = $this->makeStatus(restocking: false, enabled: false);
        $id = $this->createTestProduct();
        $before = (int) $this->row($id)['status_id'];
        $name = \trim((string) $this->db->prepare(
            sql: "SELECT CONCAT(COALESCE(b.name, ''), ' ', p.model_name) AS n FROM {$this->db->tablePrefix()}products p LEFT JOIN {$this->db->tablePrefix()}productbrands b ON b.id = p.brand_id WHERE p.id = :id",
            bindings: [':id' => $id],
        )->single()['n']); // The tool's name is brand and model together

        $this->refused(fn() => QuietProductMcp::setStatus($this->call($id, $name, $plain['id'], 'Wrong')), 'does not match');
        $this->refused(fn() => QuietProductMcp::setStatus($this->call($id, 'Wrong name', $plain['id'], $plain['label'])), 'does not match');
        $this->refused(fn() => QuietProductMcp::setStatus($this->call($id, $name, $disabled['id'], $disabled['label'])), 'disabled');
        $this->refused(fn() => QuietProductMcp::setStatus($this->call($id, $name, 999999, 'x')), 'No live status');

        $this->assertSame($before, (int) $this->row($id)['status_id']);
    }

    private function products(): \Osmium\Modules\Admin\Models\Product
    {
        return new \Osmium\Modules\Admin\Models\Product($this->db, 'products');
    }
}

/** ProductMcp without the product.saved notice, which needs the whole site loaded. */
class QuietProductMcp extends ProductMcp
{
    protected static function notifyServices(array $call, int $productId): bool
    {
        return false;
    }
}
