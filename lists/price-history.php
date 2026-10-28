<?php

declare(strict_types=1);

/**
 * Price history lists, rendered by Osmium\Modules\Admin\Core\ListView. Rows are shaped by
 * ProductPriceHistoryController (formatBatch(), formatDetail(), formatManualChange()).
 */
$productLink = static fn(array $row, array $context): string => '<a href="'
    . \htmlspecialchars($context['adminUrl']('shop/products/price-history/') . '?product_id=' . (int) $row['product_id']) . '"><strong>'
    . $row['productLabelHtml'] . '</strong></a>';

$change = static fn(string $old, string $new): callable => static fn(array $row, array $context): string => '<span class="text-muted">'
    . $row[$old] . '</span> <i class="bx bx-right-arrow-alt text-muted"></i> ' . $row[$new];

return [
    'page' => [
        'title' => 'Price History',
        'badges' => fn(array $context): array => $context['filterProductId']
            ? [['Showing ' . ($context['filterProductName'] ?? 'Product #' . $context['filterProductId']), 'bg-label-secondary']]
            : [],
        'buttons' => fn(array $context): array => \array_merge(
            $context['filterProductId']
                ? [['label' => 'Clear filter', 'icon' => 'bx-x', 'variant' => 'outline-secondary', 'href' => 'shop/products/price-history/']]
                : [],
            [['label' => 'Back to Products', 'icon' => 'bx-arrow-back', 'variant' => 'outline-secondary', 'href' => 'shop/products/']],
        ),
    ],

    'imports' => [
        'id' => 'price-imports',
        'title' => 'Import History',
        'icon' => 'bx-import',
        'noun' => ['import', 'imports'],
        'endpoint' => 'shop/products/price-history/action/',
        'emptyText' => 'No price imports recorded yet.',
        'order' => null,
        'expand' => [
            'action' => 'get_items',
            'itemsKey' => 'items',
            'emptyText' => 'No products in this import',
            'columns' => [
                ['key' => 'product_label', 'label' => 'Product', 'strong' => true],
                ['key' => 'price_old', 'label' => 'Old Price (ex VAT)'],
                ['key' => 'price_new', 'label' => 'New Price (ex VAT)'],
                ['key' => 'rrp_old', 'label' => 'Old RRP (ex VAT)'],
                ['key' => 'rrp_new', 'label' => 'New RRP (ex VAT)'],
            ],
        ],
        'columns' => [
            ['key' => 'changedAtFormatted', 'label' => 'Date', 'type' => 'text', 'nowrap' => true, 'sortKey' => 'changed_at'],
            ['key' => 'productCount', 'label' => 'Prices changed', 'type' => 'text', 'nowrap' => true],
            ['key' => 'username', 'label' => 'User', 'type' => 'text', 'fill' => true, 'fallback' => 'Unknown'],
        ],
        'actions' => [
            [
                'label' => 'Undo import',
                'icon' => 'bx-undo',
                'action' => 'undo_batch',
                'confirm' => 'Undo this import? All its products will be reverted to their previous prices.',
                'danger' => true,
                'visible' => fn(array $row): bool => (bool) $row['canUndo'],
            ],
        ],
    ],

    'manual' => [
        'id' => 'price-manual',
        'title' => 'Manual Changes',
        'icon' => 'bx-edit',
        'noun' => ['change', 'changes'],
        'endpoint' => 'shop/products/price-history/action/',
        'emptyText' => 'No manual price changes recorded yet.',
        'order' => null,
        'columns' => [
            ['key' => 'productLabelHtml', 'label' => 'Product', 'type' => 'html', 'render' => $productLink],
            ['key' => 'priceNewHtml', 'label' => 'Price (ex VAT)', 'type' => 'html', 'nowrap' => true, 'render' => $change('priceOldHtml', 'priceNewHtml')],
            [
                'key' => 'rrpNewHtml',
                'label' => 'RRP (ex VAT)',
                'type' => 'html',
                'nowrap' => true,
                'render' => fn(array $row, array $context): string => $row['hasRrp']
                    ? $change('rrpOldHtml', 'rrpNewHtml')($row, $context)
                    : '<span class="text-muted">-</span>',
            ],
            ['key' => 'changedAtFormatted', 'label' => 'Date', 'type' => 'text', 'nowrap' => true, 'sortKey' => 'changed_at'],
            ['key' => 'username', 'label' => 'User', 'type' => 'text', 'fill' => true, 'fallback' => 'Unknown'],
        ],
        'actions' => [
            [
                'label' => 'Undo',
                'icon' => 'bx-undo',
                'action' => 'undo_manual',
                'confirm' => 'Undo this manual price change?',
                'danger' => true,
                'visible' => fn(array $row): bool => (bool) $row['canUndo'],
            ],
        ],
    ],
];
