<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Product Statuses list definitions, rendered by Osmium\Modules\Admin\Core\ListView.
 */
$check = static fn(string $key, string $falseClass): array => [
    'key' => $key,
    'type' => 'html',
    'render' => static fn(array $row, array $context): string => $row[$key]
        ? '<i class="bx bx-check text-success" style="font-size: 1.25rem"></i>'
        : '<i class="bx bx-x ' . $falseClass . '" style="font-size: 1.25rem"></i>',
];

return [
    'recent' => [
        'id' => 'statuses-recent',
        'title' => 'Recent Changes',
        'noun' => ['status', 'statuses'],
        'icon' => 'bx-time-five',
        'query' => 'status-list-recent.sql',
        'endpoint' => 'shop/statuses/action/',
        'datatable' => false,
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'descr_short', 'label' => 'Short', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'product_count', 'label' => 'Products', 'type' => 'badge'],
            ['key' => 'updatedFormatted', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'shop/statuses/edit/?id={id}'],
        ],
    ],

    'active' => [
        'id' => 'statuses',
        'title' => 'Status Management',
        'noun' => ['status', 'statuses'],
        'query' => 'status-list-with-counts.sql',
        'endpoint' => 'shop/statuses/action/',
        'presence' => 'status',
        'order' => [0, 'asc'],
        'badgeLabel' => '{count} statuses',
        'columns' => [
            ['key' => 'descr_short', 'label' => 'Short', 'type' => 'text', 'strong' => true, 'nowrap' => true],
            ['key' => 'descr_long', 'label' => 'Long Description', 'type' => 'text', 'small' => true, 'fill' => true],
            $check('product_available', 'text-danger') + ['label' => 'Avail', 'title' => 'Product Available'],
            $check('restocking', 'text-muted') + ['label' => 'Restock', 'title' => 'Restocking'],
            ['key' => 'schema_ref', 'label' => 'Schema Ref', 'type' => 'text', 'code' => true, 'fallback' => '-', 'nowrap' => true],
            $check('list', 'text-muted') + ['label' => 'List', 'title' => 'Show in List'],
            [
                'key' => 'enabled',
                'label' => 'Enabled',
                'type' => 'html',
                'render' => static fn(array $row, array $context): string => $row['enabled']
                    ? '<span class="badge bg-success">Yes</span>'
                    : '<span class="badge bg-secondary">No</span>',
            ],
            ['key' => 'product_count', 'label' => 'Products', 'type' => 'badge'],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'shop/statuses/edit/?id={id}'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this status?',
                'danger' => true,
                'locked' => fn(array $row): ?string => (int) $row['product_count'] > 0
                    ? $row['product_count'] . ' product(s) using this status'
                    : null,
            ],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'statuses-deleted',
        'title' => 'Deleted Statuses',
        'noun' => ['item', 'items'],
        'query' => 'status-list-deleted.sql',
        'endpoint' => 'shop/statuses/action/',
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'descr_short', 'label' => 'Short Description', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'product_count', 'label' => 'Products', 'type' => 'badge'],
            ['key' => 'deleted_at', 'label' => 'Deleted', 'type' => 'date', 'nowrap' => true],
        ],
    ]),
];
