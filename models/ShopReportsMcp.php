<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

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
    public static function productsAttention(array $call): array
    {
        return new Product($call['dataSource'], 'products')->getAttentionReport(25);
    }
}
