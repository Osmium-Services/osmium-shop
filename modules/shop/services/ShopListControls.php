<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

/**
 * The "Show", "Sort By" and stock checkboxes above a product list, and the paging under it.
 *
 * Every value arrives from the querystring, so each is checked against a fixed list before use: the sort column goes
 * straight into SQL as {SORT_COLUMN}, so a request value must never reach it unchecked.
 */
class ShopListControls
{
    public const LIMIT_OPTIONS = [5, 10, 25, 50, 75, 100];
    public const DEFAULT_LIMIT = 10;
    public const DEFAULT_SORT = 'newest';
    public const STOCK_OPTIONS = ['in', 'out', 'preorder'];

    public const SORT_OPTIONS = [
        'newest' => ['column' => 'P.created_at DESC, P.id ASC', 'label' => 'Newest'],
        'name-asc' => ['column' => 'P.model_name ASC, P.id ASC', 'label' => 'Name (A - Z)'],
        'name-desc' => ['column' => 'P.model_name DESC, P.id ASC', 'label' => 'Name (Z - A)'],
        'price-asc' => ['column' => 'P.price_exc_tax ASC, P.id ASC', 'label' => 'Price (Low > High)'],
        'price-desc' => ['column' => 'P.price_exc_tax DESC, P.id ASC', 'label' => 'Price (High > Low)'],
    ];

    /**
     * @return array{limit: int, sort: string, sortColumn: string, stock: array<int, string>, page: int}
     */
    public static function resolve(mixed $limit, mixed $sort, mixed $stock, mixed $page): array
    {
        $limit = (int) $limit;
        if (!\in_array($limit, self::LIMIT_OPTIONS, true)) $limit = self::DEFAULT_LIMIT;

        $sort = (string) $sort;
        if (!isset(self::SORT_OPTIONS[$sort])) $sort = self::DEFAULT_SORT;

        $stock = \array_values(\array_intersect((array) $stock, self::STOCK_OPTIONS));

        return [
            'limit' => $limit,
            'sort' => $sort,
            'sortColumn' => self::SORT_OPTIONS[$sort]['column'],
            'stock' => $stock,
            'page' => \max(1, (int) $page),
        ];
    }

    /**
     * One page of $ids. A page past the end (a stale or hand-edited link) lands on the last real page.
     *
     * @return array{0: array, 1: int} The ids for the page, and the page number used
     */
    public static function paginate(array $ids, int $page, int $limit): array
    {
        $totalPages = \max(1, (int) \ceil(\count($ids) / $limit));
        $page = \min($page, $totalPages);

        return [\array_slice($ids, ($page - 1) * $limit, $limit), $page];
    }
}
