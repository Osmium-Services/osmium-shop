<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Product;

/**
 * Tells the admin Pages list which pages belong to products, through the pages.product_links hook, and keeps a product's or
 * category's page from being deleted, through pages.delete_blockers.
 */
class ShopPageLinks
{
    /**
     * @param array{osmium: object} $payload
     * @return array{links: array<int, array<string, mixed>>, deletedIds: array<int, int|string>}
     */
    public static function links(array $payload): array
    {
        $dataSource = $payload['dataSource'] ?? $payload['osmium']->dataSource; // The admin Pages list passes the framework, the page editor the connection
        $product = new Product($dataSource, 'products');

        return [
            'links' => $product->getPageLinks(),
            'deletedIds' => \array_column($product->getDeletedIds(), 'id'),
        ];
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
