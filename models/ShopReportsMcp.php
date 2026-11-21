<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Models\Order;
use Osmium\Modules\Admin\Models\Product;

/**
 * The shop's read-only report tools for the MCP server: order totals and the products needing attention.
 * Offered through the mcp.tools hook, so a site without the shop never lists them.
 */
class ShopReportsMcp
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        return [
            [
                'name' => 'orders_summary',
                'description' => 'Order counts and values (inc VAT) per status for today, the last 7 days and the last 30 days, plus the 10 newest orders. Test payments excluded. No customer details.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::ordersSummary',
            ],
            [
                'name' => 'order_get',
                'description' => 'One order by its exact reference (as orders_summary lists it): status, whether it was a test payment, payment provider and method, the provider\'s order and capture references, subtotal, shipping, tax, discount, total, delivery zone and country, carrier and tracking reference, whether terms were accepted and the order synced to the accounts package, key dates, and the lines bought. Never returns a name, email, phone, address, postcode, IP address or admin notes. Read only.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['order_ref' => ['type' => 'string', 'description' => 'The exact order reference']],
                    'required' => ['order_ref'],
                ],
                'handler' => self::class . '::orderGet',
            ],
            [
                'name' => 'products_attention',
                'description' => 'Live products needing attention: out of stock, restocking (with date), priced at zero, or in no category. Stock here is a status, not a quantity. Each list shows the total and up to 25 products.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::productsAttention',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function ordersSummary(array $call): array
    {
        $orders = new Order($call['dataSource'], 'shop_orders');

        return ['totals' => $orders->getPeriodTotals(), 'newest' => $orders->getRecentAnonymous(10)];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function orderGet(array $call): array
    {
        $ref = $call['arguments']['order_ref'] ?? null;
        if (!\is_string($ref) || \trim($ref) === '' || \strlen($ref) > 20) {
            throw new McpToolException('Give the exact order_ref, as orders_summary lists it.');
        }

        $orders = new Order($call['dataSource'], 'shop_orders');
        $order = $orders->getAnonymousByRef(\trim($ref));
        if ($order === null) throw new McpToolException('There is no order with that reference.');

        return self::shapeOrder($order, $orders->getItemsAnonymous((int) $order['id']));
    }

    /**
     * @param array<string, mixed> $order a row from order-get-by-ref-anonymous.sql
     * @param array<int, array<string, mixed>> $items rows from order-items-anonymous.sql
     * @return array<string, mixed>
     */
    public static function shapeOrder(array $order, array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'product_ref' => (string) $item['product_ref'],
                'title' => (string) $item['product_title'],
                'options' => $item['product_options'],
                'quantity' => (int) $item['quantity'],
                'unit_price_exc_tax' => (float) $item['unit_price_exc_tax'],
                'line_total_exc_tax' => (float) $item['line_total_exc_tax'],
                'line_tax' => (float) $item['line_tax_amount'],
            ];
        }

        return [
            'order_ref' => (string) $order['order_ref'],
            'status' => (string) $order['status'],
            'test_payment' => (bool) $order['is_test_payment'],
            'payment' => [
                'provider' => $order['payment_provider'],
                'method' => $order['payment_method'],
                'provider_order_ref' => $order['payment_order_ref'],
                'provider_capture_ref' => $order['payment_capture_ref'],
            ],
            'money' => [
                'currency' => (string) $order['currency'],
                'subtotal_exc_tax' => (float) $order['subtotal_exc_tax'],
                'shipping' => (float) $order['shipping_amount'],
                'tax' => (float) $order['tax_amount'],
                'tax_rate_percent' => (float) $order['tax_rate_percent'],
                'discount_code' => $order['discount_code'],
                'discount' => (float) $order['discount_amount'],
                'total_inc_tax' => (float) $order['total_inc_tax'],
            ],
            'delivery' => [
                'zone' => (string) $order['delivery_zone'],
                'country' => (string) $order['delivery_country'],
                'carrier' => $order['tracking_carrier'],
                'tracking_ref' => $order['tracking_ref'],
            ],
            'terms_accepted' => $order['terms_accepted_at'] !== null,
            'accounts_synced' => $order['xero_synced_at'] !== null,
            'created_at' => $order['created_at'],
            'paid_at' => $order['paid_at'],
            'dispatched_at' => $order['dispatched_at'],
            'items' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function productsAttention(array $call): array
    {
        return new Product($call['dataSource'], 'products')->getAttentionReport(25);
    }
}
