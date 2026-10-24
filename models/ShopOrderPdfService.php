<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Core\Library\StoreFinance;

require_once __DIR__ . '/../../../../vendor/tcpdf/tcpdf.php';

/**
 * Generates despatch notes and invoices for shop orders as PDFs via TCPDF.
 *
 * Takes already-formatted order/item arrays (as produced by
 * OrdersController::formatOrder()/formatItem()) rather than querying the
 * database itself - PDF layout is this class's only job.
 */
class ShopOrderPdfService
{
    private const PAGE_WIDTH = 210;
    private const MARGIN = 15;
    private const CONTENT_WIDTH = self::PAGE_WIDTH - (2 * self::MARGIN);

    public function __construct(private object $config, private StoreFinance $finance)
    {
    }

    public function buildDespatchNote(array $order, array $items): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $this->configurePdf($pdf, 'Despatch Note');
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 10, 'Despatch Note', 0, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(90, 90, 90);
        $meta = "Order: {$order['order_ref']}    Invoice: {$order['invoice_display']}    Placed: {$order['created_at_formatted']}";
        $pdf->Cell(0, 6, $meta, 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);

        $contactLines = [
            'Email: ' . ($order['customer_email'] ?: 'Not supplied'),
            'Phone: ' . ($order['customer_phone'] ?: 'Not supplied'),
        ];
        $addressLines = \array_merge($this->deliveryAddressLines($order), [''], $contactLines);
        $this->renderBox(pdf: $pdf, x: self::MARGIN, width: 90, heading: 'Deliver To', lines: $addressLines);

        $pdf->Ln(10);
        $this->renderItemsTable(pdf: $pdf, items: $items, includePrices: false);

