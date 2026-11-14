<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Admin\Models\Product;

require_once __DIR__ . '/ShopProductFiles.php';

/**
 * Answers shop.categories: every live product category with its parent and its names and sort numbers from the top of
 * the tree, for a service that needs to arrange or choose categories (such as a trade price list). Read-only, no
 * customer data. In tree order: top level first by sort, each followed by its children.
 */
class ShopCategoryLookup
{
    /**
     * @param array{dataSource?: OsmiumPDO} $payload
     * @return array<int, array{id: int, parentId: ?int, name: string, path: string[], order: int[]}>|null null when no database was given
     */
    public static function categories(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        return self::shape((new Product($db, 'products'))->listCategoriesForFiles());
    }

    /**
     * @param array<int, array<string, mixed>> $rows id, parent_id, label, sort
     * @return array<int, array{id: int, parentId: ?int, name: string, path: string[], order: int[]}>
     */
    public static function shape(array $rows): array
    {
        $map = ShopProductFiles::categoryMap($rows);

        $categories = [];
        foreach (\array_keys($map) as $id) {
            $category = ShopProductFiles::category($id, $map);
            $categories[] = ['id' => $id, 'parentId' => $map[$id]['parent'], 'name' => $category['name'], 'path' => $category['path'], 'order' => $category['order']];
        }

        \usort($categories, static fn(array $a, array $b): int => self::compareOrder($a['order'], $b['order']) ?: $a['id'] <=> $b['id']);

        return $categories;
    }

    /** Sort numbers from the top of the tree, one level at a time (a parent before its children). PHP's <=> on arrays would compare lengths first. */
    public static function compareOrder(array $a, array $b): int
    {
        foreach ($a as $level => $number) {
            if (!isset($b[$level])) return 1;
            if ($number !== $b[$level]) return $number <=> $b[$level];
        }

        return \count($a) <=> \count($b);
    }
}
