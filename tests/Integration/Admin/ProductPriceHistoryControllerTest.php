<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\ProductPriceHistoryController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/services/osmium-shop/models/Product.php';
require_once 'app/services/osmium-shop/models/ProductPriceHistory.php';
require_once 'app/services/osmium-shop/controllers/ProductPriceHistoryController.php';

/**
 * The price history screen: what it lists (import batches, manual changes, the product filter), which change may
 * still be undone, and the undo itself, which puts a product's price back. Real controller, real models, rolled
 * back. Undoing a batch that has since been overtaken answers with echo and exit, so that one refusal is not driven.
 */
class ProductPriceHistoryControllerTest extends ShopDatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private OsmiumAdminModel $model;
    private object $admin;
    private array $savedServer;
    private string $runTag;
    private int $brandId;
    private array $stashed = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token', 'admin_user_id' => 3];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->runTag = \strtolower('zq' . \substr(\uniqid(), -6));
        $this->stashed = [];

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $wanted = [];
        $this->model = new OsmiumAdminModel($this->db, (object) \array_intersect_key((array) $all, \array_flip($wanted)));
        $this->model->addModel($this->db, 'product', \Osmium\Modules\Admin\Models\Product::class, 'products'); // The shop service's model
        $this->model->addModel($this->db, 'productPriceHistory', \Osmium\Modules\Admin\Models\ProductPriceHistory::class, 'product_price_history'); // The shop service's model

        $auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new Password(),
        ) extends AdminAuth {};

        $this->admin = new class ($this->model, $auth) extends OsmiumAdmin {
            public array $input = [];
            public ?array $response = null;

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
            }

            public function getJsonInput(): array
            {
                return $this->input;
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

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "Brand {$this->runTag}"]);
        $this->brandId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function controller(array &$data): ProductPriceHistoryController
    {
        $osmium = new class ($this->db, $this->stashed) extends Osmium {
            public bool $cleared = false;

            public function __construct(OsmiumPDO $db, private array $stashed)
            {
                $this->dataSource = $db;
            }

            public function getStashedParam(string $key, mixed $default = null): mixed
            {
                return $this->stashed[$key] ?? $default;
            }

            public function clearStashedQuerystring(): void
            {
                $this->cleared = true;
            }
        };

        return new ProductPriceHistoryController($osmium, $this->admin, $data);
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function act(array $input, bool $validToken = true): array
    {
        $this->admin->input = $input + ['csrf_token' => $validToken ? 'good-token' : 'forged'];
        $this->admin->response = null;
        $data = [];

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent'));
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function page(array $stashed = []): array
    {
        $this->stashed = $stashed;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = ['admin' => ['basePath' => '/admin/']];
        $this->controller($data)->index();

        return $data;
    }

    private function product(float $price = 100.00, float $rrp = 120.00, string $model = ''): int
    {
        return $this->createTestProduct([
            'brand_id' => $this->brandId,
            'model_name' => $model ?: "Model {$this->runTag} " . \uniqid(),
            'price_exc_tax' => $price,
            'rrp_exc_tax' => $rrp,
        ]);
    }

    private function change(int $productId, string $source, string $at, float $old, float $new, ?float $oldRrp = 120.00, ?float $newRrp = 120.00, bool $undone = false): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'product_price_history', data: [
            'product_id' => $productId,
            'price_exc_tax_old' => $old,
            'price_exc_tax_new' => $new,
            'rrp_exc_tax_old' => $oldRrp,
            'rrp_exc_tax_new' => $newRrp,
            'changed_at' => $at,
            'changed_by' => 3,
            'change_source' => $source,
            'undone_at' => $undone ? $at : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function price(int $productId): array
    {
        $row = $this->db->prepare(sql: 'SELECT price_exc_tax, rrp_exc_tax FROM ' . $this->db->tablePrefix() . 'products WHERE id = :id', bindings: [':id' => $productId])->single();

        return [(float) $row['price_exc_tax'], (float) $row['rrp_exc_tax']];
    }

    private function undoneAt(int $historyId): ?string
    {
        return $this->db->prepare(sql: 'SELECT undone_at FROM ' . $this->db->tablePrefix() . 'product_price_history WHERE id = :id', bindings: [':id' => $historyId])->single()['undone_at'];
    }

    // ---- request checks -----------------------------------------------------------

    public function test_a_request_needs_an_action_and_a_valid_token(): void
    {
        $this->assertSame('Missing action', $this->act([])['error']);
        $this->assertStringContainsString('Invalid request token', $this->act(['action' => 'undo_manual', 'id' => 1], validToken: false)['error']);
        $this->assertSame('Unknown action', $this->act(['action' => 'explode'])['error']);
    }

    public function test_a_forged_token_undoes_nothing(): void
    {
        $productId = $this->product(price: 80.00);
        $id = $this->change($productId, 'manual', '2026-03-01 10:00:00', old: 100.00, new: 80.00);

        $this->act(['action' => 'undo_manual', 'id' => $id], validToken: false);

        $this->assertSame([80.0, 120.0], $this->price($productId));
        $this->assertNull($this->undoneAt($id));
    }

    // ---- undo one manual change ---------------------------------------------------

    public function test_undoing_a_manual_change_puts_the_old_price_and_rrp_back_and_marks_it_done(): void
    {
        $productId = $this->product(price: 80.00, rrp: 110.00);
        $id = $this->change($productId, 'manual', '2026-03-01 10:00:00', old: 100.00, new: 80.00, oldRrp: 130.00, newRrp: 110.00);

        $response = $this->act(['action' => 'undo_manual', 'id' => $id]);

        $this->assertTrue($response['ok'], $response['error'] ?? '');
        $this->assertSame([100.0, 130.0], $this->price($productId));
        $this->assertNotNull($this->undoneAt($id));
    }

    public function test_a_manual_change_cannot_be_undone_once_the_product_has_a_newer_one(): void
    {
        $productId = $this->product(price: 60.00);
        $first = $this->change($productId, 'manual', '2026-03-01 10:00:00', old: 100.00, new: 80.00);
        $this->change($productId, 'manual', '2026-03-02 10:00:00', old: 80.00, new: 60.00);

        $response = $this->act(['action' => 'undo_manual', 'id' => $first]);

        $this->assertSame('Cannot undo: this product has newer price changes', $response['error']);
        $this->assertSame([60.0, 120.0], $this->price($productId), 'undoing the older one would silently discard the newer price');
        $this->assertNull($this->undoneAt($first));
    }

    public function test_a_newer_change_that_was_itself_undone_does_not_block(): void
    {
        $productId = $this->product(price: 80.00);
        $first = $this->change($productId, 'manual', '2026-03-01 10:00:00', old: 100.00, new: 80.00);
        $this->change($productId, 'manual', '2026-03-02 10:00:00', old: 80.00, new: 60.00, undone: true);

        $this->assertTrue($this->act(['action' => 'undo_manual', 'id' => $first])['ok']);
        $this->assertSame([100.0, 120.0], $this->price($productId));
    }

    public function test_another_products_newer_change_does_not_block(): void
    {
        $productId = $this->product(price: 80.00);
        $other = $this->product(price: 10.00);
        $first = $this->change($productId, 'manual', '2026-03-01 10:00:00', old: 100.00, new: 80.00);
        $this->change($other, 'manual', '2026-03-05 10:00:00', old: 5.00, new: 10.00);

        $this->assertTrue($this->act(['action' => 'undo_manual', 'id' => $first])['ok']);
    }

    public function test_the_manual_undo_needs_a_real_manual_record(): void
    {
        $productId = $this->product(price: 80.00);
        $import = $this->change($productId, 'import', '2026-03-01 10:00:00', old: 100.00, new: 80.00);

        $this->assertSame('Missing ID', $this->act(['action' => 'undo_manual'])['error']);
        $this->assertSame('Record not found', $this->act(['action' => 'undo_manual', 'id' => 4000000])['error']);
        $this->assertSame('Record not found', $this->act(['action' => 'undo_manual', 'id' => $import])['error'], 'an import row is undone as a batch, not one at a time');
        $this->assertSame([80.0, 120.0], $this->price($productId));
    }

    // ---- undo a batch -------------------------------------------------------------

    public function test_undoing_a_batch_reverts_every_product_in_it(): void
    {
        $a = $this->product(price: 90.00, rrp: 100.00);
        $b = $this->product(price: 45.00, rrp: 50.00);
        $other = $this->product(price: 70.00);
        $at = '2026-04-01 09:00:00';
        $idA = $this->change($a, 'import', $at, old: 100.00, new: 90.00, oldRrp: 120.00, newRrp: 100.00);
        $idB = $this->change($b, 'import', $at, old: 50.00, new: 45.00, oldRrp: 60.00, newRrp: 50.00);
        $otherBatch = $this->change($other, 'import', '2026-04-02 09:00:00', old: 75.00, new: 70.00);

        $response = $this->act(['action' => 'undo_batch', 'timestamp' => $at]);

        $this->assertTrue($response['ok'], $response['error'] ?? '');
        $this->assertSame(2, $response['body']['reverted']);
        $this->assertSame([100.0, 120.0], $this->price($a));
        $this->assertSame([50.0, 60.0], $this->price($b));
        $this->assertNotNull($this->undoneAt($idA));
        $this->assertNotNull($this->undoneAt($idB));
        $this->assertSame([70.0, 120.0], $this->price($other), 'a different batch is left alone');
        $this->assertNull($this->undoneAt($otherBatch));
    }

    public function test_a_batch_undo_does_not_touch_manual_changes_made_at_the_same_moment(): void
    {
        $a = $this->product(price: 90.00);
        $manualProduct = $this->product(price: 33.00);
        $at = '2026-04-01 09:00:00';
        $this->change($a, 'import', $at, old: 100.00, new: 90.00);
        $manual = $this->change($manualProduct, 'manual', $at, old: 40.00, new: 33.00);

        $this->act(['action' => 'undo_batch', 'timestamp' => $at]);

        $this->assertSame([33.0, 120.0], $this->price($manualProduct));
        $this->assertNull($this->undoneAt($manual));
    }

    public function test_a_batch_needs_a_timestamp_and_some_records(): void
    {
        $this->assertSame('Missing timestamp', $this->act(['action' => 'undo_batch'])['error']);
        $this->assertSame('No records found for this batch', $this->act(['action' => 'undo_batch', 'timestamp' => '2001-01-01 00:00:00'])['error']);
    }

    // ---- one product's history ----------------------------------------------------

    public function test_a_products_history_is_returned_for_the_page(): void
    {
        $productId = $this->product();
        $this->change($productId, 'manual', '2026-03-01 10:00:00', old: 100.00, new: 80.00);
        $this->change($productId, 'import', '2026-03-02 10:00:00', old: 80.00, new: 70.00);
        $this->change($this->product(), 'manual', '2026-03-03 10:00:00', old: 5.00, new: 6.00);

        $response = $this->act(['action' => 'get_product_history', 'product_id' => $productId]);

        $this->assertTrue($response['body']['success']);
        $this->assertCount(2, $response['body']['history'], 'only this product');
        $this->assertSame('Missing product ID', $this->act(['action' => 'get_product_history'])['error']);
    }

    // ---- the page -----------------------------------------------------------------

    public function test_the_page_lists_batches_newest_first_and_only_the_latest_can_be_undone(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->change($a, 'import', '2099-01-01 08:00:00', old: 100.00, new: 90.00);
        $this->change($b, 'import', '2099-01-02 08:00:00', old: 50.00, new: 45.00);
        $this->change($a, 'import', '2099-01-02 08:00:00', old: 100.00, new: 95.00);
        $this->change($a, 'import', '2098-12-31 08:00:00', old: 1.00, new: 2.00, undone: true);

        $data = $this->page();

        $batches = \array_values(\array_filter($data['admin']['importBatches'], fn ($b) => \str_starts_with($b['changed_at'], '2099') || \str_starts_with($b['changed_at'], '2098')));
        $this->assertSame(['2099-01-02 08:00:00', '2099-01-01 08:00:00'], \array_column($batches, 'changed_at'), 'an undone batch is not listed');
        $this->assertSame(2, $batches[0]['productCount']);
        $this->assertSame('2 Jan 2099, 08:00', $batches[0]['changedAtFormatted']);
        $this->assertTrue($data['admin']['importBatches'][0]['canUndo']);
        $this->assertSame([], \array_filter(\array_slice($data['admin']['importBatches'], 1), fn ($b) => $b['canUndo']), 'older batches stay');
        $this->assertCount(2, $data['admin']['importDetails']['2099-01-02 08:00:00']);
        $this->assertTrue($data['admin']['hasHistory']);
        $this->assertSame('app/core/admin/views/products/price-history.phtml', $data['adminContent']);
    }

    public function test_the_page_formats_a_change_with_the_product_named_and_prices_in_pounds(): void
    {
        $productId = $this->product(model: 'Tom & Jerry <b>');
        $this->change($productId, 'import', '2099-02-01 08:00:00', old: 1234.5, new: 99.0, oldRrp: null, newRrp: 150.0);

        $data = $this->page();

        $detail = $data['admin']['importDetails']['2099-02-01 08:00:00'][0];
        $this->assertSame("Brand {$this->runTag} Tom &amp; Jerry &lt;b&gt;", $detail['productLabelHtml'], 'a product name is escaped');
        $this->assertSame('&pound;1,234.50', $detail['priceOldHtml']);
        $this->assertSame('&pound;99.00', $detail['priceNewHtml']);
        $this->assertSame('-', $detail['rrpOldDisplay'], 'no old RRP');
        $this->assertSame('&pound;150.00', $detail['rrpNewDisplay']);
    }

    public function test_a_change_to_a_product_that_has_gone_is_labelled_by_its_number(): void
    {
        $this->change(63999, 'manual', '2099-02-02 08:00:00', old: 5.00, new: 6.00);

        $data = $this->page();

        $change = \array_values(\array_filter($data['admin']['manualChanges'], fn ($c) => (int) $c['product_id'] === 63999))[0];
        $this->assertSame(' Product #63999', $change['productLabelHtml']);
    }

    public function test_the_page_lists_manual_changes_and_only_the_latest_can_be_undone(): void
    {
        $productId = $this->product();
        $older = $this->change($productId, 'manual', '2099-03-01 08:00:00', old: 100.00, new: 90.00, oldRrp: null, newRrp: null);
        $newer = $this->change($productId, 'manual', '2099-03-02 08:00:00', old: 90.00, new: 80.00, oldRrp: 120.00, newRrp: 100.00);
        $this->change($productId, 'manual', '2099-03-03 08:00:00', old: 1.00, new: 2.00, undone: true);

        $data = $this->page();

        $byId = [];
        foreach ($data['admin']['manualChanges'] as $change) {
            $byId[(int) $change['id']] = $change;
        }
        $this->assertArrayHasKey($newer, $byId);
        $this->assertArrayHasKey($older, $byId);
        $this->assertCount(2, \array_filter($data['admin']['manualChanges'], fn ($c) => $c['product_id'] == $productId), 'the undone one is not listed');
        $this->assertTrue($data['admin']['manualChanges'][0]['canUndo']);
        $this->assertFalse($byId[$older]['canUndo']);
        $this->assertFalse($byId[$older]['hasRrp']);
        $this->assertTrue($byId[$newer]['hasRrp']);
        $this->assertSame('&pound;90.00', $byId[$newer]['priceOldHtml']);
        $this->assertSame('&pound;100.00', $byId[$newer]['rrpNewHtml']);
        $this->assertSame('2 Mar 2099, 08:00', $byId[$newer]['changedAtFormatted']);
    }

    public function test_one_products_view_shows_only_that_product_and_offers_no_batch_undo(): void
    {
        $productId = $this->product(model: 'Just This One');
        $other = $this->product();
        $this->change($productId, 'import', '2099-04-01 08:00:00', old: 100.00, new: 90.00);
        $this->change($other, 'import', '2099-04-02 08:00:00', old: 100.00, new: 90.00);
        $this->change($productId, 'manual', '2099-04-03 08:00:00', old: 90.00, new: 85.00);

        $data = $this->page(['product_id' => (string) $productId]);

        $this->assertSame($productId, $data['admin']['filterProductId']);
        $this->assertSame("Brand {$this->runTag} Just This One", $data['admin']['filterProductName']);
        $this->assertSame(['2099-04-01 08:00:00'], \array_column($data['admin']['importBatches'], 'changed_at'));
        $this->assertFalse($data['admin']['importBatches'][0]['canUndo'], 'a batch is undone for every product, not from one product\'s view');
        $this->assertSame([$productId], \array_unique(\array_map('intval', \array_column($data['admin']['manualChanges'], 'product_id'))));
    }

    public function test_the_page_clears_the_stashed_query_and_without_a_filter_shows_everything(): void
    {
        $data = $this->page();

        $this->assertSame(0, $data['admin']['filterProductId']);
        $this->assertNull($data['admin']['filterProductName']);
    }

    public function test_a_filter_on_a_product_that_does_not_exist_has_no_name_and_no_history(): void
    {
        $data = $this->page(['product_id' => '63998']);

        $this->assertNull($data['admin']['filterProductName']);
        $this->assertSame([], $data['admin']['importBatches']);
        $this->assertSame([], $data['admin']['manualChanges']);
        $this->assertFalse($data['admin']['hasHistory']);
    }
}
