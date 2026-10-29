<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Admin\Models\Product;

require_once __DIR__ . '/ShopPaths.php';

/**
 * Answers shop.product.files: every live product that has documents or images on disk, with the category it is listed
 * under, so another service (Trade Downloads) can offer them without knowing the shop's folders or tables. Read-only, no
 * customer data. The files are the shop's own and are as public as its product pages.
 */
class ShopProductFiles
{
    public const DOCUMENT_TYPES = ['datasheet', 'brochure', 'instructions'];
    private const MAX_EXTRA_IMAGES = 20;

    /**
     * @param array{dataSource?: OsmiumPDO, osmium?: object} $payload `osmium` gives the images folder (config site imagesPath)
     * @return array<int, array<string, mixed>>|null products with files, by brand then name; null when no database was given
     */
    public static function files(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $imagesUrl = (string) (($payload['osmium'] ?? null)?->config?->site?->imagesPath ?? '/assets/images');
        $root = \rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '/var/www/html', '/');
        $products = new Product($db, 'products');
        $categories = self::categoryMap($products->listCategoriesForFiles());

        $found = [];
        foreach ($products->listForFiles() as $row) {
            $product = self::shape(row: $row, categories: $categories, imagesUrl: $imagesUrl, imagesRoot: $root . $imagesUrl, exists: \is_file(...));
            if ($product !== null) $found[] = $product;
        }

        return $found;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{parent: ?int, label: string, sort: int}>
     */
    public static function categoryMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = ['parent' => $row['parent_id'] === null ? null : (int) $row['parent_id'], 'label' => (string) $row['label'], 'sort' => (int) ($row['sort'] ?? 9999)];
        }

        return $map;
    }

    /**
     * A category with its names and sort numbers from the top down. A loop or a missing parent ends the path there.
     *
     * @param array<int, array{parent: ?int, label: string, sort: int}> $map
     * @return array{id: int, name: string, path: string[], order: int[]}|null
     */
    public static function category(?int $id, array $map): ?array
    {
        if ($id === null || !isset($map[$id])) return null;

        $path = [];
        $order = [];
        $seen = [];
        for ($at = $id; $at !== null && isset($map[$at]) && !isset($seen[$at]); $at = $map[$at]['parent']) {
            $seen[$at] = true;
            \array_unshift($path, $map[$at]['label']);
            \array_unshift($order, $map[$at]['sort']);
        }

        return ['id' => $id, 'name' => $map[$id]['label'], 'path' => $path, 'order' => $order];
    }

    /**
     * @param array<string, mixed> $row a live product (id, model_name, brand_name, category_id)
     * @param array<int, array{parent: ?int, label: string, sort: int}> $categories
     * @param callable(string): bool $exists whether a file is there
     * @return array<string, mixed>|null null when the product has no document and no image
     */
    public static function shape(array $row, array $categories, string $imagesUrl, string $imagesRoot, callable $exists): ?array
    {
        $brand = ShopPaths::brand((string) $row['brand_name']);
        $model = ShopPaths::model((string) $row['model_name']);
        $name = $brand . '-' . $model;
        $folder = '/shop/' . $brand . '/' . $model;

        $documents = [];
        foreach (self::DOCUMENT_TYPES as $type) {
            $file = $folder . '/' . $type . '/' . $name . '-' . $type . '.pdf';
            $present = $exists($imagesRoot . $file);
            if ($present) $documents[] = ['type' => $type, 'label' => \ucfirst($type), 'url' => $imagesUrl . $file];
        }

        $images = [];
        $mainFile = $folder . '/large/' . $name . '.jpg';
        $hasMain = $exists($imagesRoot . $mainFile);
        if ($hasMain) $images[] = self::image(type: 'main', label: 'Main Product Image', file: $mainFile, zipName: $name . '.jpg', imagesUrl: $imagesUrl, imagesRoot: $imagesRoot, exists: $exists);

        for ($n = 1; $n <= self::MAX_EXTRA_IMAGES; $n++) {
            $extraFile = $folder . '/extra/' . $name . '-extra' . $n . '.jpg';
            $missing = !$exists($imagesRoot . $extraFile);
            if ($missing) break;

            $images[] = self::image(type: 'extra', label: 'Extra Image ' . $n, file: $extraFile, zipName: $name . '-extra' . $n . '.jpg', imagesUrl: $imagesUrl, imagesRoot: $imagesRoot, exists: $exists);
        }

        $nothing = $documents === [] && $images === [];
        if ($nothing) return null;

        return [
            'id' => (int) $row['id'],
            'brandName' => (string) $row['brand_name'],
            'name' => (string) $row['model_name'],
            'title' => \trim($row['brand_name'] . ' ' . $row['model_name']),
            'category' => self::category($row['category_id'] === null ? null : (int) $row['category_id'], $categories),
            'documents' => $documents,
            'images' => $images,
        ];
    }

    /**
     * An image: its public address, and the file to put in a ZIP (the 2x version when there is one) with the name to give it there.
     *
     * @param callable(string): bool $exists
     * @return array{type: string, label: string, url: string, zipFile: string, zipName: string}
     */
    private static function image(string $type, string $label, string $file, string $zipName, string $imagesUrl, string $imagesRoot, callable $exists): array
    {
        $retina = \preg_replace('/\.jpg$/', '@2x.jpg', $file);
        $hasRetina = $exists($imagesRoot . $retina);

        return ['type' => $type, 'label' => $label, 'url' => $imagesUrl . $file, 'zipFile' => $imagesRoot . ($hasRetina ? $retina : $file), 'zipName' => $zipName];
    }
}
