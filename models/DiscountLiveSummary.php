<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * What is discounting the shop right now, for the panel on the admin discounts list. Works on the rows
 * DiscountListView::row() prepares, and states the same precedence ShopTotalsService applies at the basket:
 * a sitewide discount beats everything and blocks coupons, a coupon replaces per-product discounts for its
 * basket (free delivery excepted), and a product takes the biggest per-product discount covering it.
 */
class DiscountLiveSummary
{
    /**
     * @param array<int, array<string, mixed>> $rows from DiscountListView::row()
     * @return array{sitewide: ?array, shadowedSitewide: int, products: array, coupons: array, couponsReplaceProducts: bool, hasAny: bool}
     */
    public static function build(array $rows): array
    {
        $live = \array_values(\array_filter($rows, static fn(array $row): bool => $row['isLive']));

        $sitewide = \array_values(\array_filter($live, static fn(array $row): bool => $row['type'] === 'sitewide'));
        \usort($sitewide, static fn(array $a, array $b): int => $b['value'] <=> $a['value']); // The biggest wins, as the basket query does
        $products = \array_values(\array_filter($live, static fn(array $row): bool => $row['type'] === 'product'));
        $coupons = \array_values(\array_filter($live, static fn(array $row): bool => $row['type'] === 'coupon'));

        $couponsReplaceProducts = $products !== [] && \array_filter($coupons, static fn(array $row): bool => $row['valueType'] !== 'free_delivery') !== [];

        return [
            'sitewide' => $sitewide[0] ?? null,
            'shadowedSitewide' => \max(0, \count($sitewide) - 1),
            'products' => $products,
            'coupons' => $coupons,
            'couponsReplaceProducts' => $couponsReplaceProducts,
            'hasAny' => $live !== [],
        ];
    }
}
