<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Core\Library\MailerInterface;
use Osmium\Core\Library\PlainTextMailer;
use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Admin\Services\AdminMailer;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use PHPUnit\Framework\TestCase;

class ShopOrderMailerPlainTextTest extends TestCase
{
    private function config(): object
    {
        return (object)[
            'site' => (object)['name' => 'Test Site', 'FQDN' => 'https://example.test'],
        ];
    }

    private function order(): array
    {
        return [
            'customer_email' => 'buyer@example.com',
            'customer_name' => "Jo O'Brien",
            'order_ref' => 'SG2-1001',
            'currency' => 'GBP',
            'subtotal_exc_tax' => '100.00',
            'shipping_amount' => '5.00',
            'tax_amount' => '21.00',
            'total_inc_tax' => '126.00',
            'delivery_name' => "Jo O'Brien",
            'delivery_address1' => '1 Test Street',
            'delivery_address2' => null,
            'delivery_city' => 'Bristol',
            'delivery_county' => '',
            'delivery_postcode' => 'BS1 1AA',
            'items' => [
                ['product_title' => 'Solar Panel <Pro>', 'quantity' => 2, 'line_total_exc_tax' => '100.00', 'line_tax_amount' => '20.00'],
            ],
        ];
    }

    private function textMailer(): object
    {
        return new class implements PlainTextMailer {
            public array $sent = [];

            public function send(array $recipients, string $subject, string $htmlBody, ?string $textBody = null): array
            {
                $this->sent[] = ['to' => $recipients[0]['email'], 'html' => $htmlBody, 'text' => $textBody];

                return ['success' => true];
            }
        };
    }

    public function test_order_confirmation_carries_a_readable_plain_text_part(): void
    {
        $mailer = $this->textMailer();
        $finance = new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB');

        $result = (new ShopOrderMailer($this->config(), $finance, $mailer))
            ->sendOrderConfirmation($this->order(), invoiceNumber: 1001, respectSettings: false);

        $this->assertTrue($result['success']);
        $text = $mailer->sent[0]['text'];
        $this->assertStringContainsString("Jo O'Brien", $text);
        $this->assertStringContainsString('Order SG2-1001 - Invoice 1001', $text);
        $this->assertStringContainsString('Solar Panel <Pro> x 2: £120.00', $text);
        $this->assertStringContainsString("Total: £126.00", $text);
        $this->assertStringContainsString("1 Test Street\nBristol\nBS1 1AA", $text);
        $this->assertStringNotContainsString('{{', $text);
        $this->assertStringNotContainsString('&', $text);
    }

    public function test_html_only_transport_still_gets_the_confirmation_html(): void
    {
        $mailer = new class implements MailerInterface {
            public array $sent = [];

            public function send(array $recipients, string $subject, string $htmlBody): array
            {
                $this->sent[] = $htmlBody;

                return ['success' => true];
            }
        };

        $result = (new ShopOrderMailer($this->config(), new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'), $mailer))
            ->sendOrderConfirmation($this->order(), invoiceNumber: 1001, respectSettings: false);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SG2-1001', $mailer->sent[0]);
    }

    public function test_dispatched_email_carries_tracking_in_plain_text(): void
    {
        $mailer = $this->textMailer();

        (new AdminMailer($this->config(), $mailer))->sendOrderDispatchedEmail(
            toEmail: 'buyer@example.com',
            customerName: "Jo O'Brien",
            orderRef: 'SG2-1001',
            carrier: 'DPD',
            trackingRef: 'TRK123',
            respectSettings: false,
        );

        $text = $mailer->sent[0]['text'];
        $this->assertStringContainsString('Order SG2-1001 has been dispatched.', $text);
        $this->assertStringContainsString("Carrier: DPD\nTracking reference: TRK123", $text);
        $this->assertStringNotContainsString('{{', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_dispatched_email_without_tracking_has_no_leftover_placeholder(): void
    {
        $mailer = $this->textMailer();

        (new AdminMailer($this->config(), $mailer))->sendOrderDispatchedEmail(
            toEmail: 'buyer@example.com',
            customerName: 'Jo',
            orderRef: 'SG2-1002',
            carrier: null,
            trackingRef: null,
            respectSettings: false,
        );

        $this->assertStringNotContainsString('{{', $mailer->sent[0]['text']);
        $this->assertStringNotContainsString('Carrier:', $mailer->sent[0]['text']);
    }
}
