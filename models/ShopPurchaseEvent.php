<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * The plain figures of a paid order, handed to the thank-you page once for
 * services that report a sale in the browser (the osmium:shop-event contract).
 * Everything is a scalar; unit prices include tax at the rate recorded on the
 * order line, so a later rate change cannot alter what a sale reports.
 */
class ShopPurchaseEvent
{
    /**
     * @param array $paidOrder An order with its 'items', as ShopOrderService::getOrder() returns it
     * @return array{order_ref: string, value: float, currency: string, items: list<array>}
     */
    public static function fromOrder(array $paidOrder): array
    {
        return [
            'order_ref' => $paidOrder['order_ref'],
            'value' => (float)$paidOrder['total_inc_tax'],
            'currency' => $paidOrder['currency'],
            'items' => \array_map(fn (array $item): array => [
                'product_id' => (int)$item['product_id'],
                'title' => $item['product_title'],
                'quantity' => (int)$item['quantity'],
                'unit_price_inc_tax' => \round((float)$item['unit_price_exc_tax'] * (1 + (float)$item['tax_rate_percent'] / 100), 2),
            ], $paidOrder['items']),
        ];
    }
}
