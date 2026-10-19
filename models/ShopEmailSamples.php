<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;


require_once __DIR__ . '/ShopOrderMailer.php';

/**
 * The shop's sample emails for the admin Email settings test tool, offered through the email.samples hook.
 * Each handler builds the real email from dummy data (no database reads or writes) and sends it to the address given.
 */
final class ShopEmailSamples
{
    /** @param array<string, mixed> $payload @return array<int, array{id: string, label: string, handler: string, group: string}> */
    public static function samples(array $payload): array
    {
        return [
            ['id' => 'order_confirmation', 'label' => 'Order confirmation (sample data)', 'handler' => self::class . '::orderConfirmation', 'group' => 'Shop'],
            ['id' => 'order_dispatched', 'label' => 'Dispatch confirmation (sample data)', 'handler' => self::class . '::orderDispatched', 'group' => 'Shop'],
        ];
    }

    /** @param array{toEmail: string, osmium: object} $call @return array{success: bool, error?: string} */
    public static function orderConfirmation(array $call): array
    {
        $toEmail = $call['toEmail'];
        $osmium = $call['osmium'];

        $order = [
            'customer_email' => $toEmail,
            'customer_name' => 'Jane Doe',
            'order_ref' => 'SG-SAMPLE',
            'currency' => 'GBP',
            'subtotal_exc_tax' => 150.00,
            'shipping_amount' => 6.95,
            'tax_amount' => 31.39,
            'total_inc_tax' => 188.34,
            'delivery_name' => 'Jane Doe',
            'delivery_address1' => '1 Example Street',
            'delivery_address2' => null,
            'delivery_city' => 'Tewkesbury',
            'delivery_county' => 'Gloucestershire',
            'delivery_postcode' => 'GL20 8JH',
            'items' => [
                [
                    'product_title' => 'Example Product Name',
                    'quantity' => 1,
                    'line_total_exc_tax' => 133.33,
                    'line_tax_amount' => 26.67,
                ],
                [
                    'product_title' => 'MC4 Connector Pair',
                    'quantity' => 1,
                    'line_total_exc_tax' => 16.67,
                    'line_tax_amount' => 4.72,
                ],
            ],
        ];

        $mailer = new ShopOrderMailer($osmium->config, \Osmium\Core\Library\StoreFinance::forOsmium($osmium));

        return $mailer->sendOrderConfirmation($order, 9999, respectSettings: false);
    }

    /** @param array{toEmail: string, osmium: object} $call @return array{success: bool, error?: string} */
    public static function orderDispatched(array $call, ?ShopOrderMailer $mailer = null): array
    {
        $mailer ??= new ShopOrderMailer($call['osmium']->config, \Osmium\Core\Library\StoreFinance::forOsmium($call['osmium']));

        return $mailer->sendOrderDispatchedEmail(
            toEmail: $call['toEmail'],
            customerName: 'Jane Doe',
            orderRef: 'SG-SAMPLE',
            carrier: 'Royal Mail Tracked 24',
            trackingRef: 'RM123456789GB',
            respectSettings: false,
        );
    }
}
