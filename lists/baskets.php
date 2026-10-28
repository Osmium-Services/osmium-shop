<?php

declare(strict_types=1);

/**
 * Baskets lists (active / abandoned / converted), rendered by
 * Osmium\Modules\Admin\Core\ListView. Rows are built by BasketsController
 * (different queries per list, so no 'query' here); their display fields come
 * from formatBasket(). Each row expands to show its items ('expand', served by
 * the controller's get_items action). Abandoned has a "Clean up old baskets"
 * header button, handled by baskets.js on list:action.
 *
 * Context: abandonedHours.
 */
$session = [
    'key' => 'session_id_short',
    'label' => 'Session',
    'type' => 'html',
    'fill' => true,
    'render' => fn(array $row, array $context): string => '<code title="' . \htmlspecialchars((string) ($row['session_id'] ?? '')) . '">'
        . \htmlspecialchars((string) $row['session_id_short']) . '...</code>',
];
$items = [
    'key' => 'total_items',
    'label' => 'Items',
    'type' => 'html',
    'nowrap' => true,
    'sortKey' => 'total_items',
    'render' => fn(array $row, array $context): string => '<span class="badge bg-label-secondary">' . (int) $row['total_items'] . ' items</span>',
];
$value = [
    'key' => 'total_value_formatted',
    'label' => 'Value',
    'type' => 'text',
    'strong' => true,
    'nowrap' => true,
    'sortKey' => 'total_value',
];
$date = fn(string $key, string $label, string $sortKey): array => [
    'key' => $key,
    'label' => $label,
    'type' => 'text',
    'small' => true,
    'nowrap' => true,
    'sortKey' => $sortKey,
];
$common = [
    'noun' => ['basket', 'baskets'],
    'endpoint' => 'shop/baskets/action/',
    'expand' => [
        'action' => 'get_items',
        'itemsKey' => 'items',
        'emptyText' => 'No items in basket',
        'columns' => [
            ['key' => 'product_title', 'label' => 'Product', 'strong' => true, 'sub' => 'product_ref'],
            ['key' => 'product_options', 'label' => 'Options', 'fallback' => '-'],
            ['key' => 'quantity', 'label' => 'Qty'],
            ['key' => 'unit_price_formatted', 'label' => 'Price'],
            ['key' => 'line_total_formatted', 'label' => 'Total', 'strong' => true],
        ],
    ],
];
$deleteAction = [
    ['label' => 'Delete', 'icon' => 'bx-trash', 'action' => 'delete', 'confirm' => 'Delete this basket?', 'danger' => true],
];

return [
    'active' => \array_merge($common, [
        'id' => 'active-baskets',
        'title' => 'Active Baskets',
        'order' => [4, 'desc'],
        'columns' => [$session, $items, $value, $date('created_at_formatted', 'Created', 'created_at'), $date('updated_at_formatted', 'Last Activity', 'updated_at')],
        'actions' => $deleteAction,
    ]),
    'abandoned' => \array_merge($common, [
        'id' => 'abandoned-baskets',
        'title' => 'Abandoned Baskets',
        'subtitle' => fn(array $context): string => 'No activity for ' . $context['abandonedHours'] . '+ hours',
        'headerButton' => ['label' => 'Clean up old baskets', 'icon' => 'bx-trash', 'class' => 'btn-outline-danger', 'event' => 'cleanup_old'],
        'hideWhenEmpty' => true,
        'rowClass' => 'table-danger',
        'order' => [3, 'desc'],
        'columns' => [$session, $items, $value, $date('updated_at_formatted', 'Abandoned', 'updated_at')],
        'actions' => $deleteAction,
    ]),
    'converted' => \array_merge($common, [
        'id' => 'converted-baskets',
        'title' => 'Converted Baskets',
        'subtitle' => 'Last 7 days',
        'hideWhenEmpty' => true,
        'rowClass' => 'table-success',
        'order' => [4, 'desc'],
        'columns' => [$session, $items, $value, $date('created_at_formatted', 'Created', 'created_at'), $date('updated_at_formatted', 'Converted', 'updated_at')],
    ]),
];
