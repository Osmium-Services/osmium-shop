<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Core\Library\StoreFinance;

use Osmium\Core\Library\BrandLogo;
use Osmium\Core\Library\CustomerNotifications;
use Osmium\Core\Library\MailDelivery;
use Osmium\Core\Library\MailerFactory;
use Osmium\Core\Library\MailerInterface;

/**
 * ShopOrderMailer
 *
 * Sends the order confirmation, the first thing a customer hears from us
 * after paying. Deliberately separate from AdminMailer — that class lives in
 * the admin module and this one is triggered from the shop's own checkout
 * and webhook paths, which build their own mail send rather than
 * reaching into another module.
 */
class ShopOrderMailer
{
    private const SITE_TEMPLATE_DIR = 'app/theme/email/'; // a site's own branded copy wins over the shop's default

    public function __construct(
        private object $config,
        private StoreFinance $finance,
        private ?MailerInterface $mailer = null,
    ) {}

    /**
     * @param array $order Output of ShopOrderService::getOrder() — the order
     *                      row plus an 'items' key
     * @param int $invoiceNumber Assigned by markPaid(), passed in rather than
     *                           re-read so the caller's own idempotency guard
     *                           is the only place that decides "has this been
     *                           sent yet"
     * @param bool $respectSettings False for the admin's sample send, which
     *                              must go out whatever Shop settings say
     * @return array{success: bool, error?: string, skipped?: bool}
     */
    public function sendOrderConfirmation(array $order, int $invoiceNumber, bool $respectSettings = true): array
    {
        $switchedOff = $respectSettings && !CustomerNotifications::wants('order_paid', CustomerNotifications::EMAIL);
        if ($switchedOff) return ['success' => true, 'skipped' => true];

        $htmlVars = $this->htmlVars(order: $order, invoiceNumber: $invoiceNumber);
        $html = $this->renderTemplate(filename: 'order-confirmation.html', vars: $htmlVars);

        $textVars = $this->textVars(order: $order, invoiceNumber: $invoiceNumber);
        $text = $this->renderTemplate(filename: 'order-confirmation.txt', vars: $textVars);

        $result = MailDelivery::send(
            mailer: $this->mailer ?? MailerFactory::make($this->config),
            recipients: [['email' => $order['customer_email'], 'name' => $order['customer_name']]],
            subject: "Your {$this->config->site->name} order {$order['order_ref']} is confirmed",
            htmlBody: $html,
            textBody: $text,
        );

        if (!$result['success']) \error_log('Order Confirmation Email Error: ' . $result['error']);

        return $result;
    }

    /**
     * Notify a customer their order has shipped. Returns the real error on
     * failure rather than swallowing it, so OrdersController can tell staff
     * the order was marked dispatched but the email itself didn't go out.
     *
     * @param bool $respectSettings False for the admin's sample send, which
     *                              must go out whatever Shop settings say
     * @return array{success: bool, error?: string, skipped?: bool}
     */
    public function sendOrderDispatchedEmail(
        string $toEmail,
        string $customerName,
        string $orderRef,
        ?string $carrier,
        ?string $trackingRef,
        bool $respectSettings = true,
    ): array {
        $switchedOff = $respectSettings && !CustomerNotifications::wants('order_dispatched', CustomerNotifications::EMAIL);
        if ($switchedOff) return ['success' => true, 'skipped' => true];

        $hasTracking = !empty($carrier) || !empty($trackingRef);
        $trackingLineHtml = $hasTracking
            ? '<table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 30px 0;">'
                . '<tr><td style="padding: 4px 0; color: #666; font-size: 14px;">Carrier</td>'
                . '<td style="padding: 4px 0; color: #333; font-size: 14px; text-align: right;">'
                    . \htmlspecialchars($carrier ?: 'Not specified') . '</td></tr>'
                . '<tr><td style="padding: 4px 0; color: #666; font-size: 14px;">Tracking reference</td>'
                . '<td style="padding: 4px 0; color: #333; font-size: 14px; text-align: right;">'
                    . \htmlspecialchars($trackingRef ?: 'Not specified') . '</td></tr>'
                . '</table>'
            : '';

        $html = $this->renderTemplate(filename: 'order-dispatched.html', vars: [
            'logoUrl' => BrandLogo::emailUrl($this->config->site->FQDN),
            'customerName' => \htmlspecialchars($customerName),
            'orderRef' => \htmlspecialchars($orderRef),
            'trackingLine' => $trackingLineHtml,
            'siteName' => $this->config->site->name,
        ]);

        $trackingLineText = $hasTracking
            ? 'Carrier: ' . ($carrier ?: 'Not specified') . "\nTracking reference: " . ($trackingRef ?: 'Not specified')
            : '';

        $text = $this->renderTemplate(filename: 'order-dispatched.txt', vars: [
            'customerName' => $customerName,
            'orderRef' => $orderRef,
            'trackingLine' => $trackingLineText,
            'siteName' => $this->config->site->name,
        ]);

        $result = MailDelivery::send(
            mailer: $this->mailer ?? MailerFactory::make($this->config),
            recipients: [['email' => $toEmail, 'name' => $customerName]],
            subject: "Your order {$orderRef} has been dispatched",
            htmlBody: $html,
            textBody: $text,
        );

        if (!$result['success']) \error_log('Order Dispatched Email Error: ' . $result['error']);

        return $result;
    }

