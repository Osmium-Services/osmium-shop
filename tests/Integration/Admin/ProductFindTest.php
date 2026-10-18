<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Autoload;
use Osmium\Modules\Admin\Services\ProductMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/ProductMcp.php';

/** products_find run for real: matches brand, model and reference, never a deleted product, and caps the list. */
class ProductFindTest extends DatabaseTestCase
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

    private function find(string $search): array
    {
        return ProductMcp::find(['dataSource' => $this->db, 'arguments' => ['search' => $search]]);
    }

    public function test_it_finds_a_live_product_by_model_and_by_reference_but_not_a_deleted_one(): void
    {
        $tag = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
        $live = $this->createTestProduct(['model_name' => "{$tag} Widget", 'reference' => "{$tag}-REF"]);
        $gone = $this->createTestProduct(['model_name' => "{$tag} Gadget"]);
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $gone])->execute();

        $byModel = $this->find(\strtolower($tag) . ' widget');
        $byReference = $this->find("{$tag}-REF");

        $this->assertSame([$live], \array_column($byModel['products'], 'id'));
        $this->assertSame([$live], \array_column($byReference['products'], 'id'));
        $this->assertSame([], $this->find("{$tag} Gadget")['products']);
        $this->assertStringContainsString("{$tag} Widget", $byModel['products'][0]['name']);
    }

    public function test_a_broad_search_is_capped_and_says_there_is_more(): void
    {
        $tag = 'Zq' . \substr(\md5(\uniqid()), 0, 8);
        for ($i = 0; $i < 26; $i++) $this->createTestProduct(['model_name' => "{$tag} Item {$i}"]);

        $result = $this->find($tag);

        $this->assertSame([25, true], [$result['shown'], $result['more']]);
    }
}
