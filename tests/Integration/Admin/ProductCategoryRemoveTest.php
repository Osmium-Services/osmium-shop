<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Services\ProductMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/ProductMcp.php';

/** product_category_remove run for real: only the link goes, and every refusal leaves the links as they were. */
class ProductCategoryRemoveTest extends DatabaseTestCase
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

    private function category(string $label): int
    {
        $this->db->prepare(sql: "INSERT INTO {$this->db->tablePrefix()}productcategories (label) VALUES (:l)", bindings: [':l' => $label])->execute();

        return (int) $this->db->lastInsertId();
    }

    private function link(int $productId, int $categoryId): void
    {
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}products_productcategories (product_id, category_id, created_at) VALUES (:p, :c, NOW())",
            bindings: [':p' => $productId, ':c' => $categoryId],
        )->execute();
    }

    private function linkCount(int $productId): int
    {
        return (int) $this->db->prepare(
            sql: "SELECT COUNT(*) AS c FROM {$this->db->tablePrefix()}products_productcategories WHERE product_id = :p",
            bindings: [':p' => $productId],
        )->single()['c'];
    }

    private function remove(int $productId, string $name, int $categoryId, string $label): array
    {
        $arguments = ['id' => $productId, 'expected_name' => $name, 'category_id' => $categoryId, 'expected_category' => $label];

        return ProductMcp::removeCategory(['dataSource' => $this->db, 'arguments' => $arguments, 'actor' => 'Tester via MCP']);
    }

    private function productName(int $id): string
    {
        $prefix = $this->db->tablePrefix();

        return \trim((string) $this->db->prepare(
            sql: "SELECT CONCAT(COALESCE(b.name, ''), ' ', p.model_name) AS n FROM {$prefix}products p LEFT JOIN {$prefix}productbrands b ON b.id = p.brand_id WHERE p.id = :id",
            bindings: [':id' => $id],
        )->single()['n']);
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

    public function test_only_the_chosen_link_is_removed(): void
    {
        $tag = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
        $keep = $this->category("{$tag} Keep");
        $drop = $this->category("{$tag} Drop");
        $id = $this->createTestProduct();
        $this->link($id, $keep);
        $this->link($id, $drop);

        $result = $this->remove($id, $this->productName($id), $drop, "{$tag} Drop");

        $this->assertTrue($result['removed']);
        $this->assertSame(1, $this->linkCount($id));
    }

    public function test_refusals_leave_the_links_alone(): void
    {
        $tag = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
        $in = $this->category("{$tag} In");
        $out = $this->category("{$tag} Out");
        $id = $this->createTestProduct();
        $this->link($id, $in);
        $name = $this->productName($id);

        $this->refused(fn() => $this->remove($id, $name, $out, "{$tag} Out"), 'not in that category');
        $this->refused(fn() => $this->remove($id, $name, $in, 'Wrong'), 'does not match');
        $this->refused(fn() => $this->remove($id, 'Wrong name', $in, "{$tag} In"), 'does not match');
        $this->refused(fn() => $this->remove($id, $name, 999999999, 'x'), 'No live category');

        $this->assertSame(1, $this->linkCount($id));
    }
}
