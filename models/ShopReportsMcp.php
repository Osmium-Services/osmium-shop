<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Core\Library\PaymentProviders;
use Osmium\Modules\Admin\Models\Order;
use Osmium\Modules\Admin\Models\Product;

// Loaded by path, so it is present however the /mcp request was booted.
require_once __DIR__ . '/PaymentProviders.php';

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
                'name' => 'shop_config_summary',
                'description' => 'The shop\'s own settings: which payment providers installed services offer and whether each is ready, which one is chosen, delivery charge, free delivery threshold, highlands surcharge and how many surcharge and excluded postcodes are set, currency, tax, country, search, basket abandonment hours, order reference prefix, whether the delivery message and order confirmation note are set, and the email and SMS switches for order notifications. A value still at its shipped placeholder shows as not set. No credentials, message text or postcode lists. Provider credentials and the services\' own settings are not included. Read only.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::shopConfigSummary',
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
    public static function shopConfigSummary(array $call): array
    {
        $osmium = $call['osmium'];
        $external = [];
        $hosted = [];
        try {
            $external = PaymentProviders::external($osmium);
            $hosted = PaymentProviders::hosted($osmium);
        } catch (\Throwable) {
            // A broken provider handler leaves the payment section empty rather than failing the whole summary.
        }

        $checkout = self::asArray($osmium->config->checkout ?? null);

        return self::shapeConfig(
            self::asArray($osmium->config->shop ?? null),
            $checkout,
            $external,
            PaymentProviders::configured($checkout, $external),
            $hosted,
        );
    }

    /**
     * Built on an allowlist: only the fields named here are returned. Lists become counts, free text becomes a
     * flag, and a value still at its shipped TBD_ placeholder counts as not set.
     *
     * @param array<string, mixed> $shop the shop block of the config
     * @param array<string, mixed> $checkout the checkout block of the config
     * @param array<string, array<string, mixed>> $external payment providers from installed services, by id
     * @param array<int, array<string, mixed>> $hosted services that take payment on their own page
     * @return array<string, mixed>
     */
    public static function shapeConfig(array $shop, array $checkout, array $external, string $chosen, array $hosted = []): array
    {
        $providers = [];
        foreach ($external as $id => $provider) {
            $providers[] = ['id' => (string) $id, 'label' => \mb_substr((string) ($provider['label'] ?? $id), 0, 60), 'ready' => (bool) ($provider['ready'] ?? false)];
        }

        $notifications = [];
        foreach ((array) ($shop['customerNotifications'] ?? []) as $event => $channels) {
            $notifications[(string) $event] = ['email' => (bool) ($channels['email'] ?? false), 'sms' => (bool) ($channels['sms'] ?? false)];
        }

        return [
            'payment' => [
                'chosen' => $chosen,
                'chosen_ready' => $chosen !== PaymentProviders::NONE && (bool) ($external[$chosen]['ready'] ?? false),
                'providers_offered' => $providers,
                'hosted_payment_services' => \array_map(static fn(array $h): string => \mb_substr((string) ($h['label'] ?? ''), 0, 60), $hosted),
            ],
            'delivery' => [
                'charge' => (float) ($checkout['deliveryCharge'] ?? 0),
                'free_delivery_threshold' => isset($checkout['freeDeliveryThreshold']) ? (float) $checkout['freeDeliveryThreshold'] : null,
                'highlands_surcharge' => (float) ($checkout['deliverySurchargeHighlands'] ?? 0),
                'surcharge_postcodes' => self::count($checkout['deliverySurchargePostcodes'] ?? null),
                'excluded_postcodes' => self::count($checkout['deliveryExcludedPostcodes'] ?? null),
            ],
            'shop' => [
                'currency' => self::text($shop['currency'] ?? null),
                'tax_rate_percent' => isset($shop['taxRatePercent']) ? (float) $shop['taxRatePercent'] : null,
                'tax_name' => self::text($shop['taxName'] ?? null),
                'country' => self::text($shop['country'] ?? null),
                'search_enabled' => (bool) ($shop['searchEnabled'] ?? false),
                'basket_abandoned_hours' => isset($shop['basketAbandonedHours']) ? (int) $shop['basketAbandonedHours'] : null,
                'order_ref_prefix' => self::text($shop['orderRefPrefix'] ?? null),
                'delivery_message_set' => self::isSet($shop['deliveryMessage'] ?? null),
                'order_confirmation_note_set' => self::isSet($shop['orderConfirmationNote'] ?? null),
            ],
            'order_notifications' => $notifications,
        ];
    }

    /** A list's size. A string is a comma list, and a shipped TBD_ placeholder or blank counts as none. */
    private static function count(mixed $value): int
    {
        if (\is_array($value)) return \count($value);
        if (!self::isSet($value)) return 0;

        return \count(\array_filter(\array_map('trim', \explode(',', (string) $value)), static fn(string $item): bool => $item !== ''));
    }

    private static function isSet(mixed $value): bool
    {
        if (\is_string($value)) return \trim($value) !== '' && !\str_starts_with($value, 'TBD_');

        return $value !== null && $value !== false;
    }

    private static function text(mixed $value): ?string
    {
        return self::isSet($value) && \is_string($value) ? \mb_substr($value, 0, 60) : null;
    }

    /** @return array<string, mixed> */
    private static function asArray(mixed $section): array
    {
        $decoded = \json_decode((string) \json_encode($section), true);

        return \is_array($decoded) ? $decoded : [];
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