    private function htmlVars(array $order, int $invoiceNumber): array
    {
        $itemRows = '';
        foreach ($order['items'] as $item) {
            $lineTotal = (float)$item['line_total_exc_tax'] + (float)$item['line_tax_amount'];
            $itemRows .= '<tr>'
                . '<td style="padding: 10px 0; border-bottom: 1px solid #eee; color: #333;">'
                    . \htmlspecialchars($item['product_title']) . ' &times; ' . (int)$item['quantity']
                . '</td>'
                . '<td style="padding: 10px 0; border-bottom: 1px solid #eee; color: #333; text-align: right;">'
                    . $this->money($lineTotal, $order['currency'])
                . '</td>'
                . '</tr>';
        }

        $deliveryAddress = \implode('<br>', \array_filter([
            \htmlspecialchars($order['delivery_name']),
            \htmlspecialchars($order['delivery_address1']),
            $order['delivery_address2'] ? \htmlspecialchars($order['delivery_address2']) : null,
            \htmlspecialchars($order['delivery_city']),
            $order['delivery_county'] ? \htmlspecialchars($order['delivery_county']) : null,
            \htmlspecialchars($order['delivery_postcode']),
        ]));

        return [
            'logoUrl' => BrandLogo::emailUrl($this->config->site->FQDN),
            'customerName' => \htmlspecialchars($order['customer_name']),
            'orderRef' => \htmlspecialchars($order['order_ref']),
            'invoiceNumber' => (string)$invoiceNumber,
            'itemRows' => $itemRows,
            'subtotal' => $this->money((float)$order['subtotal_exc_tax'], $order['currency']),
            'delivery' => $this->money((float)$order['shipping_amount'], $order['currency']),
            'taxName' => $this->finance->taxName(),
            'tax' => $this->money((float)$order['tax_amount'], $order['currency']),
            'total' => $this->money((float)$order['total_inc_tax'], $order['currency']),
            'deliveryAddress' => $deliveryAddress,
            'customerNote' => $this->customerNoteHtml(),
        ];
    }

    private function textVars(array $order, int $invoiceNumber): array
    {
        $itemLines = [];
        foreach ($order['items'] as $item) {
            $lineTotal = (float)$item['line_total_exc_tax'] + (float)$item['line_tax_amount'];
            $quantity = (int)$item['quantity'];
            $price = $this->money(amount: $lineTotal, currency: $order['currency']);
            $itemLines[] = "{$item['product_title']} x {$quantity}: {$price}";
        }

        $deliveryAddress = \implode("\n", \array_filter([
            $order['delivery_name'],
            $order['delivery_address1'],
            $order['delivery_address2'] ?: null,
            $order['delivery_city'],
            $order['delivery_county'] ?: null,
            $order['delivery_postcode'],
        ]));

        return [
            'customerName' => $order['customer_name'],
            'orderRef' => $order['order_ref'],
            'invoiceNumber' => (string)$invoiceNumber,
            'itemRows' => \implode("\n", $itemLines),
            'subtotal' => $this->money((float)$order['subtotal_exc_tax'], $order['currency']),
            'delivery' => $this->money((float)$order['shipping_amount'], $order['currency']),
            'taxName' => $this->finance->taxName(),
            'tax' => $this->money((float)$order['tax_amount'], $order['currency']),
            'total' => $this->money((float)$order['total_inc_tax'], $order['currency']),
            'deliveryAddress' => $deliveryAddress,
            'customerNote' => $this->customerNoteText(),
        ];
    }

    /** The shop's optional note for the order confirmation (Shop settings), '' when blank. */
    private function customerNote(): string
    {
        return \trim((string)($this->config->shop->orderConfirmationNote ?? ''));
    }

    private function customerNoteHtml(): string
    {
        $note = $this->customerNote();
        if ($note === '') return '';

        return '<p style="color: #666; font-size: 16px; line-height: 1.5; margin: 0 0 20px 0;">'
            . \nl2br(\htmlspecialchars($note))
            . '</p>';
    }

    private function customerNoteText(): string
    {
        $note = $this->customerNote();

        return $note === '' ? '' : $note . "\n\n";
    }

    private function money(float $amount, string $currency): string
    {
        return $this->finance->formatInCurrency(amount: $amount, currencyCode: $currency);
    }

    private function renderTemplate(string $filename, array $vars): string
    {
        $path = __DIR__ . '/../templates/email/' . $filename; // the shop's default
        $siteOverrideExists = \is_file(self::SITE_TEMPLATE_DIR . $filename);
        if ($siteOverrideExists) $path = self::SITE_TEMPLATE_DIR . $filename;

        $template = \file_get_contents($path);
        $templateMissing = $template === false;
        if ($templateMissing) throw new \RuntimeException("Email template not found: {$path}");

        $siteName = $this->config->site->name ?? '';
        $prepared = ['{{siteName}}' => $siteName];
        foreach ($vars as $key => $value) {
            $prepared['{{' . $key . '}}'] = $value;
        }

        return \strtr($template, $prepared);
    }
}
