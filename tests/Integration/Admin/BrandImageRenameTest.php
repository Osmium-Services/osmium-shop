<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Services\BrandImageRename;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/admin/models/ListRows.php';
require_once 'app/services/osmium-shop/models/Brand.php';
require_once 'app/services/osmium-shop/models/ShopPaths.php';
require_once 'app/services/osmium-shop/models/ImageUploadLog.php';
require_once 'app/services/osmium-shop/models/BrandImageRename.php';

/**
 * Renaming a brand moves its product images (folder, files, description-image paths) or changes nothing at all.
 * Real database rows (rolled back) and a throwaway document root.
 */
class BrandImageRenameTest extends ShopDatabaseTestCase
{
    private Brand $brands;
    private string $root;
    private int $brandId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/osmium_brandrename_test_' . \uniqid();
        \mkdir($this->root . '/assets/images/shop', 0755, true);
        $this->brands = new Brand($this->db, 'productbrands');
        $this->brandId = $this->brands->insert('Zq Acme', null, null);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        \rmdir($path);
    }

    private function shop(string $relative): string
    {
        return "{$this->root}/assets/images/shop/{$relative}";
    }

    private function place(string $brand, string $model): void
    {
        foreach (['large/' . "{$brand}-{$model}.jpg", 'thumb/webp/' . "{$brand}-{$model}@2x.webp", 'notes.txt'] as $file) {
            @\mkdir(\dirname($this->shop("{$brand}/{$model}/{$file}")), 0755, true);
            \file_put_contents($this->shop("{$brand}/{$model}/{$file}"), 'x');
        }
    }

    private function rename(string $old, string $new, ?callable $save = null, bool &$saved = false): ?string
    {
        return BrandImageRename::run($this->brands, $this->brandId, $old, $new, $save ?? function () use ($new, &$saved): void {
            $this->brands->update($this->brandId, $new, null, null);
            $saved = true;
        }, $this->root);
    }

    private function descr(int $productId): array
    {
        $table = $this->db->tablePrefix() . 'products';
        return $this->db->prepare(sql: "SELECT descr_image, descr2_image, descr3_image FROM {$table} WHERE id = :id", bindings: [':id' => $productId])->single();
    }

    private function setDescr(int $productId, string $html): void
    {
        $table = $this->db->tablePrefix() . 'products';
        $this->db->prepare(sql: "UPDATE {$table} SET descr_image = :h WHERE id = :id", bindings: [':h' => $html, ':id' => $productId])->execute();
    }

    public function test_rename_moves_folder_files_and_description_paths(): void
    {
        $this->place('zq-acme', 'widget');
        $id = $this->createTestProduct(['brand_id' => $this->brandId]);
        $this->setDescr($id, '<img src="/assets/images/shop/zq-acme/widget/large/zq-acme-widget.jpg" alt="x">');

        $error = $this->rename('Zq Acme', 'Zq Bolt');

        $this->assertNull($error);
        $this->assertDirectoryDoesNotExist($this->shop('zq-acme'));
        $this->assertFileExists($this->shop('zq-bolt/widget/large/zq-bolt-widget.jpg'));
        $this->assertFileExists($this->shop('zq-bolt/widget/thumb/webp/zq-bolt-widget@2x.webp'));
        $this->assertFileExists($this->shop('zq-bolt/widget/notes.txt'), 'files not named after the brand are kept');
        $this->assertSame('<img src="/assets/images/shop/zq-bolt/widget/large/zq-bolt-widget.jpg" alt="x">', $this->descr($id)['descr_image']);
        $this->assertSame('Zq Bolt', $this->brands->getById($this->brandId)['name']);
    }

    public function test_a_name_with_the_same_folder_just_saves(): void
    {
        $this->place('zq-acme', 'widget');

        $saved = false;
        $this->assertNull($this->rename('Zq Acme', 'ZQ ACME', null, $saved));

        $this->assertTrue($saved);
        $this->assertFileExists($this->shop('zq-acme/widget/large/zq-acme-widget.jpg'));
    }

    public function test_a_brand_with_no_images_just_saves(): void
    {
        $saved = false;
        $this->assertNull($this->rename('Zq Acme', 'Zq Bolt', null, $saved));
        $this->assertTrue($saved);
    }

    public function test_an_existing_target_folder_refuses_and_changes_nothing(): void
    {
        $this->place('zq-acme', 'widget');
        \mkdir($this->shop('zq-bolt'));

        $saved = false;
        $error = $this->rename('Zq Acme', 'Zq Bolt', null, $saved);

        $this->assertStringContainsString('already exists', (string) $error);
        $this->assertFalse($saved);
        $this->assertFileExists($this->shop('zq-acme/widget/large/zq-acme-widget.jpg'));
        $this->assertSame('Zq Acme', $this->brands->getById($this->brandId)['name']);
    }

    public function test_a_failing_save_puts_the_images_and_descriptions_back(): void
    {
        $this->place('zq-acme', 'widget');
        $id = $this->createTestProduct(['brand_id' => $this->brandId]);
        $html = '<img src="/assets/images/shop/zq-acme/widget/large/zq-acme-widget.jpg">';
        $this->setDescr($id, $html);

        $error = $this->rename('Zq Acme', 'Zq Bolt', function (): void {
            throw new \RuntimeException('database went away');
        });

        $this->assertStringContainsString('nothing was changed', (string) $error);
        $this->assertFileExists($this->shop('zq-acme/widget/large/zq-acme-widget.jpg'));
        $this->assertFileExists($this->shop('zq-acme/widget/thumb/webp/zq-acme-widget@2x.webp'));
        $this->assertDirectoryDoesNotExist($this->shop('zq-bolt'));
        $this->assertSame($html, $this->descr($id)['descr_image']);
    }

    public function test_a_name_with_no_usable_letters_is_refused(): void
    {
        $this->place('zq-acme', 'widget');

        $this->assertStringContainsString('no letters or numbers', (string) $this->rename('Zq Acme', '***'));
        $this->assertFileExists($this->shop('zq-acme/widget/large/zq-acme-widget.jpg'));
    }
}
