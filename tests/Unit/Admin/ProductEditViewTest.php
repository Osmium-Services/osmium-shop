<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ProductEditView;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ProductEditView.php';

/**
 * ProductEditView prepares the product edit page's display values.
 */
class ProductEditViewTest extends TestCase
{
    private string $imagesRoot;

    protected function setUp(): void
    {
        $this->imagesRoot = \sys_get_temp_dir() . '/product-edit-view-' . \uniqid();
        \mkdir($this->imagesRoot . '/b/m', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->imagesRoot . '/b/m/*') as $file) {
            \unlink($file);
        }
        \rmdir($this->imagesRoot . '/b/m');
        \rmdir($this->imagesRoot . '/b');
        \rmdir($this->imagesRoot);
    }

    private function model(): object
    {
        return new class {
            public function imagePath(string $size, string $suffix = '', string $ext = '.jpg'): string
            {
                return "/b/m/{$size}-{$suffix}{$ext}";
            }
        };
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function admin(array $overrides = []): array
    {
        return \array_merge([
            'product' => ['id' => 5, 'descr' => 'Text', 'descr2' => '  ', 'descr3' => 'More',
                'descr_image' => '', 'descr2_image' => '', 'descr3_image' => '<img src="/assets/images/b/m/pic.jpg">'],
            'productModel' => $this->model(),
            'allCategories' => [['id' => 1, 'label' => 'One'], ['id' => 2, 'label' => 'Two']],
            'productCategories' => [['id' => 2]],
            'availableImageSlots' => ['main' => true, 'extra1' => false, 'extra2' => true],
            'imageUrls' => ['main' => '/m.jpg', 'extra2' => '/e2.jpg'],
            'currentKitProduct' => ['brand_name' => 'Acme', 'model_name' => 'Kit'],
            'allProducts' => [['id' => 5], ['id' => 6]],
            'productsWithOptions' => [['id' => 7]],
            'productsWithTechspec' => [['id' => 5]],
            'productsWithFaq' => [],
            'productPage' => ['robots' => 'noindex,follow'],
            'currentStatus' => ['restocking' => 1],
        ], $overrides);
    }

    public function testOptionImageSlotsOnlyListsUploadedImages(): void
    {
        $edit = ProductEditView::build($this->admin(), $this->imagesRoot);

        $this->assertSame([
            ['slot' => 'main', 'label' => 'Main', 'thumb' => '/m.jpg'],
            ['slot' => 'extra2', 'label' => 'Extra 2', 'thumb' => '/e2.jpg'],
        ], $edit['optionImageSlots']);
    }

    public function testCategoryChoicesFlagAssignedCategories(): void
    {
        $edit = ProductEditView::build($this->admin(), $this->imagesRoot);

        $this->assertFalse($edit['categoryChoices'][0]['isAssigned']);
        $this->assertTrue($edit['categoryChoices'][1]['isAssigned']);
    }

    public function testOtherProductListsExcludeTheCurrentProduct(): void
    {
        $edit = ProductEditView::build($this->admin(), $this->imagesRoot);

        $this->assertSame([['id' => 6]], $edit['otherProducts']);
        $this->assertSame([['id' => 7]], $edit['otherProductsWithOptions']);
        $this->assertSame([], $edit['otherProductsWithTechspec']);
        $this->assertSame([], $edit['otherProductsWithFaq']);
    }

    public function testDescriptionImagesOfferOnlyExistingGalleryFilesAndTrackDisabledRows(): void
    {
        \touch($this->imagesRoot . '/b/m/extra-extra2.jpg');

        $images = ProductEditView::build($this->admin(), $this->imagesRoot)['descriptionImages'];

        $this->assertSame(
            ['' => '-- None --', '/b/m/large-.jpg' => 'Main Image', '/b/m/extra-extra2.jpg' => 'Gallery 2'],
            $images['choices'],
        );
        $this->assertSame('/b/m/large-.jpg', $images['slots'][1]['path'], 'description 1 falls back to the main image');
        $this->assertTrue($images['slots'][1]['isFallback']);
        $this->assertTrue($images['slots'][2]['disabled'], 'blank text disables the row');
        $this->assertFalse($images['slots'][3]['disabled']);
        $this->assertSame('/b/m/pic.jpg', $images['slots'][3]['src']);
    }

    public function testSidebarGalleryCacheBustsExistingThumbnailsOnly(): void
    {
        \touch($this->imagesRoot . '/b/m/thumb-extra1.jpg');

        $gallery = ProductEditView::build($this->admin(), $this->imagesRoot)['sidebarGallery'];

        $this->assertMatchesRegularExpression('#^/assets/images/b/m/thumb-extra1\.jpg\?v=\d+$#', $gallery['extra1']['url']);
        $this->assertNull($gallery['extra2']['url']);
        $this->assertSame('Warranty', $gallery['warranty']['label']);
    }

    public function testSmallDisplayValues(): void
    {
        $edit = ProductEditView::build($this->admin(), $this->imagesRoot);

        $this->assertSame('Acme Kit', $edit['kitDisplayValue']);
        $this->assertSame('noindex,follow', $edit['pageRobots']);
        $this->assertSame(1, $edit['currentRestocking']);

        $bare = ProductEditView::build($this->admin(['currentKitProduct' => null, 'productPage' => null, 'currentStatus' => null]), $this->imagesRoot);
        $this->assertSame('', $bare['kitDisplayValue']);
        $this->assertSame('index,follow', $bare['pageRobots']);
        $this->assertSame(0, $bare['currentRestocking']);
    }
}
