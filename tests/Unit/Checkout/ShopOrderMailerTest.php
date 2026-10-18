<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Core\Library\MailerInterface;
use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use PHPUnit\Framework\TestCase;

/**
 * The order confirmation a customer receives after paying: who it goes to,
 * what it says about their money, and that nothing they typed can break it.
 */
final class ShopOrderMailerTest extends TestCase
{
    private string $errorLog;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'mailerr');
        $this->previousErrorLog = \ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->previousErrorLog);
        \unlink($this->errorLog);
    }

    private function config(): object
    {
        return (object)[
            'site' => (object)['name' => 'Test Site', 'FQDN' => 'https://example.test'],
        ];
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jo Buyer',
            'order_ref' => 'SG2-1001',
            'currency' => 'GBP',
            'subtotal_exc_tax' => '100.00',
            'shipping_amount' => '5.00',
            'tax_amount' => '21.00',
            'total_inc_tax' => '126.00',
            'delivery_name' => 'Jo Buyer',
            'delivery_address1' => '1 Test Street',
            'delivery_address2' => null,
            'delivery_city' => 'Bristol',
            'delivery_county' => '',
            'delivery_postcode' => 'BS1 1AA',
            'items' => [
                ['product_title' => 'Solar Panel', 'quantity' => 2, 'line_total_exc_tax' => '100.00', 'line_tax_amount' => '20.00'],
            ],
        ];
    }

    private function recorder(bool $succeeds = true): object
    {
        return new class ($succeeds) implements MailerInterface {
            public array $sent = [];

            public function __construct(private bool $succeeds) {}

            public function send(array $recipients, string $subject, string $htmlBody): array
            {
                $this->sent[] = ['recipients' => $recipients, 'subject' => $subject, 'html' => $htmlBody];

                return $this->succeeds ? ['success' => true] : ['success' => false, 'error' => 'SMTP down'];
            }
        };
    }

    private function send(array $order, ?object $mailer = null): array
    {
        $mailer ??= $this->recorder();
        $mail = new ShopOrderMailer($this->config(), new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'), $mailer);
        $result = $mail->sendOrderConfirmation($order, invoiceNumber: 1042, respectSettings: false);

        return ['result' => $result, 'mailer' => $mailer];
    }

    public function test_it_goes_to_the_customer_with_the_order_reference_in_the_subject(): void
    {
        $sent = $this->send($this->order())['mailer']->sent[0];

        $this->assertSame([['email' => 'buyer@example.com', 'name' => 'Jo Buyer']], $sent['recipients']);
        $this->assertSame('Your Test Site order SG2-1001 is confirmed', $sent['subject']);
    }

    public function test_the_html_shows_the_money_the_customer_paid(): void
    {
        $html = $this->send($this->order())['mailer']->sent[0]['html'];

        $this->assertStringContainsString('£120.00', $html); // The line: 100.00 plus 20.00 VAT
        $this->assertStringContainsString('£100.00', $html);
        $this->assertStringContainsString('£5.00', $html);
        $this->assertStringContainsString('£21.00', $html);
        $this->assertStringContainsString('£126.00', $html);
        $this->assertStringContainsString('1042', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_anything_the_customer_typed_is_escaped_in_the_html(): void
    {
        $order = $this->order([
            'customer_name' => '<b>Jo</b>',
            'delivery_name' => '<script>x()</script>',
            'delivery_address1' => 'Flat 1 & 2',
            'delivery_city' => '"Bristol"',
            'items' => [['product_title' => 'Panel <Pro>', 'quantity' => 1, 'line_total_exc_tax' => '10', 'line_tax_amount' => '2']],
        ]);

        $html = $this->send($order)['mailer']->sent[0]['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Jo</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;x()&lt;/script&gt;', $html);
        $this->assertStringContainsString('Flat 1 &amp; 2', $html);
        $this->assertStringContainsString('Panel &lt;Pro&gt;', $html);
    }

    public function test_optional_address_lines_appear_only_when_filled_in(): void
    {
        $without = $this->send($this->order())['mailer']->sent[0]['html'];
        $with = $this->send($this->order(['delivery_address2' => 'Unit 4', 'delivery_county' => 'Somerset']))['mailer']->sent[0]['html'];

        $this->assertStringContainsString('1 Test Street<br>Bristol<br>BS1 1AA', $without);
        $this->assertStringContainsString('1 Test Street<br>Unit 4<br>Bristol<br>Somerset<br>BS1 1AA', $with);
    }

    public function test_each_line_shows_its_quantity_and_total_including_vat(): void
    {
        $order = $this->order(['items' => [
            ['product_title' => 'Panel', 'quantity' => 2, 'line_total_exc_tax' => '100.00', 'line_tax_amount' => '20.00'],
            ['product_title' => 'Cable', 'quantity' => 1, 'line_total_exc_tax' => '5.00', 'line_tax_amount' => '1.00'],
        ]]);

        $html = $this->send($order)['mailer']->sent[0]['html'];

        $this->assertStringContainsString('Panel &times; 2', $html);
        $this->assertStringContainsString('Cable &times; 1', $html);
        $this->assertStringContainsString('£6.00', $html);
    }

    public function test_a_failed_send_is_reported_not_thrown(): void
    {
        $sent = $this->send($this->order(), $this->recorder(succeeds: false));

        $this->assertFalse($sent['result']['success']);
        $this->assertSame('SMTP down', $sent['result']['error']);
        $this->assertStringContainsString('Order Confirmation Email Error: SMTP down', (string) \file_get_contents($this->errorLog));
    }

    public function test_a_successful_send_is_logged_as_nothing(): void
    {
        $this->send($this->order());

        $this->assertSame('', (string) \file_get_contents($this->errorLog));
    }
}
