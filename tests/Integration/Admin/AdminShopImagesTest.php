<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Services\AdminShopImages;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/services/osmium-shop/models/Product.php';
require_once 'app/services/osmium-shop/models/AdminShopImages.php';

/**
 * Product images through AdminShopImages, for real: uploads are checked and turned into variants by the real
 * ImageProcessor and real GD under a throwaway document root; the product's description-image fields are real
 * database rows (rolled back). The processor's debug log (app/logs/image-upload.log) is put back to the length it
 * had before each test.
 */
class AdminShopImagesTest extends ShopDatabaseTestCase
{
    private AdminShopImages $images;
    private string $root;
    private array $savedServer;
    private string $logFile;
    private int $logSize;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedServer = $_SERVER;
        $this->root = \sys_get_temp_dir() . '/osmium_shopimages_test_' . \uniqid();
        \mkdir($this->root);
        $_SERVER['DOCUMENT_ROOT'] = $this->root;
        $this->images = new AdminShopImages(new Product($this->db, 'products'));

        $this->logFile = \dirname(__DIR__, 3) . '/app/logs/image-upload.log';
        $this->logSize = \is_file($this->logFile) ? (int) \filesize($this->logFile) : -1;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $_SERVER = $this->savedServer;

        if ($this->logSize === -1) {
            @\unlink($this->logFile);
        } elseif (\is_file($this->logFile)) {
            $handle = \fopen($this->logFile, 'r+');
            \ftruncate($handle, $this->logSize);
            \fclose($handle);
        }
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

    private function shop(string $relative = ''): string
    {
        return "{$this->root}/assets/images/shop/{$relative}";
    }

    /** A real picture on disk, standing in for an upload's temp file. */
    private function upload(int $width = 900, int $height = 900, string $format = 'png', int $error = UPLOAD_ERR_OK): array
    {
        $image = \imagecreatetruecolor($width, $height);
        $path = "{$this->root}/upload.{$format}";
        $format === 'png' ? \imagepng($image, $path) : \imagegif($image, $path);

        return ['name' => "pic.{$format}", 'type' => "image/{$format}", 'tmp_name' => $path, 'error' => $error, 'size' => (int) \filesize($path)];
    }

