<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Admin\Models\Product;

require_once __DIR__ . '/ShopProductFiles.php';
require_once __DIR__ . '/ShopPaths.php';

/**
 * Answers shop.products.list: every live product as another service needs to list it (a trade price list), so it never
 * reads the shop's tables. Read-only, no customer data. Prices are ex tax; the tax rate stays in Shop settings.
 * Discontinued products are left out. `available` says whether the product can be bought now (a status that is not
 * "out of stock"), and `statusLabel` is the short status text.
 */
class ShopProductList
{
    /**
     * @param array{dataSource?: OsmiumPDO, osmium?: object} $payload `osmium` gives the images folder (config site imagesPath)
     * @return array<int, array<string, mixed>>|null products by brand then name; null when no database was given
     */
    public static function list(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $imagesUrl = (string) (($payload['osmium'] ?? null)?->config?->site?->imagesPath ?? '/assets/images');
        $root = \rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '/var/www/html', '/');
        $products = new Product($db, 'products');
        $categories = ShopProductFiles::categoryMap($products->listCategoriesForFiles());

        $list = [];
        foreach ($products->listForHook() as $row) {
            $list[] = self::shape(row: $row, categories: $categories, imagesUrl: $imagesUrl, imagesRoot: $root . $imagesUrl, exists: \is_file(...));
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $row a live product (see product-list-for-hook.sql)
     * @param array<int, array{parent: ?int, label: string, sort: int}> $categories
     * @param callable(string): bool $exists whether a file is there
     * @return array{id: int, brandId: int, brandName: string, name: string, title: string, reference: string, priceExcTax: float, available: bool, statusLabel: string, hasOptions: bool, image: ?string, category: ?array{id: int, name: string, path: string[], order: int[]}}
     */
    public static function shape(array $row, array $categories, string $imagesUrl, string $imagesRoot, callable $exists): array
    {
        $brand = ShopPaths::brand((string) $row['brand_name']);
        $model = ShopPaths::model((string) $row['model_name']);
        $file = '/shop/' . $brand . '/' . $model . '/medium/' . $brand . '-' . $model . '.jpg';
        $hasImage = $exists($imagesRoot . $file);

        return [
            'id' => (int) $row['id'],
            'brandId' => (int) $row['brand_id'],
            'brandName' => (string) $row['brand_name'],
            'name' => (string) $row['model_name'],
            'title' => \trim($row['brand_name'] . ' ' . $row['model_name']),
            'reference' => (string) ($row['reference'] ?? ''),
            'priceExcTax' => (float) $row['price_exc_tax'],
            'available' => (bool) $row['available'],
            'statusLabel' => (string) ($row['status_label'] ?? ''),
            'hasOptions' => (bool) $row['has_options'],
            'image' => $hasImage ? $imagesUrl . $file : null,
            'category' => ShopProductFiles::category($row['category_id'] === null ? null : (int) $row['category_id'], $categories),
        ];
    }
}
