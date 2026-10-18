<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminShop;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/AdminShop.php';

/**
 * AdminShop's product documents (PDF validation, delete and lookup under a
 * throwaway document root) and the three product state changes (soft delete,
 * restore, discontinue). duplicate() and deleteCompletely() are not covered
 * here: both rewrite the live app/config/routes.php through code a test can't
 * redirect. A real upload's final move can't run either, because PHP only
 * moves files it received over HTTP.
 */
class AdminShopFilesAndStateTest extends DatabaseTestCase
{
    private AdminShop $shop;
    private Product $products;
    private string $docRoot;
    private array $savedServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedServer = $_SERVER;
        $this->docRoot = \sys_get_temp_dir() . '/osmium_shop_files_test_' . \uniqid();
        \mkdir($this->docRoot);
        $_SERVER['DOCUMENT_ROOT'] = $this->docRoot;

        $this->products = new Product($this->db, 'products');
        $this->shop = new AdminShop(
            $this->products,
            new Brand($this->db, 'brands'),
            new ProductOptionType($this->db, 'productoptiontypes'),
            new ProductOptionValue($this->db, 'productoptionvalues'),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->docRoot);
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($path);
    }

    private function placePdf(string $brand, string $model, string $type): string
    {
        $dir = "{$this->docRoot}/assets/images/shop/{$brand}/{$model}/{$type}";
        \mkdir($dir, recursive: true);
        \file_put_contents("{$dir}/{$brand}-{$model}-{$type}.pdf", '%PDF-1.4');

        return "{$dir}/{$brand}-{$model}-{$type}.pdf";
    }

    /** A real file on disk to stand in for an upload's temp file. */
    private function upload(string $contents, ?int $size = null, int $error = UPLOAD_ERR_OK): array
    {
        $path = "{$this->docRoot}/upload.tmp";
        \file_put_contents($path, $contents);

        return ['error' => $error, 'tmp_name' => $path, 'size' => $size ?? \strlen($contents)];
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return '(no exception)';
    }

    // ---- uploading ----------------------------------------------------------------

    public function test_an_upload_that_did_not_arrive_is_refused_with_the_reason(): void
    {
        $cases = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        ];

        foreach ($cases as $code => $message) {
            $this->assertSame($message, $this->refusal(fn () => $this->shop->uploadPdf($this->upload('x', error: $code), 'brochure', 'acme', 'widget')));
        }
        $this->assertSame('No file was uploaded', $this->refusal(fn () => $this->shop->uploadPdf([], 'brochure', 'acme', 'widget')));
    }

    public function test_only_the_three_document_types_are_accepted(): void
    {
        $message = $this->refusal(fn () => $this->shop->uploadPdf($this->upload('%PDF-1.4'), 'manual', 'acme', 'widget'));

        $this->assertSame('Invalid PDF type', $message);
    }

    public function test_both_paths_are_required(): void
    {
        $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->shop->uploadPdf($this->upload('%PDF-1.4'), 'brochure', '', 'widget')));
        $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->shop->uploadPdf($this->upload('%PDF-1.4'), 'brochure', 'acme', '')));
    }

    public function test_only_a_real_pdf_is_accepted_whatever_it_is_called(): void
    {
        $message = $this->refusal(fn () => $this->shop->uploadPdf($this->upload('<?php echo 1; ?>'), 'brochure', 'acme', 'widget'));

        $this->assertSame('Invalid file type. Only PDF files allowed', $message);
        $this->assertDirectoryDoesNotExist("{$this->docRoot}/assets");
    }

    public function test_a_pdf_over_ten_megabytes_is_refused(): void
    {
        $message = $this->refusal(fn () => $this->shop->uploadPdf($this->upload('%PDF-1.4', size: 10 * 1024 * 1024 + 1), 'brochure', 'acme', 'widget'));

        $this->assertSame('File too large. Maximum 10MB allowed', $message);
    }

    // ---- listing and deleting -----------------------------------------------------

    public function test_existing_documents_are_listed_by_type(): void
    {
        $this->placePdf('acme', 'widget', 'brochure');

        $paths = $this->shop->getPdfPaths('acme', 'widget');

        $this->assertSame('/assets/images/shop/acme/widget/brochure/acme-widget-brochure.pdf', $paths['brochure']);
        $this->assertNull($paths['datasheet']);
        $this->assertNull($paths['instructions']);
    }

    public function test_a_path_with_slashes_or_html_entities_is_refused(): void
    {
        $this->placePdf('acme-co', 'big-widget', 'datasheet');

        foreach (['acme/co', 'acme&#x2F;co', '..'] as $brand) {
            try {
                $this->shop->getPdfPaths($brand, 'big-widget');
                $this->fail("{$brand} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_deleting_a_document_removes_only_that_one_and_its_empty_folder(): void
    {
        $brochure = $this->placePdf('acme', 'widget', 'brochure');
        $datasheet = $this->placePdf('acme', 'widget', 'datasheet');

        $this->shop->deletePdf('brochure', 'acme', 'widget');

        $this->assertFileDoesNotExist($brochure);
        $this->assertDirectoryDoesNotExist(\dirname($brochure));
        $this->assertFileExists($datasheet);
    }

    public function test_deleting_a_document_that_is_not_there_is_not_an_error(): void
    {
        $this->shop->deletePdf('brochure', 'acme', 'widget');

        $this->assertDirectoryDoesNotExist("{$this->docRoot}/assets");
    }

    public function test_deleting_validates_the_type_and_paths(): void
    {
        $this->assertSame('Invalid PDF type', $this->refusal(fn () => $this->shop->deletePdf('manual', 'acme', 'widget')));
        $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->shop->deletePdf('brochure', '', 'widget')));
    }

    // ---- product state ------------------------------------------------------------

    public function test_soft_delete_hides_the_product_and_restore_brings_it_back(): void
    {
        $id = $this->createTestProduct(['status_id' => 1]);

        $this->shop->softDelete($id);
        $this->assertNotNull($this->storedProduct($id)['deleted_at']);
        $this->assertSame(4, (int) $this->storedProduct($id)['status_id'], 'a deleted product is also marked unavailable');

        $this->shop->restore($id);
        $this->assertNull($this->storedProduct($id)['deleted_at']);
        $this->assertSame(4, (int) $this->storedProduct($id)['status_id'], 'restoring does not guess a status: the admin is told to check it');
    }

    public function test_discontinue_sets_the_discontinued_status_and_keeps_the_product(): void
    {
        $id = $this->createTestProduct(['status_id' => 1]);

        $this->shop->discontinue($id);

        $this->assertSame(3, (int) $this->storedProduct($id)['status_id']);
        $this->assertNull($this->storedProduct($id)['deleted_at']);
    }

    private function storedProduct(int $id): array
    {
        $table = $this->db->tablePrefix() . 'products';

        return $this->db->prepare(sql: "SELECT * FROM {$table} WHERE id = :id", bindings: [':id' => $id])->single();
    }
}