    /** Variant files for one slot, the way the processor lays them out, without paying for the real encoding. */
    private function placeVariants(string $brand, string $model, string $suffix = ''): int
    {
        $count = 0;
        foreach (['thumb', 'medium', 'large', 'extra'] as $size) {
            foreach (['' => 'jpg', '/webp' => 'webp', '/avif' => 'avif'] as $folder => $ext) {
                foreach (['', '@2x'] as $density) {
                    $dir = $this->shop("{$brand}/{$model}/{$size}{$folder}");
                    @\mkdir($dir, 0755, true);
                    \file_put_contents("{$dir}/{$brand}-{$model}{$suffix}{$density}.{$ext}", 'x');
                    $count++;
                }
            }
        }

        return $count;
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

    private function descrFields(int $id): array
    {
        $table = $this->db->tablePrefix() . 'products';

        return $this->db->prepare(sql: "SELECT descr_image, descr2_image, descr3_image FROM {$table} WHERE id = :id", bindings: [':id' => $id])->single();
    }

    // ---- upload ----------------------------------------------------------------------

    public function test_a_900_pixel_photo_becomes_the_main_image_with_all_its_variants(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->upload($id, $this->upload(), ['brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame('main', $result['slot']);
        $this->assertSame('/shop/acme/widget/large/acme-widget.jpg', $result['imagePath']);
        $this->assertSame([true, true, true], [$result['derivations']['large'], $result['derivations']['medium'], $result['derivations']['thumb']]);
        $this->assertFileExists($this->shop('acme/widget/large/acme-widget.jpg'));
        $this->assertFileExists($this->shop('acme/widget/thumb/acme-widget@2x.jpg'));
        $this->assertFileExists($this->shop('acme/widget/medium/webp/acme-widget.webp'));
        $this->assertSame([null, null, null], \array_values($this->descrFields($id)), 'a main image touches no description field');
    }

    public function test_the_main_upload_reports_the_webp_and_avif_it_made(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->upload($id, $this->upload(), ['brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertTrue($result['derivations']['webp']);
        $this->assertSame(\function_exists('imageavif'), $result['derivations']['avif']);
    }

    public function test_an_extra_slot_gets_its_own_file_names_and_no_derivation_report(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->upload($id, $this->upload(), ['slot' => 'extra2', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame(['slot' => 'extra2', 'imagePath' => '/shop/acme/widget/large/acme-widget-extra2.jpg'], $result);
        $this->assertFileExists($this->shop('acme/widget/large/acme-widget-extra2.jpg'));
    }

    public function test_a_description_slot_remembers_the_image_on_the_product(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->upload($id, $this->upload(), ['slot' => 'descr2_image', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame('/shop/acme/widget/large/acme-widget-descr2.jpg', $result['imagePath']);
        $this->assertSame([null, '/shop/acme/widget/large/acme-widget-descr2.jpg', null], \array_values($this->descrFields($id)));
    }

    public function test_the_warranty_slot_is_allowed(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->upload($id, $this->upload(), ['slot' => 'warranty', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame('/shop/acme/widget/large/acme-widget-warranty.jpg', $result['imagePath']);
    }

    public function test_an_unknown_slot_is_refused_before_anything_is_written(): void
    {
        $id = $this->createTestProduct();

        $this->assertSame('Unknown image slot: extra9', $this->refusal(fn () => $this->images->upload($id, $this->upload(), ['slot' => 'extra9', 'brand_path' => 'acme', 'model_path' => 'widget'])));
        $this->assertDirectoryDoesNotExist($this->shop());
    }

    public function test_an_upload_is_refused_for_a_missing_product_a_failed_transfer_or_missing_paths(): void
    {
        $input = ['brand_path' => 'acme', 'model_path' => 'widget'];
        $id = $this->createTestProduct();

        $this->assertSame('Product ID is required', $this->refusal(fn () => $this->images->upload(0, $this->upload(), $input)));
        $this->assertSame('File was only partially uploaded', $this->refusal(fn () => $this->images->upload($id, $this->upload(error: UPLOAD_ERR_PARTIAL), $input)));
        $this->assertSame('File exceeds server upload limit', $this->refusal(fn () => $this->images->upload($id, $this->upload(error: UPLOAD_ERR_INI_SIZE), $input)));
        $this->assertSame('No file was uploaded', $this->refusal(fn () => $this->images->upload($id, [], $input)));
        $this->assertSame('Unknown upload error', $this->refusal(fn () => $this->images->upload($id, $this->upload(error: 99), $input)));
        $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->images->upload($id, $this->upload(), ['brand_path' => 'acme'])));
        $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->images->upload($id, $this->upload(), ['model_path' => 'widget'])));
    }

    public function test_only_jpeg_png_gif_and_webp_files_are_accepted(): void
    {
        $id = $this->createTestProduct();
        $text = "{$this->root}/note.png";
        \file_put_contents($text, 'not a picture');
        $file = ['name' => 'note.png', 'type' => 'image/png', 'tmp_name' => $text, 'error' => UPLOAD_ERR_OK, 'size' => 13];

        $this->assertSame('Invalid file type. Allowed: JPG, PNG, GIF, WebP', $this->refusal(fn () => $this->images->upload($id, $file, ['brand_path' => 'acme', 'model_path' => 'widget'])));
    }

    public function test_a_file_over_ten_megabytes_is_refused(): void
    {
        $id = $this->createTestProduct();
        $file = ['size' => 10 * 1024 * 1024 + 1] + $this->upload();

        $this->assertSame('File too large. Maximum 10MB allowed', $this->refusal(fn () => $this->images->upload($id, $file, ['brand_path' => 'acme', 'model_path' => 'widget'])));
    }

    public function test_the_photo_must_be_exactly_900_by_900(): void
    {
        $id = $this->createTestProduct();
        $input = ['brand_path' => 'acme', 'model_path' => 'widget'];

        $this->assertSame('Image must be 900×900 pixels. Uploaded: 800×900.', $this->refusal(fn () => $this->images->upload($id, $this->upload(800, 900), $input)));
        $this->assertSame('Image must be 900×900 pixels. Uploaded: 900×901.', $this->refusal(fn () => $this->images->upload($id, $this->upload(900, 901), $input)));
        $this->assertSame('Image must be 900×900 pixels. Uploaded: 901×900.', $this->refusal(fn () => $this->images->upload($id, $this->upload(901, 900), $input)));
        $this->assertDirectoryDoesNotExist($this->shop());
    }

    public function test_a_gif_of_the_right_size_is_accepted(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->upload($id, $this->upload(format: 'gif'), ['brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame('/shop/acme/widget/large/acme-widget.jpg', $result['imagePath']);
    }

    public function test_a_processing_failure_comes_back_as_an_error_message(): void
    {
        $id = $this->createTestProduct();
        \mkdir("{$this->root}/assets");
        \file_put_contents("{$this->root}/assets/images", 'a file where the folder should go');

        \set_error_handler(fn () => true);
        try {
            $message = $this->refusal(fn () => $this->images->upload($id, $this->upload(), ['brand_path' => 'acme', 'model_path' => 'widget']));
        } finally {
            \restore_error_handler();
        }

        $this->assertStringStartsWith('Failed to create directory: ', $message);
    }

    // ---- delete ----------------------------------------------------------------------

    public function test_deleting_a_slot_removes_its_variants_and_nothing_else(): void
    {
        $id = $this->createTestProduct();
        $count = $this->placeVariants('acme', 'widget');
        $this->placeVariants('acme', 'widget', '-extra1');

        $result = $this->images->delete($id, ['slot' => 'main', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame(['slot' => 'main', 'deleted' => $count], $result);
        $this->assertFileDoesNotExist($this->shop('acme/widget/large/acme-widget.jpg'));
        $this->assertFileExists($this->shop('acme/widget/large/acme-widget-extra1.jpg'));
    }

    public function test_deleting_a_description_slot_also_empties_its_field(): void
    {
        $id = $this->createTestProduct();
        $this->images->updateDescrImage($id, ['field' => 'descr3_image', 'value' => '/shop/acme/widget/large/acme-widget-descr3.jpg']);
        $this->placeVariants('acme', 'widget', '-descr3');

        $result = $this->images->delete($id, ['slot' => 'descr3_image', 'brand_path' => 'acme', 'model_path' => 'widget']);

        $this->assertSame(24, $result['deleted']);
        $this->assertNull($this->descrFields($id)['descr3_image']);
    }

    public function test_deleting_needs_a_product_a_slot_and_both_paths_and_a_known_slot(): void
    {
        $id = $this->createTestProduct();

        $this->assertSame('Product ID is required', $this->refusal(fn () => $this->images->delete(0, [])));
        $this->assertSame('Slot, brand path and model path are required', $this->refusal(fn () => $this->images->delete($id, ['brand_path' => 'a', 'model_path' => 'b'])));
        $this->assertSame('Slot, brand path and model path are required', $this->refusal(fn () => $this->images->delete($id, ['slot' => 'main', 'model_path' => 'b'])));
        $this->assertSame('Slot, brand path and model path are required', $this->refusal(fn () => $this->images->delete($id, ['slot' => 'main', 'brand_path' => 'a'])));
        $this->assertSame('Unknown image slot: nope', $this->refusal(fn () => $this->images->delete($id, ['slot' => 'nope', 'brand_path' => 'a', 'model_path' => 'b'])));
    }

    // ---- description images ---------------------------------------------------------

    public function test_a_description_image_is_stored_as_an_escaped_img_tag_with_the_product_as_alt_text(): void
    {
        $brand = $this->createBrand('Zq "Quote" & Co');
        $id = $this->createTestProduct(['brand_id' => $brand, 'model_name' => 'Widget <1>']);

        $result = $this->images->updateDescrImage($id, ['field' => 'descr_image', 'value' => '/shop/a/b/large/a-b.jpg']);

        $expected = '<img src="/assets/images/shop/a/b/large/a-b.jpg" alt="Zq &quot;Quote&quot; &amp; Co Widget &lt;1&gt;" loading="lazy">';
        $this->assertSame(['field' => 'descr_image', 'value' => '/shop/a/b/large/a-b.jpg', 'html' => $expected], $result);
        $this->assertSame($expected, $this->descrFields($id)['descr_image']);
    }

    public function test_a_quote_in_the_image_path_cannot_break_out_of_the_img_tag(): void
    {
        $id = $this->createTestProduct();

        $result = $this->images->updateDescrImage($id, ['field' => 'descr_image', 'value' => '/shop/a" onerror="x.jpg']);

        $this->assertStringContainsString('src="/assets/images/shop/a&quot; onerror=&quot;x.jpg"', $result['html']);
        $this->assertStringNotContainsString('onerror="', $result['html']);
    }

    public function test_an_empty_value_clears_the_description_image(): void
    {
        $id = $this->createTestProduct();
        $this->images->updateDescrImage($id, ['field' => 'descr_image', 'value' => '/shop/a/b/large/a-b.jpg']);

        $result = $this->images->updateDescrImage($id, ['field' => 'descr_image', 'value' => '']);

        $this->assertSame(['field' => 'descr_image', 'value' => '', 'html' => null], $result);
        $this->assertNull($this->descrFields($id)['descr_image']);
    }

    public function test_only_the_three_description_fields_can_be_set(): void
    {
        $id = $this->createTestProduct();

        $this->assertSame('Product ID is required', $this->refusal(fn () => $this->images->updateDescrImage(0, ['field' => 'descr_image'])));
        $this->assertSame('Invalid field', $this->refusal(fn () => $this->images->updateDescrImage($id, ['field' => 'model_name', 'value' => 'x'])));
        $this->assertSame('Invalid field', $this->refusal(fn () => $this->images->updateDescrImage($id, [])));
    }

    // ---- listing and regenerating ----------------------------------------------------

    public function test_the_image_list_has_every_slot_and_the_three_description_images(): void
    {
        $id = $this->createTestProduct();
        $this->placeVariants('acme', 'widget');
        $this->images->updateDescrImage($id, ['field' => 'descr2_image', 'value' => '/shop/x.jpg']);
        $table = $this->db->tablePrefix() . 'products';
        $this->db->prepare(sql: "UPDATE {$table} SET descr_image = '', descr3_image = '' WHERE id = :id", bindings: [':id' => $id])->execute();

        $all = $this->images->getAll($id, 'acme', 'widget');

        $this->assertSame('/assets/images/shop/acme/widget/large/acme-widget.jpg', $all['main']);
        $this->assertNull($all['extra1']);
        $this->assertNull($all['descr_image']);
        $this->assertStringContainsString('/assets/images/shop/x.jpg', $all['descr2_image']);
        $this->assertNull($all['descr3_image']);
    }

    public function test_the_image_list_returns_each_description_image_that_is_set(): void
    {
        $id = $this->createTestProduct();
        foreach (['descr_image', 'descr2_image', 'descr3_image'] as $field) {
            $this->images->updateDescrImage($id, ['field' => $field, 'value' => "/shop/{$field}.jpg"]);
        }

        $all = $this->images->getAll($id, 'acme', 'widget');

        foreach (['descr_image', 'descr2_image', 'descr3_image'] as $field) {
            $this->assertStringContainsString("/assets/images/shop/{$field}.jpg", $all[$field], $field);
        }
    }

    public function test_the_image_list_and_regeneration_need_a_product_and_both_paths(): void
    {
        $id = $this->createTestProduct();

        foreach (['getAll', 'regenerate'] as $method) {
            $this->assertSame('Product ID is required', $this->refusal(fn () => $this->images->$method(0, 'a', 'b')), $method);
            $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->images->$method($id, '', 'b')), $method);
            $this->assertSame('Brand and model paths are required', $this->refusal(fn () => $this->images->$method($id, 'a', '')), $method);
        }
    }

    public function test_regenerating_rebuilds_the_small_sizes_from_the_large_image(): void
    {
        $id = $this->createTestProduct();
        $dir = $this->shop('acme/widget/large');
        \mkdir($dir, 0755, true);
        \imagejpeg(\imagecreatetruecolor(300, 300), "{$dir}/acme-widget.jpg");

        $result = $this->images->regenerate($id, 'acme', 'widget');

        $this->assertTrue($result['success']);
        $this->assertFileExists($this->shop('acme/widget/thumb/acme-widget.jpg'));
        $this->assertFileExists($this->shop('acme/widget/medium/acme-widget@2x.jpg'));
    }

    public function test_regenerating_with_no_large_image_says_so(): void
    {
        $id = $this->createTestProduct();

        $this->assertSame(['success' => false, 'error' => 'Large image not found'], $this->images->regenerate($id, 'acme', 'widget'));
    }

    // ---- renaming --------------------------------------------------------------------

    public function test_renaming_a_model_moves_the_folder_and_every_file_inside_it(): void
    {
        $count = $this->placeVariants('acme', 'widget-one');
        $this->placeVariants('acme', 'widget-one', '-extra1');

        $result = $this->images->renameProductImages('Acme', 'Widget One', 'Widget Two');

        $this->assertSame(['success' => true, 'renamed' => $count * 2], $result);
        $this->assertDirectoryDoesNotExist($this->shop('acme/widget-one'));
        $this->assertFileExists($this->shop('acme/widget-two/large/acme-widget-two.jpg'));
        $this->assertFileExists($this->shop('acme/widget-two/thumb/webp/acme-widget-two-extra1@2x.webp'));
        $this->assertFileDoesNotExist($this->shop('acme/widget-two/large/acme-widget-one.jpg'));
    }

    public function test_only_files_named_after_the_old_model_are_renamed(): void
    {
        $this->placeVariants('acme', 'widget-one');
        \file_put_contents($this->shop('acme/widget-one/readme.txt'), 'keep');
        \mkdir($this->shop('acme/widget-one/acme-widget-one-notes'));

        $result = $this->images->renameProductImages('Acme', 'Widget One', 'Widget Two');

        $this->assertSame(24, $result['renamed'], 'the readme and the folder are not counted');
        $this->assertFileExists($this->shop('acme/widget-two/readme.txt'));
        $this->assertDirectoryExists($this->shop('acme/widget-two/acme-widget-one-notes'), 'a folder keeps its name');
    }

    public function test_names_are_turned_into_url_safe_folder_names(): void
    {
        \mkdir($this->shop('v-ctron-energy/model-x'), 0755, true);

        $result = $this->images->renameProductImages("  Víctron &/ Energy ", 'Model X', 'Model/Y?');

        $this->assertSame(['success' => true, 'renamed' => 0], $result);
        $this->assertDirectoryExists($this->shop('v-ctron-energy/modely'));
    }

    public function test_renaming_when_there_are_no_images_yet_is_skipped(): void
    {
        $this->assertSame(['success' => true, 'renamed' => 0, 'skipped' => true], $this->images->renameProductImages('Acme', 'Widget One', 'Widget Two'));
    }

    public function test_renaming_onto_an_existing_folder_is_refused_and_moves_nothing(): void
    {
        $this->placeVariants('acme', 'widget-one');
        \mkdir($this->shop('acme/widget-two'), 0755, true);

        $result = $this->images->renameProductImages('Acme', 'Widget One', 'Widget Two');

        $this->assertSame(['success' => false, 'renamed' => 0, 'error' => 'Target folder already exists: widget-two'], $result);
        $this->assertFileExists($this->shop('acme/widget-one/large/acme-widget-one.jpg'));
    }

    private function createBrand(string $name): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => $name]);

        return (int) $this->db->lastInsertId();
    }
}
