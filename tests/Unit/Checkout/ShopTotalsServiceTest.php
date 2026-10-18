<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShopTotalsService
 *
 * The totals engine is pure arithmetic over resolved lines, so it needs no
 * database. What it does need is to be pinned down exactly: the basket page,
 * the checkout page, the payment provider and the stored order all total up
 * through here, and a penny of divergence between them is a payment dispute.
 */
class ShopTotalsServiceTest extends TestCase
{
    /**
     * A fully configured service, matching the shipped shop.json.php defaults.
     */
    private function service(?float $freeDeliveryThreshold = null): ShopTotalsService
    {
        return new ShopTotalsService(
            taxRatePercent: 20.0,
            deliveryCharge: 6.95,
            highlandsSurcharge: 3.00,
            surchargePostcodes: ['AB31-AB38', 'HS', 'IV', 'KA27-KA28', 'PH15-PH26', 'ZE'],
            excludedPostcodes: ['GY', 'JE'],
            freeDeliveryThreshold: $freeDeliveryThreshold,
        );
    }

    private function line(float $unitPrice, int $quantity): array
    {
        return ['unit_price_exc_tax' => $unitPrice, 'quantity' => $quantity];
    }

    // =========================================================================
    // Zone resolution
    // =========================================================================

    public function test_mainland_postcode_resolves_to_mainland(): void
    {
        $zone = $this->service()->resolveZone('GL20 8JH');

        $this->assertSame(expected: ShopTotalsService::ZONE_MAINLAND, actual: $zone);
    }

    public function test_northern_ireland_is_mainland_not_surcharged(): void
    {
        $zone = $this->service()->resolveZone('BT1 5GS');

        $this->assertSame(expected: ShopTotalsService::ZONE_MAINLAND, actual: $zone); // BT is deliberately absent from the surcharge list
    }

    public function test_highlands_area_only_pattern_matches(): void
    {
        $zone = $this->service()->resolveZone('IV3 5SN');

        $this->assertSame(expected: ShopTotalsService::ZONE_HIGHLANDS, actual: $zone);
    }

    public function test_district_range_matches_inside_the_range(): void
    {
        $zone = $this->service()->resolveZone('AB35 5XY');

        $this->assertSame(expected: ShopTotalsService::ZONE_HIGHLANDS, actual: $zone);
    }

    public function test_a_single_district_pattern_matches_only_that_district(): void
    {
        $service = new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 6.95, highlandsSurcharge: 3.00, surchargePostcodes: ['KA27']);

