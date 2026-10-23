<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Products list definitions, rendered by Osmium\Modules\Admin\Core\ListView.
 * Rows are assembled by ProductsController (several queries), so there is no
 * 'query' here. The category filter is server-side (reloads with ?category=).
 * Duplicate is an event: products.js calls the action and opens the new product.
 *
 * Context: taxName, totalProductCount, selectedCategory, categories.
 */
$cartColumn = [
    'key' => 'isAvailable',
    'label' => 'Available to Buy',
    'labelHtml' => '<i class="bx bx-cart" title="Available to Buy" style="font-size: 1.1em;"></i>',
    'type' => 'html',
    'sortable' => false,
    'render' => fn(array $row): string => '<i class="bx bx-cart" style="font-size: 1.25rem; color: '
        . ($row['isAvailable'] ? '#28a745' : '#dc3545') . ';" title="'
        . \htmlspecialchars($row['availTitle'], ENT_QUOTES) . '"></i>',
];

$seoColumn = [
    'key' => 'seoLabel',
    'label' => 'SEO',
    'type' => 'badge',
    'text' => true,
    'classKey' => 'seoClass',
    'classPrefix' => 'bg-label-',
    'titleKey' => 'seoTitle',
    'sortable' => false,
];

$priceColumn = [
    'key' => 'priceIncVat',
    'label' => 'Price',
    'type' => 'html',
    'nowrap' => true,
    'render' => fn(array $row, array $context): string => '<strong>&pound;'
        . \number_format((float) $row['priceIncVat'], 2) . '</strong><br><small class="text-muted">&pound;'
        . \number_format((float) $row['price_exc_tax'], 2) . ' ex '
        . \htmlspecialchars((string) $context['taxName'], ENT_QUOTES) . '</small>',
];

$common = [
    'noun' => ['product', 'products'],
    'endpoint' => 'shop/products/action/',
    'presence' => 'product',
    'datatable' => false,
];

require_once 'app/core/admin/services/ChangedByCell.php';

return [
    'recent' => $common + [
        'id' => 'products-recent',
        'title' => 'Recent Changes',
        'icon' => 'bx-time-five',
        'rowClass' => static fn(array $row): string => empty($row['deleted_at']) ? '' : 'table-danger',
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))],
            $cartColumn,
            $seoColumn,
            ['key' => 'productTitle', 'label' => 'Product', 'type' => 'text', 'strong' => true, 'fill' => true],
            $priceColumn,
            ['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'code' => true, 'nowrap' => true],
            ['key' => 'updatedFormatted', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ],

    'active' => \array_merge($common, [
        'id' => 'products',
        'title' => 'Product Management',
        'datatable' => true,
        'order' => null,
        'badgeLabel' => fn(int $count, array $context): string => ($count === (int) $context['totalProductCount']
            ? $count
            : $count . ' of ' . $context['totalProductCount']) . ' products',
        'filters' => [
            [
                'key' => 'category',
                'label' => 'Filter by Category',
                'server' => true,
                'param' => 'category',
                'selected' => fn(array $context): ?string => $context['selectedCategory'],
                'options' => function (array $rows, array $context): array {
                    $options = [['', 'All']];
                    foreach ($context['categories'] as $category) {
                        $options[] = [$category['id'], $category['label'] ?? ''];
                    }

                    return $options;
                },
            ],
        ],
        'columns' => [
            $cartColumn,
            $seoColumn,
            ['key' => 'thumbnail', 'label' => 'Image', 'type' => 'image', 'altKey' => 'productTitle', 'sortable' => false],
            [
                'key' => 'productTitle',
                'label' => 'Product',
                'type' => 'text',
                'strong' => true,
                'fill' => true,
                'sub' => ['key' => 'reference', 'style' => 'code'],
            ],
            $priceColumn,
            ['key' => 'created_at', 'label' => 'Added', 'type' => 'date', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'shop/products/edit/?id={id}'],
            ['label' => 'Price History', 'icon' => 'bx-line-chart', 'href' => 'shop/products/price-history/?product_id={id}'],
            ['label' => 'Duplicate', 'icon' => 'bx-copy', 'event' => 'duplicate'],
            ['label' => 'View on site', 'icon' => 'bx-link-external', 'url' => '/{url}/', 'target' => '_blank'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this product?',
                'danger' => true,
            ],
        ],
    ]),

    'deleted' => ListView::deletedPreset([
        'id' => 'products-deleted',
        'title' => 'Deleted Products',
        'noun' => ['product', 'products'],
        'endpoint' => 'shop/products/action/',
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))],
            ['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'code' => true, 'nowrap' => true],
            ['key' => 'productTitle', 'label' => 'Product', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'deletedFormatted', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Restore', 'icon' => 'bx-undo', 'action' => 'restore', 'class' => 'text-success'],
            [
                'label' => 'Discontinue',
                'icon' => 'bx-block',
                'action' => 'discontinue',
                'confirm' => 'Discontinue this product? The page will remain live.',
                'class' => 'text-warning',
                'divider' => true,
                'visible' => fn(array $row, array $context): bool => (bool) $context['isAdmin'],
            ],
        ],
    ]),
];
