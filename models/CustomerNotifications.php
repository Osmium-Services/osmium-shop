<?php

declare(strict_types=1);

namespace Osmium\Core\Library;

/**
 * Which messages a customer gets, and over which transport.
 *
 * Shop settings holds one on/off setting per event and transport
 * (shop.customerNotifications.<event>.<transport>). Email is built in. SMS is
 * only ever available through an installed provider service: it answers
 * `sms.providers` (id, label, ready, settingsRoute) so Shop settings can say
 * whether texts are possible, and `sms.send` to deliver one. Core never names
 * a provider, and only one SMS provider is expected to be installed.
 *
 * The email senders (ShopOrderMailer, AdminMailer) ask wants() before
 * sending. SMS is sent from here. Callers are the order-paid path
 * (ShopOrderService::markPaid) and the admin's mark-dispatched action.
 *
 * Texts are production-only: they cost money and reach a real phone, so dev,
 * staging and an unreadable environment never send. Best-effort: a failed
 * text is logged and never blocks the sale or the dispatch.
 */
class CustomerNotifications
{
    public const EMAIL = 'email';
    public const SMS = 'sms';
    public const TRANSPORTS = [self::EMAIL => 'Email', self::SMS => 'SMS'];

    /** Placeholders: {name} first name, {order_ref}, {total}, {tracking} (dispatched only, may be empty). */
    public const EVENTS = [
        'order_paid' => [
            'label' => 'Order paid',
            'help' => 'When payment is received',
            'sms' => 'Thanks {name}, we have received your payment for order {order_ref} ({total}).',
        ],
        'order_dispatched' => [
            'label' => 'Order dispatched',
            'help' => 'When you mark an order dispatched',
            'sms' => 'Hi {name}, your order {order_ref} has been dispatched.{tracking}',
        ],
    ];

    private const SHOP_CONFIG_PATH = 'app/config/modules/shop.json.php';
    private const SITE_CONFIG_PATH = 'app/config/config.json.php';

    /**
     * Whether this event is switched on for this transport. Unset means the
     * old behaviour: email on, anything else off.
     */
    public static function wants(string $event, string $transport): bool
    {
        $saved = self::shopConfig()['customerNotifications'][$event][$transport] ?? null;

        return $saved === null ? $transport === self::EMAIL : (bool) $saved;
    }

    /**
     * Every event and transport with its saved value, for the settings page.
     *
     * @return array<string, array<string, bool>>
     */
    public static function settings(): array
    {
        $matrix = [];
        foreach (\array_keys(self::EVENTS) as $event) {
            foreach (\array_keys(self::TRANSPORTS) as $transport) {
                $matrix[$event][$transport] = self::wants($event, $transport);
            }
        }

        return $matrix;
    }

    /**
     * The installed SMS provider's descriptor, or null when there is none.
     * Only one is expected, so the first answer is the one.
     *
     * @return array{id: string, label: string, ready: bool, settingsRoute: ?string}|null
     */
    public static function smsProvider(ServiceHooks $hooks): ?array
    {
        foreach ($hooks->collect('sms.providers') as $descriptor) {
            if (!\is_array($descriptor) || (string) ($descriptor['id'] ?? '') === '') continue;

            return [
                'id' => (string) $descriptor['id'],
                'label' => (string) ($descriptor['label'] ?? \ucfirst((string) $descriptor['id'])),
                'ready' => (bool) ($descriptor['ready'] ?? false),
                'settingsRoute' => $descriptor['settingsRoute'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Texts the customer about an event, when SMS is ticked for it.
     *
     * @param array<string, mixed> $order Needs customer_name, customer_phone, order_ref, total_inc_tax
     * @param array<string, mixed> $extra tracking_carrier and tracking_ref for order_dispatched
     */
    public static function sendSms(string $event, array $order, ServiceHooks $hooks, array $extra = []): void
    {
        if (!isset(self::EVENTS[$event]) || !self::wants($event, self::SMS)) return;

        $environment = (string) (self::readConfig(self::SITE_CONFIG_PATH)['site']['environment'] ?? '');
        $isProduction = !\in_array($environment, ['dev', 'staging', ''], true);
        if (!$isProduction) return;

        $phone = \trim((string) ($order['customer_phone'] ?? ''));
        if ($phone === '') return;

        $body = self::render(
            template: self::EVENTS[$event]['sms'],
            order: $order,
            currencySign: (string) (self::shopConfig()['currencySign'] ?? ''),
            extra: $extra,
        );

        try {
            $result = $hooks->handle('sms.send', ['to' => $phone, 'body' => $body]);
        } catch (\Throwable $e) {
            \error_log("{$event} SMS for {$order['order_ref']} failed: {$e->getMessage()}");
            return;
        }

        $failed = \is_array($result) && !($result['sent'] ?? false);
        if ($failed) \error_log("{$event} SMS for {$order['order_ref']}: " . ($result['detail'] ?? 'not sent'));
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $extra
     */
    public static function render(string $template, array $order, string $currencySign, array $extra = []): string
    {
        $parts = \preg_split('/\s+/', \trim((string) ($order['customer_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $parts[0] ?? 'there';
        $total = $currencySign . \number_format((float) ($order['total_inc_tax'] ?? 0), 2);

        $carrier = \trim((string) ($extra['tracking_carrier'] ?? ''));
        $reference = \trim((string) ($extra['tracking_ref'] ?? ''));
        $tracking = \trim($reference . ($carrier !== '' ? " ({$carrier})" : ''));

        return \strtr($template, [
            '{name}' => $firstName,
            '{order_ref}' => (string) ($order['order_ref'] ?? ''),
            '{total}' => $total,
            '{tracking}' => $tracking !== '' ? " Tracking: {$tracking}" : '',
        ]);
    }

    /**
     * Read from the file because the order-paid path is also reached from
     * service webhooks that hold no config object.
     *
     * @return array<string, mixed> The shop block of shop.json.php
     */
    private static function shopConfig(): array
    {
        return self::readConfig(self::SHOP_CONFIG_PATH)['shop'] ?? [];
    }

    /**
     * @return array<string, mixed> The decoded file, or [] when missing or unreadable
     */
    private static function readConfig(string $path): array
    {
        if (!\file_exists($path)) return [];

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        if ($jsonStart === false) return [];

        return \json_decode(\substr(string: $content, offset: $jsonStart), associative: true) ?? [];
    }
}
