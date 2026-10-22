<?php

declare(strict_types=1);

/**
 * Product Categories list definitions, rendered by Osmium\Modules\Admin\Core\ListView.
 * The category tree itself is a custom editor; only Recent Changes is a list.
 */
return [
    'recent' => [
        'id' => 'categories-recent',
        'title' => 'Recent Changes',
        'noun' => ['category', 'categories'],
        'icon' => 'bx-time-five',
        'datatable' => false,
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'label', 'label' => 'Category', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'updatedFormatted', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
        'actions' => [],
    ],
];
