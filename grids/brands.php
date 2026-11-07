<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the Brand Management list, rendered by
 * Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same vocabulary as lists/brands.php
 * but nothing is shared with it. The actions are a copy of the list's for now.
 */
return [
    'columns' => 5,
    'imageHeight' => 120,
    'image' => ['key' => 'logo_url', 'type' => 'image', 'altKey' => 'name'],
    'title' => ['key' => 'name', 'type' => 'text', 'strong' => true],
    'meta' => [
        ['key' => 'product_count', 'type' => 'count-pill', 'href' => 'shop/products/?brand={id}'],
    ],
    'actionsAt' => 'bottom-end',
    'actions' => [
        ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'shop/brands/edit/?id={id}'],
        [
            'label' => 'Delete',
            'icon' => 'bx-trash',
            'action' => 'delete',
            'confirm' => 'Delete this brand?',
            'danger' => true,
            'locked' => fn(array $row): ?string => (int) $row['product_count'] > 0
                ? $row['product_count'] . ' product(s) using this brand'
                : null,
        ],
    ],
];
