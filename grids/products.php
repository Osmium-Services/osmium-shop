<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the Product Management list, rendered by
 * Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same vocabulary as lists/products.php
 * but nothing is shared with it. The actions are a copy of the list's for now.
 */
return [
    'columns' => 5,
    'imageHeight' => 150,
    'image' => ['key' => 'thumbnail', 'type' => 'image', 'altKey' => 'productTitle'],
    'title' => [
        'key' => 'productTitle',
        'type' => 'text',
        'strong' => true,
        'sub' => ['key' => 'reference', 'style' => 'code'],
    ],
    'meta' => [
        [
            'key' => 'priceIncVat',
            'type' => 'html',
            'render' => fn(array $row, array $context): string => '<strong>&pound;'
                . \number_format((float) $row['priceIncVat'], 2) . '</strong> <small class="text-muted">&pound;'
                . \number_format((float) $row['price_exc_tax'], 2) . ' ex '
                . \htmlspecialchars((string) $context['taxName'], ENT_QUOTES) . '</small>',
        ],
        [
            'key' => 'isAvailable',
            'type' => 'html',
            'render' => fn(array $row): string => '<i class="bx bx-cart" style="font-size: 1.25rem; color: '
                . ($row['isAvailable'] ? '#28a745' : '#dc3545') . ';" title="'
                . \htmlspecialchars($row['availTitle'], ENT_QUOTES) . '"></i>',
        ],
        [
            'key' => 'seoLabel',
            'type' => 'badge',
            'text' => true,
            'classKey' => 'seoClass',
            'classPrefix' => 'bg-label-',
            'titleKey' => 'seoTitle',
        ],
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
];
