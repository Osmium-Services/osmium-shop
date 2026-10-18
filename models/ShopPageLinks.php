<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Product;

/**
 * Tells the admin Pages list which pages belong to products, through the pages.product_links hook.
 */
class ShopPageLinks
{
    /**
     * @param array{osmium: object} $payload
     * @return array{links: array<int, array<string, mixed>>, deletedIds: array<int, int|string>}
     */
    public static function links(array $payload): array
    {
        $product = new Product($payload['osmium']->dataSource, 'products');

        return [
            'links' => $product->getPageLinks(),
            'deletedIds' => \array_column($product->getDeletedIds(), 'id'),
        ];
    }
}
