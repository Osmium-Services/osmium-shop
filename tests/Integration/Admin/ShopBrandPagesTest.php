<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\Strings;
use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Services\AdminPages;
use Osmium\Modules\Admin\Services\ShopBrandPages;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/admin/models/AdminModel.php';
require_once 'app/core/admin/models/Page.php';
require_once 'app/services/osmium-shop/models/Brand.php';
require_once 'app/core/admin/services/AdminPages.php';
require_once 'app/services/osmium-shop/models/ShopBrandPages.php';

/**
 * A brand's own page: an ordinary page at brands/<slug> linked through productbrands.page_id. The routes file is
 * faked so the real app/config/routes.php is never rewritten.
 */
class ShopBrandPagesTest extends DatabaseTestCase
{
    private Brand $brands;
    private Page $page;
    private ShopBrandPages $brandPages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = new Page($this->db, 'pages');
        $this->brands = new Brand($this->db, 'productbrands');
        $pages = new class ($this->page) extends AdminPages {
            public function regenerateRoutesFile(): void {}
        };
        $this->brandPages = new ShopBrandPages($this->brands, $pages, new Strings());
    }

    private function newBrand(string $name): array
    {
        $id = $this->brands->insert($name . ' ' . \uniqid(), null, null);

        return $this->brands->getById($id);
    }

    public function test_the_page_is_made_under_the_prefix_and_linked_to_the_brand(): void
    {
        $brand = $this->newBrand('Acme Power');

        $pageId = $this->brandPages->create($brand, 'brands');

        $page = $this->page->getById($pageId);
        $this->assertStringStartsWith('brands/acme-power-', $page['url']);
        $this->assertSame('index,follow', $page['robots']);
        $this->assertSame($pageId, (int) $this->brands->getById((int) $brand['id'])['page_id']);
    }

    public function test_a_brand_that_has_a_page_cannot_get_a_second(): void
    {
        $brand = $this->newBrand('Twice');
        $this->brandPages->create($brand, 'brands');

        $this->expectExceptionMessage('already has a page');
        $this->brandPages->create($this->brands->getById((int) $brand['id']), 'brands');
    }

    public function test_an_address_already_in_use_is_refused(): void
    {
        $brand = $this->newBrand('Taken');
        $this->brandPages->create($brand, 'brands');
        $other = $this->brands->getById($this->brands->insert($brand['name'], null, null)); // Same name, so the same address

        $this->expectExceptionMessage('already exists');
        $this->brandPages->create($other, 'brands');
    }

    public function test_the_prefix_comes_from_the_shop_config_with_brands_as_the_default(): void
    {
        $this->assertSame('brands', ShopBrandPages::prefix(null));
        $this->assertSame('brands', ShopBrandPages::prefix((object) ['brandPagePrefix' => ' / ']));
        $this->assertSame('makes', ShopBrandPages::prefix((object) ['brandPagePrefix' => '/makes/']));
    }

    public function test_pages_of_brands_are_listed_with_binned_brands_included(): void
    {
        $brand = $this->newBrand('Listed');
        $pageId = $this->brandPages->create($brand, 'brands');
        $this->brands->softDelete((int) $brand['id']);

        $withPages = \array_column($this->brands->withPages(), 'page_id');

        $this->assertContains((string) $pageId, \array_map('strval', $withPages));
    }
}
