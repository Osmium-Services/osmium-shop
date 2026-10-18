<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Services\ShopOrderPdfService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/ShopOrderPdfService.php';

/**
 * The invoice and despatch note are customer-facing documents, so these tests
 * read the text back out of the generated PDF rather than trusting that a
 * PDF was produced.
 */
class ShopOrderPdfServiceTest extends TestCase
{
    private function service(string $taxName = 'VAT'): ShopOrderPdfService
    {
        $config = (object) [
            'site' => (object) ['name' => 'Test Shop'],
            'company' => (object) [
                'address' => '1 High St, Bristol, BS1 1AA',
                'phone' => '0117 000 0000',
                'email' => 'hello@test.example',
                'vatNumber' => 'GB123456789',
            ],
        ];

        return new ShopOrderPdfService($config, new StoreFinance('GBP', '£', $taxName, 20.0, 'GB'));
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'order_ref' => 'SG2-1001',
            'invoice_display' => 'INV-42',
            'created_at_formatted' => '01 Oct 2026 10:00',
            'paid_at_formatted' => '01 Oct 2026 10:05',
            'customer_name' => 'Pat Customer',
            'customer_email' => 'pat@example.com',
            'customer_phone' => '07700 900123',
            'delivery_name' => 'Sam Recipient',
            'delivery_address1' => '5 Road Lane',
            'delivery_address2' => '',
            'delivery_city' => 'Bristol',
            'delivery_county' => '',
            'delivery_postcode' => 'BS1 1AA',
            'delivery_country' => 'United Kingdom',
            'billing_address1' => '9 Billing Street',
            'billing_address2' => '',
            'billing_city' => 'Leeds',
            'billing_county' => '',
            'billing_postcode' => 'LS1 1AA',
            'billing_country' => 'United Kingdom',
            'subtotal_formatted' => '£200.00',
            'shipping_formatted' => '£4.99',
            'tax_formatted' => '£41.00',
            'total_formatted' => '£245.99',
        ];
    }

    private function item(array $overrides = []): array
    {
        return $overrides + [
            'product_title' => 'Solar Panel 400W',
            'product_ref' => 'SP-400',
            'quantity' => 2,
            'unit_price_formatted' => '£100.00',
            'line_total_formatted' => '£200.00',
            'line_tax_formatted' => '£40.00',
        ];
    }

    /** Every text string drawn in the PDF, in order, decoded to UTF-8. */
    private function textOf(string $pdf): string
    {
        $text = '';
        \preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        foreach ($streams[1] as $stream) {
            $content = @\gzuncompress($stream);
            if ($content === false) continue;

            \preg_match_all('/\[\((.*?)\)\] TJ/s', $content, $drawn);
            foreach ($drawn[1] as $raw) {
                $unescaped = \preg_replace_callback(
                    '/\\\\([0-7]{3}|.)/s',
                    fn(array $m): string => \strlen($m[1]) === 3 ? \chr((int) \octdec($m[1])) : $m[1],
                    $raw,
                );
                $text .= \mb_convert_encoding($unescaped, 'UTF-8', 'Windows-1252') . "\n";
            }
        }

        return $text;
    }

    private function pageCount(string $pdf): int
    {
        return (int) \preg_match_all('/\/Type \/Page\b/', $pdf);
    }

    // ── invoice ──────────────────────────────────────────────────────────────

    public function test_an_invoice_is_a_complete_pdf(): void
    {
        $pdf = $this->service()->buildInvoice($this->order(), [$this->item()], 'Test Shop');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', \substr($pdf, -16));
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function test_the_invoice_names_the_order_the_invoice_and_both_dates(): void
    {
        $text = $this->textOf($this->service()->buildInvoice($this->order(), [$this->item()], 'Test Shop'));

        $this->assertStringContainsString('Invoice: INV-42', $text);
        $this->assertStringContainsString('Order: SG2-1001', $text);
        $this->assertStringContainsString('Placed: 01 Oct 2026 10:00', $text);
        $this->assertStringContainsString('Paid: 01 Oct 2026 10:05', $text);
    }

    public function test_the_invoice_header_carries_the_company_details(): void
    {
        $text = $this->textOf($this->service()->buildInvoice($this->order(), [$this->item()], 'Test Shop'));

        $this->assertStringContainsString('Test Shop', $text);
        $this->assertStringContainsString("1 High St\n", $text); // The one-line address is printed one part per row
        $this->assertStringContainsString("BS1 1AA\n", $text);
        $this->assertStringContainsString('Tel: 0117 000 0000', $text);
        $this->assertStringContainsString('Email: hello@test.example', $text);
        $this->assertStringContainsString('VAT Reg: GB123456789', $text);
    }

    public function test_the_invoice_uses_the_configured_tax_name_throughout(): void
    {
        $text = $this->textOf($this->service(taxName: 'GST')->buildInvoice($this->order(), [$this->item()], 'Test Shop'));

        $this->assertStringContainsString('GST Reg: GB123456789', $text);
        $this->assertStringContainsString('Subtotal (exc. GST)', $text);
        $this->assertStringContainsString('Delivery (exc. GST)', $text);
        $this->assertStringContainsString('Unit (exc. GST)', $text);
        $this->assertStringNotContainsString('VAT', $text);
    }

    public function test_the_invoice_prints_bill_to_and_deliver_to_separately(): void
    {
        $text = $this->textOf($this->service()->buildInvoice($this->order(), [$this->item()], 'Test Shop'));

        $this->assertStringContainsString('BILL TO', $text);
        $this->assertStringContainsString('DELIVER TO', $text);
        $this->assertStringContainsString('9 Billing Street', $text);
        $this->assertStringContainsString('Leeds', $text);
        $this->assertStringContainsString('Sam Recipient', $text);
        $this->assertStringContainsString('5 Road Lane', $text);
    }

    public function test_the_invoice_lists_each_item_with_its_prices(): void
    {
        $items = [
            $this->item(),
            $this->item(['product_title' => 'Battery Monitor', 'product_ref' => 'BM-1', 'quantity' => 1, 'unit_price_formatted' => '£39.95']),
        ];

        $text = $this->textOf($this->service()->buildInvoice($this->order(), $items, 'Test Shop'));

        $this->assertStringContainsString('Solar Panel 400W', $text);
        $this->assertStringContainsString('SP-400', $text);
        $this->assertStringContainsString('£100.00', $text);
        $this->assertStringContainsString('Battery Monitor', $text);
        $this->assertStringContainsString('BM-1', $text);
        $this->assertStringContainsString('£39.95', $text);
    }

    public function test_the_invoice_totals_are_the_stored_figures_in_order(): void
    {
        $text = $this->textOf($this->service()->buildInvoice($this->order(), [$this->item()], 'Test Shop'));

        $subtotal = \strpos($text, '£200.00', \strpos($text, 'Subtotal'));
        $delivery = \strpos($text, '£4.99');
        $tax = \strpos($text, '£41.00');
        $total = \strpos($text, '£245.99');

        $this->assertNotFalse($subtotal);
        $this->assertTrue($subtotal < $delivery && $delivery < $tax && $tax < $total, 'Totals must read subtotal, delivery, tax, total');
        $this->assertStringContainsString('Total paid', $text);
    }

    public function test_missing_contact_details_read_not_supplied(): void
    {
        $order = $this->order(['customer_email' => '', 'customer_phone' => '']);

        $text = $this->textOf($this->service()->buildInvoice($order, [$this->item()], 'Test Shop'));

        $this->assertStringContainsString('Email: Not supplied', $text);
        $this->assertStringContainsString('Phone: Not supplied', $text);
    }

    public function test_awkward_characters_in_customer_data_survive(): void
    {
        $order = $this->order(['customer_name' => "Pat O'Neil & Sons (Café) <ltd>"]);

        $text = $this->textOf($this->service()->buildInvoice($order, [$this->item(['product_title' => 'Panel (400W) \\ 5"'])], 'Test Shop'));

        $this->assertStringContainsString("Pat O'Neil & Sons (Café) <ltd>", $text);
        $this->assertStringContainsString('Panel (400W) \\ 5"', $text);
    }

    public function test_a_billing_address_packed_into_one_field_is_split_and_deduplicated(): void
    {
        $order = $this->order(['billing_address1' => '9 Billing Street, Leeds', 'billing_city' => 'LEEDS']);

        $text = $this->textOf($this->service()->buildInvoice($order, [$this->item()], 'Test Shop'));

        $this->assertStringContainsString("9 Billing Street\n", $text);
        $this->assertSame(1, \substr_count(\strtolower($text), "leeds\n"), 'The town was printed twice');
    }

    // ── despatch note ────────────────────────────────────────────────────────

    public function test_a_despatch_note_is_a_complete_pdf(): void
    {
        $pdf = $this->service()->buildDespatchNote($this->order(), [$this->item()]);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', \substr($pdf, -16));
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function test_the_despatch_note_says_what_to_pack_and_where_to_send_it(): void
    {
        $text = $this->textOf($this->service()->buildDespatchNote($this->order(), [$this->item()]));

        $this->assertStringContainsString('Despatch Note', $text);
        $this->assertStringContainsString('Order: SG2-1001', $text);
        $this->assertStringContainsString('DELIVER TO', $text);
        $this->assertStringContainsString('Sam Recipient', $text);
        $this->assertStringContainsString('BS1 1AA', $text);
        $this->assertStringContainsString('Solar Panel 400W', $text);
        $this->assertStringContainsString('SP-400', $text);
        $this->assertStringContainsString('Email: pat@example.com', $text);
        $this->assertStringContainsString('Phone: 07700 900123', $text);
    }

    public function test_the_despatch_note_shows_no_prices_and_no_billing_details(): void
    {
        $text = $this->textOf($this->service()->buildDespatchNote($this->order(), [$this->item()]));

        $this->assertStringNotContainsString('£', $text);
        $this->assertStringNotContainsString('BILL TO', $text);
        $this->assertStringNotContainsString('9 Billing Street', $text);
        $this->assertStringNotContainsString('Total paid', $text);
    }

    public function test_a_delivery_address_with_a_repeated_town_prints_it_once(): void
    {
        $order = $this->order(['delivery_address1' => '5 Road Lane, Bristol', 'delivery_city' => 'bristol']);

        $text = $this->textOf($this->service()->buildDespatchNote($order, [$this->item()]));

        $this->assertSame(1, \substr_count(\strtolower($text), "bristol\n"));
    }

    public function test_the_county_and_second_address_line_are_printed_when_present(): void
    {
        $order = $this->order(['delivery_address2' => 'Unit 7', 'delivery_county' => 'Somerset']);

        $text = $this->textOf($this->service()->buildDespatchNote($order, [$this->item()]));

        $this->assertStringContainsString("Unit 7\n", $text);
        $this->assertStringContainsString("Somerset\n", $text);
    }

    // ── long orders ──────────────────────────────────────────────────────────

    private function manyItems(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = $this->item(['product_title' => "Item number {$i}", 'product_ref' => "R{$i}"]);
        }

        return $items;
    }

    public function test_a_long_invoice_runs_onto_as_few_pages_as_its_rows_need(): void
    {
        $pdf = $this->service()->buildInvoice($this->order(), $this->manyItems(40), 'Test Shop');

        $this->assertLessThanOrEqual(2, $this->pageCount($pdf), 'Every row after the first page break was put on a page of its own');
        $this->assertStringContainsString('Item number 40', $this->textOf($pdf));
    }

    public function test_a_long_despatch_note_runs_onto_as_few_pages_as_its_rows_need(): void
    {
        $pdf = $this->service()->buildDespatchNote($this->order(), $this->manyItems(60));

        $this->assertLessThanOrEqual(2, $this->pageCount($pdf), 'Every row after the first page break was put on a page of its own');
        $this->assertStringContainsString('Item number 60', $this->textOf($pdf));
    }

    public function test_the_column_headings_repeat_on_every_continuation_page(): void
    {
        $pdf = $this->service()->buildInvoice($this->order(), $this->manyItems(40), 'Test Shop');

        $this->assertSame($this->pageCount($pdf), \substr_count($this->textOf($pdf), "Qty\n"));
    }

    public function test_the_totals_are_never_lost_whatever_page_the_last_row_lands_on(): void
    {
        foreach (\range(15, 30) as $count) {
            $pdf = $this->service()->buildInvoice($this->order(), $this->manyItems($count), 'Test Shop');
            $text = $this->textOf($pdf);

            $this->assertSame(1, \substr_count($text, 'Total paid'), "{$count} items: totals missing or repeated");
            $this->assertStringContainsString('£245.99', $text);
            $this->assertStringContainsString("Item number {$count}\n", $text);
            $this->assertLessThanOrEqual(2, $this->pageCount($pdf), "{$count} items spread over too many pages");
        }
    }
}
