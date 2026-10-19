<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Osmium\Modules\Admin\Services\ShopSpecRebuild;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/models/Product.php';
require_once 'app/services/osmium-shop/models/TechspecParser.php';
require_once 'app/services/osmium-shop/models/ShopSpecRebuild.php';

/**
 * The Rebuild Spec JSON action on the Migrations page: it rewrites every product's parsed specs from its techspec.
 * Real models, rolled back.
 */
class ShopSpecRebuildTest extends ShopDatabaseTestCase
{
    private const SPEC_TABLE = '<table><tbody><tr><td>Power</td><td>100 W</td></tr><tr><td>Weight</td><td>2 kg</td></tr></tbody></table>';

    private OsmiumAdminModel $model;
    private object $osmium;
    private object $admin;
    private string $runTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runTag = \strtolower('zq' . \substr(\uniqid(), -6));

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $this->model = new OsmiumAdminModel($this->db, (object) \array_intersect_key((array) $all, ['changelog' => true]));
        $this->model->addModel($this->db, 'product', \Osmium\Modules\Admin\Models\Product::class, 'products');

        $this->osmium = new class ($this->db) extends Osmium {
            public function __construct(\Osmium\Core\Library\OsmiumPDO $db)
            {
                $this->dataSource = $db;
            }
        };
        $this->admin = (object) ['model' => $this->model];
    }

    private function rebuild(): array
    {
        return ShopSpecRebuild::rebuild(['osmium' => $this->osmium, 'admin' => $this->admin]);
    }

    private function lastChangelog(string $type): ?array
    {
        return $this->db->prepare(
            sql: 'SELECT description, record_type FROM ' . $this->db->tablePrefix() . 'changelog WHERE record_type = :t ORDER BY id DESC LIMIT 1',
            bindings: [':t' => $type],
        )->single() ?: null;
    }

    private function productWithSpec(?string $techspec, ?string $json = null): int
    {
        $id = $this->createTestProduct(['model_name' => "Spec {$this->runTag} " . \uniqid()]);
        $this->db->prepare(
            sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET techspec = :t, techspec_json = :j WHERE id = :id',
            bindings: [':t' => $techspec, ':j' => $json, ':id' => $id],
        )->execute();

        return $id;
    }

    private function specJson(int $id): ?string
    {
        return $this->db->prepare(sql: 'SELECT techspec_json FROM ' . $this->db->tablePrefix() . 'products WHERE id = :id', bindings: [':id' => $id])->single()['techspec_json'];
    }

    public function test_the_button_is_offered_with_its_handler(): void
    {
        $action = ShopSpecRebuild::action([]);

        $this->assertSame('rebuild-specs', $action['action']);
        $this->assertTrue(\is_callable(\explode('::', $action['handler'])));
    }

    public function test_the_rebuild_parses_each_products_spec_table_and_clears_the_ones_with_none(): void
    {
        $withTable = $this->productWithSpec(self::SPEC_TABLE);
        $placeholder = $this->productWithSpec('<p>Spec coming soon</p>', '[{"stale":true}]');
        $blank = $this->productWithSpec('', '[{"stale":true}]');

        $this->rebuild();

        $sections = \json_decode((string) $this->specJson($withTable), true);
        $this->assertSame(['Power', 'Weight'], \array_column($sections[0]['specs'], 'label'));
        $this->assertSame(['100 W', '2 kg'], \array_column($sections[0]['specs'], 'value'));
        $this->assertNull($this->specJson($placeholder), 'nothing parseable is NULL so the page falls back to the HTML');
        $this->assertSame('[{"stale":true}]', $this->specJson($blank), 'a product with no spec at all is not in the rebuild');
    }

    public function test_the_rebuild_counts_what_it_did_and_logs_it(): void
    {
        $this->productWithSpec(self::SPEC_TABLE);
        $this->productWithSpec('<p>none</p>');
        $all = $this->model->product->listWithTechspec();

        $counts = $this->rebuild();

        $this->assertTrue($counts['success']);
        $this->assertSame(\count($all), $counts['products']);
        $this->assertSame($counts['products'], $counts['parsed'] + $counts['empty']);
        $this->assertGreaterThanOrEqual(1, $counts['parsed']);
        $this->assertGreaterThanOrEqual(1, $counts['empty']);
        $this->assertGreaterThanOrEqual(2, $counts['specs'], 'the two rows of the test product, at least');
        $this->assertStringContainsString("{$counts['parsed']} of {$counts['products']} product(s)", $counts['message']);
        $this->assertSame(
            "Rebuilt product spec JSON: {$counts['parsed']} of {$counts['products']} products, {$counts['specs']} spec rows",
            $this->lastChangelog('product')['description'],
        );
    }

    public function test_the_rebuild_replaces_json_that_is_already_there_and_skips_binned_products(): void
    {
        $stale = $this->productWithSpec(self::SPEC_TABLE, '[{"section":null,"specs":[{"key":"old","label":"Old","value":"x"}]}]');
        $binned = $this->productWithSpec(self::SPEC_TABLE, '[{"stale":true}]');
        $this->db->prepare(sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id', bindings: [':id' => $binned])->execute();

        $this->rebuild();

        $this->assertStringNotContainsString('Old', (string) $this->specJson($stale), 'a parser improvement reaches products that already have JSON');
        $this->assertStringContainsString('Power', (string) $this->specJson($stale));
        $this->assertSame('[{"stale":true}]', $this->specJson($binned));
    }
}
