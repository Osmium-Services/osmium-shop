<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Product;

/**
 * Tells core about the shop's pages: a live product's page is owned by the shop (pages.owners), every product page id stays
 * reserved (pages.reserved_ids), and a product's, category's or brand's page cannot be deleted (pages.delete_blockers).
 * Product pages and brand pages each get their own child sitemap (sitemap.groups).
 */
class ShopPageLinks
{
    /**
     * A product whose own record is deleted is left out, so its page can be deleted from the Pages list.
     *
     * @param array{osmium?: object, dataSource?: object} $payload
     * @return array<int, array{label: string, editUrl: string, canBeParent: bool}> keyed by page id
     */
    public static function owners(array $payload): array
    {
        $product = new Product(self::dataSource($payload), 'products');

        $owners = [];
        foreach ($product->getPageOwners() as $row) {
            $owners[(int) $row['page_id']] = [
                'label' => 'Shop product',
                'editUrl' => 'shop/products/edit/?id=' . (int) $row['product_id'],
                'canBeParent' => false, // A shop has too many product pages to offer each as a parent
            ];
        }

        foreach ((new Brand(self::dataSource($payload), 'productbrands'))->withPages() as $row) {
            if ($row['deleted_at'] !== null) continue;

            $owners[(int) $row['page_id']] = [
                'label' => 'Shop brand',
                'editUrl' => 'shop/brands/edit/?id=' . (int) $row['id'],
                'canBeParent' => false,
            ];
        }

        return $owners;
    }

    /**
     * The shop's product pages and brand pages, for the `sitemap.groups` hook: a child sitemap of its own for each.
     *
     * @param array{osmium?: object, dataSource?: object} $payload
     * @return array<int, array{key: string, label: string, pageIds: array<int, int>}>
     */
    public static function sitemapGroups(array $payload): array
    {
        $brandPageIds = [];
        foreach ((new Brand(self::dataSource($payload), 'productbrands'))->withPages() as $row) {
            if ($row['deleted_at'] === null) $brandPageIds[] = (int) $row['page_id'];
        }

        $productPageIds = [];
        foreach ((new Product(self::dataSource($payload), 'products'))->getPageOwners() as $row) {
            if ((int) $row['is_published'] === 1) $productPageIds[] = (int) $row['page_id']; // A Draft stays out of the sitemap
        }

        return [
            ['key' => 'products', 'label' => 'Shop products', 'pageIds' => $productPageIds],
            ['key' => 'brands', 'label' => 'Shop brands', 'pageIds' => $brandPageIds],
        ];
    }

    /**
     * @param array{osmium?: object, dataSource?: object} $payload
     * @return array{ids: array<int, int>}
     */
    public static function reservedIds(array $payload): array
    {
        $product = new Product(self::dataSource($payload), 'products');

        $brandPageIds = \array_column((new Brand(self::dataSource($payload), 'productbrands'))->withPages(), 'page_id'); // Binned brands keep their page id

        return ['ids' => \array_map('intval', \array_merge(\array_column($product->getPageLinks(), 'page_id'), $brandPageIds))];
    }

    private static function dataSource(array $payload): object
    {
        return $payload['dataSource'] ?? $payload['osmium']->dataSource; // The Pages list passes the framework, the page editor the connection
    }

    /**
     * @param array{pageId: int, dataSource: object} $payload
     * @return string|null why the page cannot be deleted, or null when the shop has no claim on it
     */
    public static function deleteBlocker(array $payload): ?string
    {
        $product = new Product($payload['dataSource'], 'products');
        $pageId = (int) $payload['pageId'];

        $linkedProduct = $product->findProductOfPage($pageId);
        if ($linkedProduct) return "Page is linked to product \"{$linkedProduct['model_name']}\"";

        $linkedCategory = $product->findCategoryOfPage($pageId);
        if ($linkedCategory) return "Page is the page for category \"{$linkedCategory['label']}\"";

        $linkedBrand = self::brandOfPage((new Brand($payload['dataSource'], 'productbrands'))->withPages(), $pageId);
        if ($linkedBrand) return "Page is the page for brand \"{$linkedBrand['name']}\"";

        return null;
    }

    /** @param array<int, array{name: string, page_id: int}> $brandsWithPages */
    private static function brandOfPage(array $brandsWithPages, int $pageId): ?array
    {
        foreach ($brandsWithPages as $brand) {
            if ((int) $brand['page_id'] === $pageId) return $brand;
        }

        return null;
    }
}
