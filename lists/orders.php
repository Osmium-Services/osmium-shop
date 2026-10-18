<?php

declare(strict_types=1);

/**
 * Orders list definition, rendered by Osmium\Modules\Admin\Core\ListView.
 * Row fields (status_label/class, is_test_payment, total_formatted,
 * created_at_formatted, invoice_display) are prepared by
 * OrdersController::formatOrder(). The status filter is server-side
 * (reloads with ?status=); the controller validates it against a whitelist.
 *
 * Context: statusFilter, statuses.
 */
return [
    'orders' => [
        'id' => 'orders',
        'title' => 'All Orders',
        'icon' => 'bx-receipt',
        'noun' => ['order', 'orders'],
        'endpoint' => 'shop/orders/action/',
        'order' => null,
        'emptyText' => 'No orders',
        'filters' => [
            [
                'key' => 'status',
                'label' => 'Status',
                'server' => true,
                'param' => 'status',
                'selected' => fn(array $context): ?string => $context['statusFilter'],
                'options' => function (array $rows, array $context): array {
                    $options = [['', 'All']];
                    foreach ($context['statuses'] as $status) {
                        $options[] = [$status, \ucfirst($status)];
                    }

                    return $options;
                },
            ],
        ],
        'columns' => [
            [
                'key' => 'order_ref',
                'label' => 'Order',
                'type' => 'text',
                'strong' => true,
                'nowrap' => true,
                'sub' => ['key' => 'invoice_display', 'style' => 'muted'],
            ],
            [
                'key' => 'status_label',
                'label' => 'Status',
                'type' => 'html',
                'nowrap' => true,
                'render' => function (array $row, array $context): string {
                    $html = '<span class="badge ' . \htmlspecialchars((string) $row['status_class']) . '">'
                        . \htmlspecialchars((string) $row['status_label']) . '</span>';
                    if ($row['is_test_payment']) {
                        $html .= ' <span class="badge bg-label-secondary" title="Paid through a '
                            . 'provider\'s sandbox/test mode — not real money">Test</span>';
                    }

                    return $html;
                },
            ],
            [
                'key' => 'customer_name',
                'label' => 'Customer',
                'type' => 'text',
                'fill' => true,
                'sub' => ['key' => 'customer_email', 'style' => 'muted'],
            ],
            [
                'key' => 'delivery_postcode',
                'label' => 'Delivery',
                'type' => 'text',
                'nowrap' => true,
                'sub' => ['key' => 'delivery_zone', 'style' => 'muted'],
            ],
            [
                'key' => 'total_formatted',
                'label' => 'Total',
                'type' => 'raw',
                'align' => 'end',
                'nowrap' => true,
                'sortKey' => 'total_inc_tax',
            ],
            [
                'key' => 'created_at_formatted',
                'label' => 'Placed',
                'type' => 'text',
                'small' => true,
                'nowrap' => true,
                'sortKey' => 'created_at',
            ],
        ],
        'actions' => [
            ['label' => 'View', 'icon' => 'bx-show', 'href' => 'shop/orders/view/?id={id}'],
            [
                'label' => 'Print Despatch Note',
                'icon' => 'bx-printer',
                'href' => 'shop/orders/despatch-note/?id={id}',
                'target' => '_blank',
                'visible' => fn(array $row): bool => \in_array($row['status'], ['paid', 'dispatched'], true),
            ],
        ],
    ],
];
