<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Services\ProductMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/ProductMcp.php';

/**
 * product_category_rename run for real: only the label changes, the page title follows it only when the category
 * has no title of its own, and every refusal leaves the rows as they were.
 */
class ProductCategoryRenameTest extends DatabaseTestCase
{
    private static bool $autoloadRegistered = false;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$autoloadRegistered) {
            (new Autoload('app/config'))->register();
            self::$autoloadRegistered = true;
        }

        $this->tag = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
    }

    private function call(array $arguments): array
    {
        $osmium = (object) ['config' => (object) ['site' => (object) ['urlTrailingSlash' => false]]];

        return ['dataSource' => $this->db, 'osmium' => $osmium, 'arguments' => $arguments, 'actor' => 'Tester via MCP'];
    }

    private function category(string $suffix, ?string $title = null, ?int $parentId = null, bool $withPage = false): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productcategories (parent_id, label, title, subtitle, description) VALUES (:p, :l, :t, 'Sub', 'Desc')",
            bindings: [':p' => $parentId, ':l' => "{$this->tag} {$suffix}", ':t' => $title],
        )->execute();
        $id = (int) $this->db->lastInsertId();

        if ($withPage) {
            $pageId = (int) $this->db->prepare(sql: "SELECT COALESCE(MAX(id), 0) + 1 AS n FROM {$prefix}pages")->single()['n'];
            $this->db->prepare(
                sql: "INSERT INTO {$prefix}pages (id, url, title, description, robots) VALUES (:i, :u, :t, 'Page desc', 'noindex,follow')",
                bindings: [':i' => $pageId, ':u' => \strtolower($this->tag) . '-' . \strtolower($suffix), ':t' => "{$this->tag} {$suffix}"],
            )->execute();
            $this->db->prepare(
                sql: "INSERT INTO {$prefix}productcategories_pages (productcategory_id, page_id) VALUES (:c, :p)",
                bindings: [':c' => $id, ':p' => $pageId],
            )->execute();
        }

        return $id;
    }

    private function row(int $id): array
    {
        return $this->db->prepare(sql: "SELECT * FROM {$this->db->tablePrefix()}productcategories WHERE id = :id", bindings: [':id' => $id])->single();
    }

    private function pageTitle(int $categoryId): string
    {
        $prefix = $this->db->tablePrefix();

        return (string) $this->db->prepare(
            sql: "SELECT p.title FROM {$prefix}pages p JOIN {$prefix}productcategories_pages cp ON cp.page_id = p.id WHERE cp.productcategory_id = :c",
            bindings: [':c' => $categoryId],
        )->single()['title'];
    }

    private function refused(array $arguments, string $messagePart): void
    {
        try {
            ProductMcp::renameCategory($this->call($arguments));
        } catch (McpToolException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }
        $this->fail('Expected a refusal');
    }

    public function test_a_rename_changes_only_the_label(): void
    {
        $id = $this->category('Panels', title: 'Own title');
        $before = $this->row($id);

        $result = ProductMcp::renameCategory($this->call(['id' => $id, 'expected_label' => "{$this->tag} Panels", 'label' => " {$this->tag} Solar Panels "]));
        $after = $this->row($id);

        $this->assertTrue($result['updated']);
        $this->assertSame("{$this->tag} Solar Panels", $after['label']);
        foreach (['parent_id', 'title', 'subtitle', 'description', 'sort'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }
    }

    public function test_the_page_title_follows_only_when_the_category_has_no_title(): void
    {
        $plain = $this->category('Plain', withPage: true);
        $titled = $this->category('Titled', title: 'Own title', withPage: true);

        ProductMcp::renameCategory($this->call(['id' => $plain, 'expected_label' => "{$this->tag} Plain", 'label' => "{$this->tag} Renamed"]));
        ProductMcp::renameCategory($this->call(['id' => $titled, 'expected_label' => "{$this->tag} Titled", 'label' => "{$this->tag} Retitled"]));

        $this->assertSame("{$this->tag} Renamed", $this->pageTitle($plain));
        $this->assertSame("{$this->tag} Titled", $this->pageTitle($titled));
    }

    public function test_refusals_leave_the_label_alone(): void
    {
        $id = $this->category('Panels');
        $this->category('Inverters');
        $name = "{$this->tag} Panels";

        $this->refused(['id' => $id, 'expected_label' => 'Wrong', 'label' => 'x'], 'does not match');
        $this->refused(['id' => $id, 'expected_label' => $name, 'label' => ' '], 'blank');
        $this->refused(['id' => $id, 'expected_label' => $name, 'label' => "{$this->tag} inverters"], 'same level');
        $this->refused(['id' => 999999999, 'expected_label' => $name, 'label' => 'x'], 'No live category');

        $this->assertSame($name, $this->row($id)['label']);
    }
}
