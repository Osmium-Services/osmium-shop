<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the Discount Management list, rendered by
 * Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same vocabulary as lists/discounts.php
 * but nothing is shared with it. The actions are a copy of the list's for now.
 */
return [
    'columns' => 4,
    'title' => [
        'key' => 'name',
        'type' => 'text',
        'strong' => true,
        'sub' => ['key' => 'typeLabel', 'style' => 'muted'],
    ],
    'meta' => [
        ['key' => 'statusBadge', 'type' => 'raw'],
        ['key' => 'valueLabel', 'type' => 'text', 'strong' => true, 'sub' => ['key' => 'usageLabel', 'style' => 'muted']],
        ['key' => 'windowLabel', 'type' => 'text', 'small' => true],
    ],
    'actionsAt' => 'bottom-end',
    'actions' => [
        ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'shop/discounts/edit/?id={id}'],
        ['label' => 'Duplicate', 'icon' => 'bx-copy', 'action' => 'duplicate', 'submit' => 'form'],
        [
            'label' => 'Delete',
            'icon' => 'bx-trash',
            'action' => 'delete',
            'confirm' => 'Delete this discount?',
            'danger' => true,
        ],
    ],
];
