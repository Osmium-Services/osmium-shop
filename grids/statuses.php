<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the Status Management list, rendered by
 * Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same vocabulary as lists/statuses.php
 * but nothing is shared with it. The actions are a copy of the list's for now.
 */
$flag = static fn(string $key, string $label, string $class): array => [
    'key' => $key,
    'type' => 'html',
    'render' => static fn(array $row): string => $row[$key] ? '<span class="badge ' . $class . '">' . $label . '</span>' : '',
];

return [
    'columns' => 4,
    'title' => [
        'key' => 'descr_short',
        'type' => 'text',
        'strong' => true,
        'sub' => ['key' => 'descr_long', 'style' => 'muted'],
    ],
    'meta' => [
        [
            'key' => 'enabled',
            'type' => 'html',
            'render' => static fn(array $row): string => $row['enabled']
                ? '<span class="badge bg-success">Enabled</span>'
                : '<span class="badge bg-secondary">Disabled</span>',
        ],
        $flag('product_available', 'Available', 'bg-label-success'),
        $flag('restocking', 'Restocking', 'bg-label-warning'),
        ['key' => 'product_count', 'type' => 'count-pill', 'href' => 'shop/products/?status={id}'],
    ],
    'actionsAt' => 'bottom-end',
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
];