        return $pdf->Output('', 'S');
    }

    public function buildInvoice(array $order, array $items, string $siteName): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $this->configurePdf($pdf, 'Invoice');
        $pdf->AddPage();

        $this->renderCompanyHeader(pdf: $pdf, siteName: $siteName);
        $this->renderInvoiceMeta(pdf: $pdf, order: $order);

        $boxWidth = (self::CONTENT_WIDTH - 10) / 2;
        $billLines = \array_merge(
            [(string)$order['customer_name']],
            $this->billingAddressLines($order),
            [''],
            [
                'Email: ' . ($order['customer_email'] ?: 'Not supplied'),
                'Phone: ' . ($order['customer_phone'] ?: 'Not supplied'),
            ],
        );
        $deliverLines = $this->deliveryAddressLines($order);
        $boxesTop = $pdf->GetY();
        $tallestBoxHeight = \max($this->boxHeight($billLines), $this->boxHeight($deliverLines));

        $this->renderBox(pdf: $pdf, x: self::MARGIN, width: $boxWidth, heading: 'Bill To', lines: $billLines);

        $pdf->SetY($boxesTop);
        $this->renderBox(
            pdf: $pdf,
            x: self::MARGIN + $boxWidth + 10,
            width: $boxWidth,
            heading: 'Deliver To',
            lines: $deliverLines,
        );

        $pdf->SetY($boxesTop + $tallestBoxHeight);
        $pdf->Ln(10);
        $this->renderItemsTable(pdf: $pdf, items: $items, includePrices: true);
        $this->renderTotals(pdf: $pdf, order: $order);

        return $pdf->Output('', 'S');
    }

    private function configurePdf(\TCPDF $pdf, string $title): void
    {
        $pdf->SetCreator("{$this->config->site->name} Admin");
        $pdf->SetAuthor($this->config->site->name);
        $pdf->SetTitle($title);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
    }

    private function renderCompanyHeader(\TCPDF $pdf, string $siteName): void
    {
        $company = $this->config->company;

        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->Cell(90, 10, 'Invoice', 0, 0);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(self::CONTENT_WIDTH - 90, 6, $siteName, 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(100, 100, 100);

        $addressLines = \array_map('trim', \explode(',', (string)($company->address ?? '')));
        $lines = \array_merge($addressLines, [
            'Tel: ' . ($company->phone ?? ''),
            'Email: ' . ($company->email ?? ''),
            $this->finance->taxName() . ' Reg: ' . ($company->vatNumber ?? ''),
        ]);

        foreach ($lines as $line) {
            $pdf->Cell(90, 4, '', 0, 0);
            $pdf->Cell(self::CONTENT_WIDTH - 90, 4, $line, 0, 1, 'R');
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(6);
    }

    private function renderInvoiceMeta(\TCPDF $pdf, array $order): void
    {
        $pdf->SetFont('helvetica', '', 10);
        $meta = "Invoice: {$order['invoice_display']}    Order: {$order['order_ref']}    "
            . "Placed: {$order['created_at_formatted']}    Paid: {$order['paid_at_formatted']}";
        $pdf->Cell(0, 6, $meta, 0, 1);
        $pdf->Ln(4);
    }

    /**
     * A bordered box with a small-caps heading, matching the admin
     * despatch-note/invoice screens' "Deliver To" styling.
     */
    private function renderBox(\TCPDF $pdf, float $x, float $width, string $heading, array $lines): void
    {
        $y = $pdf->GetY();
        $padding = 4;
        $lineHeight = 5;
        $height = $this->boxHeight($lines);

        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect($x, $y, $width, $height, 'D');
        $pdf->SetDrawColor(0, 0, 0);

        $pdf->SetXY($x + $padding, $y + $padding);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell($width - (2 * $padding), 5, \strtoupper($heading), 0, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $lineY = $y + $padding + 6;
        foreach ($lines as $line) {
            $pdf->SetXY($x + $padding, $lineY);
            $pdf->Cell($width - (2 * $padding), $lineHeight, $line);
            $lineY += $lineHeight;
        }

        $pdf->SetY($y + $height);
    }

    private function boxHeight(array $lines): float
    {
        $padding = 4;
        $headingHeight = 6;
        $lineHeight = 5;

        return $headingHeight + (\count($lines) * $lineHeight) + (2 * $padding);
    }

    private function deliveryAddressLines(array $order): array
    {
        return $this->addressLines($order, 'delivery', (string)$order['delivery_name']);
    }

    private function billingAddressLines(array $order): array
    {
        return $this->addressLines($order, 'billing', null);
    }

    /**
     * @param string|null $nameLine Leading name line, or null to omit it
     *                              (billing already leads with customer_name).
     */
    /**
     * Address lines, one per printed row. Address1/2 are split on commas
     * too - customers sometimes paste a whole address into one field - and
     * the result is de-duplicated, since a comma-packed address1 often
     * already repeats the town given separately in the city field.
     */
    private function addressLines(array $order, string $prefix, ?string $nameLine): array
    {
        $lines = $nameLine !== null ? [$nameLine] : [];

        $lines = \array_merge($lines, $this->splitAddressPart((string)$order["{$prefix}_address1"]));

        $hasAddress2 = !empty($order["{$prefix}_address2"]);
        if ($hasAddress2) $lines = \array_merge($lines, $this->splitAddressPart((string)$order["{$prefix}_address2"]));

        $lines[] = (string)$order["{$prefix}_city"];

        $hasCounty = !empty($order["{$prefix}_county"]);
        if ($hasCounty) $lines[] = (string)$order["{$prefix}_county"];

        $lines[] = (string)$order["{$prefix}_postcode"];
        $lines[] = (string)$order["{$prefix}_country"];

        $lines = \array_filter($lines, fn ($line) => $line !== '');

        return $this->deduplicateCaseInsensitive($lines);
    }

    private function splitAddressPart(string $part): array
    {
        $pieces = \array_map('trim', \explode(',', $part));

        return \array_values(\array_filter($pieces, fn ($piece) => $piece !== ''));
    }

    private function deduplicateCaseInsensitive(array $lines): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($lines as $line) {
            $key = \strtolower($line);
            $alreadySeen = isset($seen[$key]);
            if ($alreadySeen) continue;

            $seen[$key] = true;
            $deduplicated[] = $line;
        }

        return $deduplicated;
    }

    private function renderItemsTable(\TCPDF $pdf, array $items, bool $includePrices): void
    {
        $taxName = $this->finance->taxName();
        $columns = $includePrices
            ? [['Item', 60], ['Ref', 30], ["Unit (exc. {$taxName})", 25, 'R'], ['Qty', 15, 'C'], ["Line (exc. {$taxName})", 25, 'R'], [$taxName, 25, 'R']]
            : [['', 12, 'C'], ['Item', 98], ['Ref', 40], ['Qty', 20, 'C']];
        $itemColumnIndex = $includePrices ? 0 : 1;
        $itemColumnWidth = $columns[$itemColumnIndex][1];

        $y = $this->renderTableHeader(pdf: $pdf, columns: $columns, y: $pdf->GetY());
        $lineHeight = 4.5;

        foreach ($items as $item) {
            $title = (string)$item['product_title'];
            $numLines = $pdf->getNumLines($title, $itemColumnWidth);
            $rowHeight = \max(7, $numLines * $lineHeight);

            // Rows are placed by hand, so the page break is made here rather than left to TCPDF:
            // its own break would reset the page but not this running $y.
            $rowDoesNotFit = $this->exceedsPage(pdf: $pdf, y: $y, height: $rowHeight);
            if ($rowDoesNotFit) {
                $pdf->AddPage();
                $y = $this->renderTableHeader(pdf: $pdf, columns: $columns, y: $pdf->getMargins()['top']);
            }

            $pdf->SetXY(self::MARGIN, $y);

            if ($includePrices) {
                $this->renderInvoiceRow($pdf, $item, $columns, $rowHeight);
            } else {
                $this->renderDespatchRow($pdf, $item, $columns, $rowHeight);
            }

            $y += $rowHeight;
        }

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetY($y);
    }

    /**
     * Draw the column headings at $y and return the y the first row starts at.
     * Repeated at the top of every continuation page.
     */
    private function renderTableHeader(\TCPDF $pdf, array $columns, float $y): float
    {
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY(self::MARGIN, $y);
        foreach ($columns as $column) {
            $pdf->Cell($column[1], 8, $column[0], 1, 0, $column[2] ?? 'L');
        }
        $pdf->SetFont('helvetica', '', 9);

        return $y + 8;
    }

    private function exceedsPage(\TCPDF $pdf, float $y, float $height): bool
    {
        $lastUsableY = $pdf->getPageHeight() - $pdf->getBreakMargin();

        return $y + $height > $lastUsableY;
    }

    private function renderItemCell(\TCPDF $pdf, string $title, float $width, float $rowHeight): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->MultiCell($width, $rowHeight, $title, 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowHeight, 'T');
        $pdf->SetXY($x + $width, $y);
    }

    private function renderDespatchRow(\TCPDF $pdf, array $item, array $columns, float $rowHeight): void
    {
        $pdf->Cell($columns[0][1], $rowHeight, '', 1, 0, 'C');
        $this->renderItemCell($pdf, (string)$item['product_title'], $columns[1][1], $rowHeight);
        $pdf->Cell($columns[2][1], $rowHeight, (string)$item['product_ref'], 1);
        $pdf->Cell($columns[3][1], $rowHeight, (string)(int)$item['quantity'], 1, 0, 'C');
    }

    private function renderInvoiceRow(\TCPDF $pdf, array $item, array $columns, float $rowHeight): void
    {
        $this->renderItemCell($pdf, (string)$item['product_title'], $columns[0][1], $rowHeight);
        $pdf->Cell($columns[1][1], $rowHeight, (string)$item['product_ref'], 1);
        $pdf->Cell($columns[2][1], $rowHeight, $item['unit_price_formatted'], 1, 0, 'R');
        $pdf->Cell($columns[3][1], $rowHeight, (string)(int)$item['quantity'], 1, 0, 'C');
        $pdf->Cell($columns[4][1], $rowHeight, $item['line_total_formatted'], 1, 0, 'R');
        $pdf->Cell($columns[5][1], $rowHeight, $item['line_tax_formatted'], 1, 0, 'R');
    }

    private function renderTotals(\TCPDF $pdf, array $order): void
    {
        $y = $pdf->GetY() + 6;
        $hasDiscount = (float)($order['discount_amount'] ?? 0) > 0;
        $totalsHeight = 4 * 6 + ($hasDiscount ? 8 : 0);
        $totalsDoNotFit = $this->exceedsPage(pdf: $pdf, y: $y, height: $totalsHeight);
        if ($totalsDoNotFit) {
            $pdf->AddPage();
            $y = $pdf->getMargins()['top'];
        }

        $labelWidth = 40;
        $valueWidth = 30;
        $x = self::MARGIN + self::CONTENT_WIDTH - $labelWidth - $valueWidth;
        $taxName = $this->finance->taxName();

        $rows = [
            ["Subtotal (exc. {$taxName})", $order['subtotal_formatted'], false],
            ["Delivery (exc. {$taxName})", $order['shipping_formatted'], false],
            [$taxName, $order['tax_formatted'], false],
            ['Total paid', $order['total_formatted'], true],
        ];

        foreach ($rows as [$label, $value, $isTotal]) {
            $pdf->SetFont('helvetica', $isTotal ? 'B' : '', $isTotal ? 11 : 10);
            $pdf->SetXY($x, $y);
            $pdf->Cell($labelWidth, 6, $label, 0, 0, 'R');
            $pdf->Cell($valueWidth, 6, $value, 0, 0, 'R');
            $y += 6;
        }

        if (!$hasDiscount) return;

        // A note, not a row: the prices above are already after the discount, so
        // an amount in the totals column would read as a second deduction.
        $code = (string)($order['discount_code'] ?? '');
        $name = $code !== '' ? "Discount {$code}" : 'Discount';
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY(self::MARGIN, $y + 2);
        $pdf->Cell(self::CONTENT_WIDTH, 5, "{$name} applied: {$order['discount_formatted']} off the goods (exc. {$taxName}), already included in the prices above", 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);
    }
}
