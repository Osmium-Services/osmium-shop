<?php

declare(strict_types=1);

/**
 * Product Categories list definitions, rendered by Osmium\Modules\Admin\Core\ListView.
 * The category tree itself is a custom editor; only Recent Changes and Deleted are lists.
 */
require_once 'app/core/admin/services/ChangedByCell.php';

return [
    'recent' => [
        'id' => 'categories-recent',
        'title' => 'Recent Changes',
        'noun' => ['category', 'categories'],
        'icon' => 'bx-time-five',
        'rowClass' => static fn(array $row): string => empty($row['deleted_at']) ? '' : 'table-danger',
        'datatable' => false,
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))],
            ['key' => 'label', 'label' => 'Category', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'updatedFormatted', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
        'actions' => [],
    ],

    'deleted' => \Osmium\Modules\Admin\Core\ListView::deletedPreset(
        definition: [
            'id' => 'categories-deleted',
            'title' => 'Deleted Categories',
            'noun' => ['category', 'categories'],
            'endpoint' => 'shop/categories/action/',
            'hideWhenEmpty' => false,
            'columns' => [
                ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))],
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'strong' => true, 'fill' => true],
                ['key' => 'deleted_formatted', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
            ],
            'actions' => [
                ['label' => 'Restore', 'icon' => 'bx-undo', 'action' => 'restore', 'class' => 'text-success'],
                [
                    'label' => 'Permanently Delete',
                    'icon' => 'bx-trash',
                    'action' => 'permanent_delete',
                    'confirm' => fn(array $row): string => 'Are you sure you want to PERMANENTLY delete "' . $row['label'] . '"? This cannot be undone.',
                    'danger' => true,
                    'visible' => fn(array $row, array $context): bool => (bool) $context['isAdmin'],
                ],
            ],
        ],
    ),
];
