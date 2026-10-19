<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Product;

/**
 * Tells core about the shop's pages: a live product's page is owned by the shop (pages.owners), every product page id stays
 * reserved (pages.reserved_ids), and a product's or category's page cannot be deleted (pages.delete_blockers).
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
                'label' => 'Shop product "' . $row['model_name'] . '"',
                'editUrl' => 'shop/products/edit/?id=' . (int) $row['product_id'],
                'canBeParent' => false, // A shop has too many product pages to offer each as a parent
            ];
        }

        return $owners;
    }

    /**
     * @param array{osmium?: object, dataSource?: object} $payload
     * @return array{ids: array<int, int>}
     */
    public static function reservedIds(array $payload): array
    {
        $product = new Product(self::dataSource($payload), 'products');

        return ['ids' => \array_map('intval', \array_column($product->getPageLinks(), 'page_id'))];
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

        return null;
    }
}
