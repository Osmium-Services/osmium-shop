<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Password;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Osmium\Modules\Admin\Services\ProductPriceCsv;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/services/osmium-shop/models/Product.php';
require_once 'app/services/osmium-shop/models/ProductOptionValue.php';
require_once 'app/services/osmium-shop/models/ProductPriceHistory.php';
require_once 'app/services/osmium-shop/models/ProductPriceCsv.php';

/**
 * The price list CSV: what the export writes (products, their options, the byte-order mark Excel needs) and what
 * an import changes, skips and refuses, row by row. Money moves here, so each outcome is pinned: the new price,
 * the history row that makes it undoable, and the rows that must be left alone. Real models, rolled back.
 */
class ProductPriceCsvTest extends DatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private OsmiumAdminModel $model;
    private ProductPriceCsv $csv;
    private string $runTag;
    private array $tempFiles = [];
    private int $brandId;

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['admin_user_id' => 7];
        $this->runTag = \strtolower('zq' . \substr(\uniqid(), -6));
        $this->tempFiles = [];

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $wanted = [];
        $this->model = new OsmiumAdminModel($this->db, (object) \array_intersect_key((array) $all, \array_flip($wanted)));
        $this->model->addModel($this->db, 'product', \Osmium\Modules\Admin\Models\Product::class, 'products'); // The shop service's model
        $this->model->addModel($this->db, 'productOptionValue', \Osmium\Modules\Admin\Models\ProductOptionValue::class, 'productoptionvalues'); // The shop service's model
        $this->model->addModel($this->db, 'productPriceHistory', \Osmium\Modules\Admin\Models\ProductPriceHistory::class, 'product_price_history'); // The shop service's model

        $auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new Password(),
        ) extends AdminAuth {};

        $admin = new class ($this->model, $auth) extends OsmiumAdmin {
            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
            }
        };
        $this->csv = new ProductPriceCsv($admin);

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "Brand {$this->runTag}"]);
        $this->brandId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (\file_exists($file)) \unlink($file);
        }
        $_SESSION = [];
        parent::tearDown();
    }

    private function product(array $overrides = []): int
    {
        $weight = $overrides['packed_weight'] ?? 0;
        unset($overrides['packed_weight']);
        $id = $this->createTestProduct($overrides + ['brand_id' => $this->brandId, 'model_name' => "Model {$this->runTag} " . \uniqid(), 'price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00]);
        $this->db->prepare(
            sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET packed_weight = :w WHERE id = :id',
            bindings: [':w' => $weight, ':id' => $id],
        )->execute();

        return $id;
    }

    private function option(int $productId, array $values = []): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->insert(tableName: "{$prefix}productoptiontypes", data: ['product_id' => $productId, 'type_label' => 'Colour', 'type_slug' => "colour-{$this->runTag}"]);
        $typeId = (int) $this->db->lastInsertId();
        $this->db->insert(tableName: "{$prefix}productoptionvalues", data: $values + [
            'option_type_id' => $typeId, 'option_descr' => 'Red', 'option_reference' => "OPT-{$this->runTag}",
            'option_premium' => 10.00, 'option_rrp' => 15.00, 'option_packed_weight' => 1.50,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function productRow(int $id): array
    {
        return $this->db->prepare(sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'products WHERE id = :id', bindings: [':id' => $id])->single();
    }

    private function optionRow(int $id): array
    {
        return $this->db->prepare(sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'productoptionvalues WHERE id = :id', bindings: [':id' => $id])->single();
    }

    private function history(int $productId): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'product_price_history WHERE product_id = :id ORDER BY id',
            bindings: [':id' => $productId],
        )->resultset();
    }

    /** @return array<int, array<int, string|null>> the export's rows, without the byte-order mark, header included */
    private function exportRows(): array
    {
        $stream = \fopen('php://memory', 'w+');
        $this->csv->export($stream);
        \rewind($stream);
        $raw = (string) \stream_get_contents($stream);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw, 'Excel needs the byte-order mark to read pound signs and accents');
        \rewind($stream);
        \fread($stream, 3);
        $rows = [];
        while (($row = \fgetcsv($stream, escape: '\\')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** Runs the import over CSV text as an uploaded file */
    private function import(string $csv, string $name = 'prices.csv', string $type = 'text/csv', int $error = UPLOAD_ERR_OK): array
    {
        $path = \tempnam(\sys_get_temp_dir(), 'osmium-csv');
        $this->tempFiles[] = $path;
        \file_put_contents($path, $csv);

        return $this->csv->import(['name' => $name, 'type' => $type, 'error' => $error, 'tmp_name' => $path]);
    }

    private const HEADER = "Type,ID,Brand,Model,Reference,Option Type,Option Description,Price (ex VAT),RRP (ex VAT),Status,Weight (kg)\n";

    // ---- export -------------------------------------------------------------------

    public function test_the_export_lists_each_product_followed_by_its_options(): void
    {
        $productId = $this->product(['reference' => "REF-{$this->runTag}", 'price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00, 'packed_weight' => 2.5]);
        $optionId = $this->option($productId);

        $rows = $this->exportRows();

        $this->assertSame(['Type', 'ID', 'Brand', 'Model', 'Reference', 'Option Type', 'Option Description', 'Price (ex VAT)', 'RRP (ex VAT)', 'Status', 'Weight (kg)'], $rows[0]);
        $at = null;
        foreach ($rows as $i => $row) {
            if ($row[0] === 'Product' && (int) $row[1] === $productId) $at = $i;
        }
        $this->assertNotNull($at, 'the product is in the export');
        $product = $rows[$at];
        $this->assertSame("Brand {$this->runTag}", $product[2]);
        $this->assertSame("REF-{$this->runTag}", $product[4]);
        $this->assertSame('', $product[5]);
        $this->assertEqualsWithDelta(100.0, (float) $product[7], 0.0001);
        $this->assertEqualsWithDelta(120.0, (float) $product[8], 0.0001);
        $this->assertEqualsWithDelta(2.5, (float) $product[10], 0.0001);
        $option = $rows[$at + 1];
        $this->assertSame('Option', $option[0]);
        $this->assertSame((string) $optionId, $option[1], 'an option row carries the option id, not the product id');
        $this->assertSame("OPT-{$this->runTag}", $option[4]);
        $this->assertSame(['Colour', 'Red'], [$option[5], $option[6]]);
        $this->assertEqualsWithDelta(10.0, (float) $option[7], 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $option[8], 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $option[10], 0.0001);
    }

    public function test_the_export_leaves_out_binned_products_and_their_options(): void
    {
        $binned = $this->product();
        $this->option($binned);
        $this->db->prepare(sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id', bindings: [':id' => $binned])->execute();
        $kept = $this->product();
        $binnedOption = $this->option($kept, ['option_descr' => 'Binned option', 'deleted_at' => '2026-01-01 00:00:00']);

        $rows = $this->exportRows();

        $this->assertNotContains((string) $binned, \array_column(\array_filter($rows, fn ($r) => $r[0] === 'Product'), 1));
        $this->assertNotContains((string) $binnedOption, \array_column(\array_filter($rows, fn ($r) => $r[0] === 'Option'), 1));
        $this->assertContains((string) $kept, \array_column(\array_filter($rows, fn ($r) => $r[0] === 'Product'), 1));
    }

    public function test_an_exported_file_imports_back_as_no_change(): void
    {
        $productId = $this->product(['price_exc_tax' => 64.50, 'rrp_exc_tax' => 80.00, 'packed_weight' => 3.0]);
        $this->option($productId);
        $stream = \fopen('php://memory', 'w+');
        $this->csv->export($stream);
        \rewind($stream);

        $result = $this->import((string) \stream_get_contents($stream));

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->history($productId), 'nothing to undo when nothing changed');
        $this->assertEqualsWithDelta(64.5, (float) $this->productRow($productId)['price_exc_tax'], 0.0001);
    }

    // ---- import: the file ---------------------------------------------------------

    public function test_a_failed_upload_or_a_file_that_is_not_a_csv_is_refused(): void
    {
        $this->assertSame(['error' => 'File upload failed'], $this->import('x', error: UPLOAD_ERR_NO_FILE));
        $this->assertSame(['error' => 'Please upload a CSV file'], $this->import('x', name: 'prices.xlsx', type: 'application/vnd.ms-excel'));
    }

    public function test_a_csv_is_accepted_by_its_mime_type_or_by_its_name(): void
    {
        $body = self::HEADER;

        $this->assertTrue($this->import($body, name: 'export', type: 'text/csv')['success'], 'mime only');
        $this->assertTrue($this->import($body, name: 'prices.csv', type: 'application/octet-stream')['success'], 'name only (Windows sends odd types)');
    }

    public function test_an_empty_file_or_one_without_the_columns_it_needs_is_refused(): void
    {
        $this->assertSame(['error' => 'Empty file'], $this->import(''));
        $this->assertSame(['error' => 'CSV must have ID and Price (ex VAT) columns'], $this->import("ID,Name\n5,Thing\n"), 'no price column');
        $this->assertSame(['error' => 'Could not find header row'], $this->import("Just a title\n"), 'a title row and nothing after');
        $this->assertSame(['error' => 'CSV must have ID and Price (ex VAT) columns'], $this->import("Just a title\nName,Price (ex VAT)\nx,1\n"), 'a header with no ID column');
    }

    public function test_the_header_may_sit_under_a_title_row_and_may_start_with_a_byte_order_mark(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00]);

        $withBom = $this->import("\xEF\xBB\xBFID,Price (ex VAT)\n{$productId},110.00\n");
        $this->assertSame(1, $withBom['productsUpdated'], 'a BOM must not hide the ID column');

        $underTitle = $this->import("Price list for Tuesday\nID,Price (ex VAT)\n{$productId},120.00\n");
        $this->assertSame(1, $underTitle['productsUpdated']);
        $this->assertEqualsWithDelta(120.0, (float) $this->productRow($productId)['price_exc_tax'], 0.0001);
    }

    // ---- import: products ---------------------------------------------------------

    public function test_a_changed_price_is_saved_and_kept_in_the_history_so_it_can_be_undone(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00]);

        $result = $this->import(self::HEADER . "Product,{$productId},,,,,,89.99,,,\n");

        $this->assertSame(['success' => true, 'productsUpdated' => 1, 'optionsUpdated' => 0, 'skipped' => 0, 'errors' => []], $result);
        $this->assertEqualsWithDelta(89.99, (float) $this->productRow($productId)['price_exc_tax'], 0.0001);
        $history = $this->history($productId);
        $this->assertCount(1, $history);
        $this->assertSame('import', $history[0]['change_source']);
        $this->assertSame(7, (int) $history[0]['changed_by'], 'the signed-in user');
        $this->assertEqualsWithDelta(100.0, (float) $history[0]['price_exc_tax_old'], 0.0001);
        $this->assertEqualsWithDelta(89.99, (float) $history[0]['price_exc_tax_new'], 0.0001);
        $this->assertEqualsWithDelta(120.0, (float) $history[0]['rrp_exc_tax_old'], 0.0001);
        $this->assertNull($history[0]['undone_at']);
    }

    public function test_the_rrp_and_weight_are_updated_when_the_file_gives_them(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00, 'packed_weight' => 2.0]);

        $this->import(self::HEADER . "Product,{$productId},,,,,,100.00,150.00,,4.25\n");

        $row = $this->productRow($productId);
        $this->assertEqualsWithDelta(100.0, (float) $row['price_exc_tax'], 0.0001);
        $this->assertEqualsWithDelta(150.0, (float) $row['rrp_exc_tax'], 0.0001, 'an RRP-only change still counts');
        $this->assertEqualsWithDelta(4.25, (float) $row['packed_weight'], 0.0001);
        $this->assertCount(1, $this->history($productId));
    }

    public function test_a_change_to_only_the_rrp_or_only_the_weight_is_still_a_change(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00, 'packed_weight' => 2.0]);

        $rrpOnly = $this->import(self::HEADER . "Product,{$productId},,,,,,100.00,150.00,,2.00\n");
        $this->assertSame(1, $rrpOnly['productsUpdated']);
        $this->assertEqualsWithDelta(150.0, (float) $this->productRow($productId)['rrp_exc_tax'], 0.0001);

        $weightOnly = $this->import(self::HEADER . "Product,{$productId},,,,,,100.00,150.00,,5.00\n");
        $this->assertSame(1, $weightOnly['productsUpdated']);
        $this->assertEqualsWithDelta(5.0, (float) $this->productRow($productId)['packed_weight'], 0.0001);
    }

    public function test_a_blank_or_non_numeric_rrp_or_weight_keeps_what_is_there(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00, 'packed_weight' => 2.0]);

        $this->import(self::HEADER . "Product,{$productId},,,,,,90.00,,,\n");
        $row = $this->productRow($productId);
        $this->assertEqualsWithDelta(120.0, (float) $row['rrp_exc_tax'], 0.0001, 'blank RRP');
        $this->assertEqualsWithDelta(2.0, (float) $row['packed_weight'], 0.0001, 'blank weight');

        $this->import(self::HEADER . "Product,{$productId},,,,,,80.00,POA,,heavy\n");
        $row = $this->productRow($productId);
        $this->assertEqualsWithDelta(80.0, (float) $row['price_exc_tax'], 0.0001);
        $this->assertEqualsWithDelta(120.0, (float) $row['rrp_exc_tax'], 0.0001, 'text in the RRP column');
        $this->assertEqualsWithDelta(2.0, (float) $row['packed_weight'], 0.0001, 'text in the weight column');
    }

    public function test_a_file_without_rrp_or_weight_columns_changes_only_the_price(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00, 'packed_weight' => 2.0]);

        $result = $this->import("ID,Price (ex VAT)\n{$productId},95.00\n");

        $this->assertSame(1, $result['productsUpdated'], 'a row with no Type column is a product');
        $row = $this->productRow($productId);
        $this->assertEqualsWithDelta(95.0, (float) $row['price_exc_tax'], 0.0001);
        $this->assertEqualsWithDelta(120.0, (float) $row['rrp_exc_tax'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $row['packed_weight'], 0.0001);
    }

    public function test_a_row_that_changes_nothing_is_skipped_and_leaves_no_history(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00, 'rrp_exc_tax' => 120.00]);

        $result = $this->import(self::HEADER . "Product,{$productId},,,,,,100.0004,120.00,,\n");

        $this->assertSame(1, $result['skipped'], 'a price under a tenth of a penny away is not a change');
        $this->assertSame(0, $result['productsUpdated']);
        $this->assertSame([], $this->history($productId));
    }

    public function test_a_blank_or_zero_price_is_skipped_so_a_product_is_never_set_to_nothing(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00]);

        $result = $this->import(self::HEADER . "Product,{$productId},,,,,,,,,\nProduct,{$productId},,,,,,0,,,\nProduct,{$productId},,,,,,0.00,,,\n");

        $this->assertSame(3, $result['skipped']);
        $this->assertEqualsWithDelta(100.0, (float) $this->productRow($productId)['price_exc_tax'], 0.0001);
        $this->assertSame([], $this->history($productId));
    }

    public function test_bad_rows_are_reported_with_their_line_number_and_do_not_stop_the_rest(): void
    {
        $good = $this->product(['price_exc_tax' => 100.00]);
        $binned = $this->product();
        $this->db->prepare(sql: 'UPDATE ' . $this->db->tablePrefix() . 'products SET deleted_at = NOW() WHERE id = :id', bindings: [':id' => $binned])->execute();

        $result = $this->import(self::HEADER
            . "Product,abc,,,,,,10.00,,,\n"                  // row 2: no usable ID
            . "Product,{$good},,,,,,ten pounds,,,\n"          // row 3: price is not a number
            . "Product,{$binned},,,,,,10.00,,,\n"             // row 4: product is in the bin
            . "Product,65000,,,,,,10.00,,,\n"                 // row 5: no such product
            . "Product,{$good},,,,,,75.00,,,\n");             // row 6: fine

        $this->assertSame([
            'Row 2: Invalid ID',
            "Row 3: Invalid price 'ten pounds'",
            "Row 4: Product ID {$binned} not found",
            'Row 5: Product ID 65000 not found',
        ], $result['errors']);
        $this->assertSame(1, $result['productsUpdated']);
        $this->assertEqualsWithDelta(75.0, (float) $this->productRow($good)['price_exc_tax'], 0.0001);
        $this->assertSame([], $this->history($binned));
    }

    public function test_the_row_number_counts_from_the_header_it_found(): void
    {
        $result = $this->import("A title\nID,Price (ex VAT)\n0,10\n");

        $this->assertSame(['Row 2: Invalid ID'], $result['errors'], 'counted from the header row, as the first line after it');
    }

    // ---- import: options ----------------------------------------------------------

    public function test_an_option_row_updates_the_option_and_not_the_product_with_the_same_number(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00]);
        $optionId = $this->option($productId, ['option_premium' => 10.00, 'option_rrp' => 15.00, 'option_packed_weight' => 1.50]);

        $result = $this->import(self::HEADER . "Option,{$optionId},,,,Colour,Red,12.50,18.00,,2.00\n");

        $this->assertSame(1, $result['optionsUpdated']);
        $this->assertSame(0, $result['productsUpdated']);
        $option = $this->optionRow($optionId);
        $this->assertEqualsWithDelta(12.5, (float) $option['option_premium'], 0.0001);
        $this->assertEqualsWithDelta(18.0, (float) $option['option_rrp'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $option['option_packed_weight'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $this->productRow($productId)['price_exc_tax'], 0.0001);
    }

    public function test_an_option_can_be_set_to_a_zero_premium(): void
    {
        $optionId = $this->option($this->product(), ['option_premium' => 10.00]);

        $result = $this->import(self::HEADER . "Option,{$optionId},,,,,,0,,,\n");

        $this->assertSame(1, $result['optionsUpdated'], 'unlike a product, an option with no extra cost is normal');
        $this->assertEqualsWithDelta(0.0, (float) $this->optionRow($optionId)['option_premium'], 0.0001);
    }

    public function test_an_option_row_that_changes_nothing_or_names_no_option_is_dealt_with(): void
    {
        $optionId = $this->option($this->product(), ['option_premium' => 10.00, 'option_rrp' => 15.00, 'option_packed_weight' => 1.50]);
        $binnedId = $this->option($this->product(), ['deleted_at' => '2026-01-01 00:00:00']);

        $result = $this->import(self::HEADER
            . "Option,{$optionId},,,,,,10.00,15.00,,1.50\n"
            . "Option,{$binnedId},,,,,,10.00,,,\n"
            . "Option,4000000,,,,,,10.00,,,\n");

        $this->assertSame(1, $result['skipped']);
        $this->assertSame([
            "Row 3: Option ID {$binnedId} not found",
            'Row 4: Option ID 4000000 not found',
        ], $result['errors']);
    }

    public function test_a_mixed_file_counts_each_kind_of_row(): void
    {
        $productId = $this->product(['price_exc_tax' => 100.00]);
        $optionId = $this->option($productId, ['option_premium' => 10.00]);

        $result = $this->import(self::HEADER
            . "Product,{$productId},,,,,,110.00,,,\n"
            . "Option,{$optionId},,,,,,12.00,,,\n"
            . "Product,{$productId},,,,,,,,,\n"
            . "Product,0,,,,,,5,,,\n");

        $this->assertSame(['success' => true, 'productsUpdated' => 1, 'optionsUpdated' => 1, 'skipped' => 1, 'errors' => ['Row 5: Invalid ID']], $result);
    }
}
