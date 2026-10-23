<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Discounts list definitions, rendered by Osmium\Modules\Admin\Core\ListView.
 * Row fields (typeLabel, valueLabel, statusBadge...) are prepared by
 * DiscountsController::formatDiscount().
 */
return [
    'recent' => [
        'id' => 'discounts-recent',
        'title' => 'Recent Changes',
        'noun' => ['discount', 'discounts'],
        'icon' => 'bx-time-five',
        'query' => 'discount-list-recent.sql',
        'endpoint' => 'shop/discounts/action/',
        'datatable' => false,
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\DiscountListView::changedByHtml($row, (string)($context['avatarUrl'] ?? ''), \Osmium\Modules\Admin\Services\AdminPresence::AVATAR_PATH)],
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'type', 'label' => 'Type', 'type' => 'text', 'format' => fn(string $value): string => \ucfirst($value)],
            ['key' => 'updatedFormatted', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ],

    'active' => [
        'id' => 'discounts',
        'title' => 'Discount Management',
        'noun' => ['discount', 'discounts'],
        'query' => 'discount-list-with-counts.sql',
        'endpoint' => 'shop/discounts/action/',
        'presence' => 'discount',
        'order' => [8, 'desc'],
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            [
                'key' => 'typeLabel',
                'label' => 'Type',
                'type' => 'text',
                'sub' => ['key' => 'code', 'style' => 'code'],
            ],
            [
                'key' => 'valueLabel',
                'label' => 'Value',
                'type' => 'text',
                'nowrap' => true,
                'sub' => ['key' => 'usageLabel', 'style' => 'muted'],
            ],
            ['key' => 'windowLabel', 'label' => 'Window', 'type' => 'text', 'small' => true, 'nowrap' => true],
            ['key' => 'statusBadge', 'label' => 'Status', 'type' => 'raw'],
            ['key' => 'scopeLabel', 'label' => 'Covers', 'type' => 'text', 'small' => true],
            ['key' => 'ordersLabel', 'label' => 'Orders', 'type' => 'text', 'nowrap' => true],
            ['key' => 'givenLabel', 'label' => 'Given (ex VAT)', 'type' => 'text', 'nowrap' => true],
            ['key' => 'created_at', 'label' => 'Added', 'type' => 'date', 'nowrap' => true],
        ],
        'actions' => [
            [
                'label' => 'Edit',
                'icon' => 'bx-edit-alt',
                'href' => 'shop/discounts/edit/?id={id}',
            ],
            [
                'label' => 'Duplicate',
                'icon' => 'bx-copy',
                'action' => 'duplicate',
                'submit' => 'form',
            ],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this discount?',
                'danger' => true,
            ],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'discounts-deleted',
        'title' => 'Deleted Discounts',
        'noun' => ['item', 'items'],
        'query' => 'discount-list-deleted.sql',
        'endpoint' => 'shop/discounts/action/',
        'hideWhenEmpty' => false,
        'columns' => [
            ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\DiscountListView::changedByHtml($row, (string)($context['avatarUrl'] ?? ''), \Osmium\Modules\Admin\Services\AdminPresence::AVATAR_PATH)],
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            [
                'key' => 'type',
                'label' => 'Type',
                'type' => 'text',
                'format' => fn(string $value): string => \ucfirst($value),
            ],
            ['key' => 'deleted_at', 'label' => 'Deleted', 'type' => 'date', 'nowrap' => true],
        ],
    ]),
];