        $this->assertSame(expected: ShopTotalsService::ZONE_HIGHLANDS, actual: $service->resolveZone('KA27 8AA'));
        $this->assertSame(expected: ShopTotalsService::ZONE_MAINLAND, actual: $service->resolveZone('KA28 0AA')); // Next district along is not covered
        $this->assertSame(expected: ShopTotalsService::ZONE_MAINLAND, actual: $service->resolveZone('KA1 1AA'));
    }

    public function test_district_range_does_not_match_outside_the_range(): void
    {
        $zone = $this->service()->resolveZone('AB10 1AA');

        $this->assertSame(expected: ShopTotalsService::ZONE_MAINLAND, actual: $zone); // Aberdeen city is not Highlands
    }

    public function test_channel_islands_are_excluded_not_surcharged(): void
    {
        $guernsey = $this->service()->resolveZone('GY1 1AA');
        $jersey = $this->service()->resolveZone('JE2 3AA');

        $this->assertSame(expected: ShopTotalsService::ZONE_EXCLUDED, actual: $guernsey);
        $this->assertSame(expected: ShopTotalsService::ZONE_EXCLUDED, actual: $jersey); // Outside the UK VAT area — must never fall through to a delivery rate
    }

    public function test_postcode_is_normalised_before_matching(): void
    {
        $zone = $this->service()->resolveZone(' iv3-5sn ');

        $this->assertSame(expected: ShopTotalsService::ZONE_HIGHLANDS, actual: $zone);
    }

    public function test_invalid_postcode_resolves_to_unknown(): void
    {
        $zone = $this->service()->resolveZone('NOT A POSTCODE');

        $this->assertSame(expected: ShopTotalsService::ZONE_UNKNOWN, actual: $zone);
    }

    public function test_missing_postcode_resolves_to_unknown(): void
    {
        $zone = $this->service()->resolveZone(null);

        $this->assertSame(expected: ShopTotalsService::ZONE_UNKNOWN, actual: $zone);
    }

    // =========================================================================
    // Delivery
    // =========================================================================

    public function test_mainland_delivery_is_the_base_charge(): void
    {
        $totals = $this->service()->calculate([$this->line(100.00, 1)], 'GL20 8JH');

        $this->assertSame(expected: 6.95, actual: $totals['delivery_exc_tax']);
    }

    public function test_highlands_delivery_adds_the_surcharge(): void
    {
        $totals = $this->service()->calculate([$this->line(100.00, 1)], 'IV3 5SN');

        $this->assertSame(expected: 9.95, actual: $totals['delivery_exc_tax']);
    }

    public function test_excluded_postcode_is_not_deliverable_and_carries_no_charge(): void
    {
        $totals = $this->service()->calculate([$this->line(100.00, 1)], 'JE2 3AA');

        $this->assertFalse($totals['deliverable']);
        $this->assertSame(expected: 0.0, actual: $totals['delivery_exc_tax']);
    }

    public function test_empty_basket_carries_no_delivery(): void
    {
        $totals = $this->service()->calculate([], 'GL20 8JH');

        $this->assertSame(expected: 0.0, actual: $totals['delivery_exc_tax']);
        $this->assertSame(expected: 0.0, actual: $totals['total_inc_tax']);
    }

    public function test_free_delivery_threshold_is_off_by_default(): void
    {
        $totals = $this->service()->calculate([$this->line(500.00, 1)], 'GL20 8JH');

        $this->assertSame(expected: 6.95, actual: $totals['delivery_exc_tax']); // Threshold is null in config — every order carries delivery
    }

    public function test_free_delivery_threshold_applies_when_enabled(): void
    {
        $totals = $this->service(freeDeliveryThreshold: 250.00)->calculate([$this->line(500.00, 1)], 'GL20 8JH');

        $this->assertSame(expected: 0.0, actual: $totals['delivery_exc_tax']);
    }

    public function test_free_delivery_threshold_does_not_apply_below_it(): void
    {
        $totals = $this->service(freeDeliveryThreshold: 250.00)->calculate([$this->line(100.00, 1)], 'GL20 8JH');

        $this->assertSame(expected: 6.95, actual: $totals['delivery_exc_tax']);
    }

    // =========================================================================
    // Money
    // =========================================================================

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $totals = $this->service()->calculate([$this->line(19.99, 3)], 'GL20 8JH');

        $this->assertSame(expected: 59.97, actual: $totals['lines'][0]['line_total_exc_tax']);
    }

    public function test_subtotal_sums_the_rounded_line_totals(): void
    {
        $totals = $this->service()->calculate([
            $this->line(19.99, 3),
            $this->line(5.50, 2),
        ], 'GL20 8JH');

        $this->assertSame(expected: 70.97, actual: $totals['subtotal_exc_tax']);
    }

    public function test_vat_is_the_sum_of_rounded_per_line_amounts_not_vat_on_the_subtotal(): void
    {
        $totals = $this->service()->calculate([
            $this->line(0.03, 1),
            $this->line(0.03, 1),
            $this->line(0.03, 1),
        ], 'GY1 1AA'); // Excluded zone keeps delivery out of the figure

        $perLineVat = 0.03; // 0.006 rounds to 0.01 on each of the three lines
        $vatOnSubtotal = \round(0.09 * 0.2, 2); // 0.02 — a penny adrift

        $this->assertSame(expected: $perLineVat, actual: $totals['tax_amount']);
        $this->assertNotSame(expected: $vatOnSubtotal, actual: $totals['tax_amount']); // The two genuinely differ, which is why the order of operations is fixed
    }

    public function test_grand_total_reconciles_with_the_per_line_vat(): void
    {
        $totals = $this->service()->calculate([
            $this->line(0.03, 1),
            $this->line(0.03, 1),
            $this->line(0.03, 1),
        ], 'GY1 1AA');

        $this->assertSame(expected: 0.12, actual: $totals['total_inc_tax']); // 0.09 + 0.03, not 0.09 + 0.02
    }

    public function test_delivery_is_vatable(): void
    {
        $totals = $this->service()->calculate([$this->line(100.00, 1)], 'GL20 8JH');

        $this->assertSame(expected: 1.39, actual: $totals['delivery_tax_amount']);
        $this->assertSame(expected: 21.39, actual: $totals['tax_amount']);
        $this->assertSame(expected: 128.34, actual: $totals['total_inc_tax']);
    }

    public function test_grand_total_is_subtotal_plus_delivery_plus_vat(): void
    {
        $totals = $this->service()->calculate([
            $this->line(19.99, 3),
            $this->line(5.50, 2),
        ], 'IV3 5SN');

        $expected = \round(70.97 + 9.95 + $totals['tax_amount'], 2);

        $this->assertSame(expected: $expected, actual: $totals['total_inc_tax']);
    }

    public function test_item_count_is_units_not_lines(): void
    {
        $totals = $this->service()->calculate([
            $this->line(19.99, 3),
            $this->line(5.50, 2),
        ]);

        $this->assertSame(expected: 5, actual: $totals['item_count']);
    }

    // =========================================================================
    // Unconfigured delivery
    // =========================================================================

    public function test_delivery_is_reported_as_configured_when_a_charge_is_set(): void
    {
        $totals = $this->service()->calculate([$this->line(100.00, 1)], 'GL20 8JH');

        $this->assertTrue($totals['delivery_configured']);
    }

    public function test_missing_delivery_charge_is_flagged_rather_than_quoted_as_free(): void
    {
        $service = ShopTotalsService::fromConfig([], $this->finance()); // shop.json.php is per-environment and never deployed, so this happens

        $totals = $service->calculate([$this->line(100.00, 1)], 'GL20 8JH');

        $this->assertFalse($totals['delivery_configured']); // £0.00 here means "cannot quote", and the caller must not read it as free delivery
        $this->assertSame(expected: 0.0, actual: $totals['delivery_exc_tax']);
    }

    public function test_a_charge_of_zero_is_configured_not_missing(): void
    {
        $service = ShopTotalsService::fromConfig([
            'deliveryCharge' => 0,
        ], $this->finance());

        $totals = $service->calculate([$this->line(100.00, 1)], 'GL20 8JH');

        $this->assertTrue($totals['delivery_configured']); // Deliberate free delivery is a decision, not an omission
    }

    public function test_config_factory_reads_the_shop_block(): void
    {
        $service = ShopTotalsService::fromConfig([
            'deliveryCharge' => 6.95,
            'deliverySurchargeHighlands' => 3.00,
            'deliverySurchargePostcodes' => ['ZE'],
            'deliveryExcludedPostcodes' => ['JE'],
            'freeDeliveryThreshold' => null,
        ], $this->finance());

        $this->assertSame(expected: ShopTotalsService::ZONE_HIGHLANDS, actual: $service->resolveZone('ZE1 0AA'));
        $this->assertSame(expected: ShopTotalsService::ZONE_EXCLUDED, actual: $service->resolveZone('JE2 3AA'));
    }

    private function finance(float $taxRatePercent = 20.0): StoreFinance
    {
        return new StoreFinance(
            currencyCode: 'GBP',
            currencySign: '£',
            taxName: 'VAT',
            taxRatePercent: $taxRatePercent,
            country: 'GB',
        );
    }

    public function test_config_factory_takes_the_vat_rate_from_the_finance_settings(): void
    {
        // A stray taxRatePercent in the checkout block is ignored: the rate lives in StoreFinance only
        $service = ShopTotalsService::fromConfig(['taxRatePercent' => '5', 'deliveryCharge' => 0], $this->finance(15.0));

        $this->assertSame(expected: 15.0, actual: $service->taxRatePercent());
    }
}

