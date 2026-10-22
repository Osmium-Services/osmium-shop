<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Brands list definitions, rendered by Osmium\Modules\Admin\Core\ListView.
 *
 * Column types: text, link, icon-link, badge, date, html (needs 'render').
 * {field} in 'href' / 'confirm' / 'title' is replaced with the row's value.
 */
return [
    'recent' => [
        'id' => 'brands-recent',
        'title' => 'Recent Changes',
        'noun' => ['brand', 'brands'],
        'icon' => 'bx-time-five',
        'query' => 'brand-list-recent.sql',
        'endpoint' => 'shop/brands/action/',
        'datatable' => false,
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'product_count', 'label' => 'Products', 'type' => 'badge'],
            ['key' => 'updatedFormatted', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'shop/brands/edit/?id={id}'],
        ],
    ],

    'active' => [
        'id' => 'brands',
        'title' => 'Brand Management',
        'noun' => ['brand', 'brands'],
        'query' => 'brand-list-with-counts.sql',
        'endpoint' => 'shop/brands/action/',
        'presence' => 'brand',
        'order' => [0, 'asc'],
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'page_url', 'label' => 'Page URL', 'type' => 'link', 'href' => '/{page_url}/', 'nowrap' => true],
            ['key' => 'info_host', 'label' => 'External Info', 'type' => 'link', 'href' => '{info_external}', 'external' => true, 'nowrap' => true],
            ['key' => 'product_count', 'label' => 'Products', 'type' => 'badge'],
            ['key' => 'created_at', 'label' => 'Added', 'type' => 'date', 'nowrap' => true],
        ],
        'actions' => [
            [
                'label' => 'Edit',
                'icon' => 'bx-edit-alt',
                'href' => 'shop/brands/edit/?id={id}',
            ],
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
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'brands-deleted',
        'title' => 'Deleted Brands',
        'noun' => ['item', 'items'],
        'query' => 'brand-list-deleted.sql',
        'endpoint' => 'shop/brands/action/',
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'product_count', 'label' => 'Products', 'type' => 'badge'],
            ['key' => 'deleted_at', 'label' => 'Deleted', 'type' => 'date', 'nowrap' => true],
        ],
    ]),
];
