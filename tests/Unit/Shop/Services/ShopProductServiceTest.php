<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Shop\Services\ShopProductService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShopProductService
 *
 * Covers:
 * - formatPriceIncTax() - adds tax and formats
 * - formatPriceExcTax() - formats without tax
 * - calculateSavings() - calculates savings vs RRP
 * - loadKitProduct() - loads related kit products
 * - buildLegacyOptions() - legacy option format
 * - buildOptionTypesJson() - JSON config for JavaScript
 * - addSalesTax() - private tax calculation
 */
class ShopProductServiceTest extends TestCase
{
    private function createService(float $taxRate = 20.0): ShopProductService
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        return new ShopProductService($mockDb, taxRatePercent: $taxRate);
    }

    // =========================================================================
    // formatPriceIncTax()
    // =========================================================================

    public function test_format_price_inc_tax_adds_twenty_percent_tax(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->formatPriceIncTax(priceExcTax: 100.00);

        $this->assertEquals('120.00', $result);
    }

    public function test_format_price_inc_tax_formats_to_two_decimals(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->formatPriceIncTax(priceExcTax: 99.99);

        $this->assertEquals('119.99', $result);
    }

    public function test_format_price_inc_tax_handles_zero(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->formatPriceIncTax(priceExcTax: 0.00);

        $this->assertEquals('0.00', $result);
    }

    // =========================================================================
    // formatPriceExcTax()
    // =========================================================================

    public function test_format_price_exc_tax_formats_to_two_decimals(): void
    {
        $service = $this->createService();

        $result = $service->formatPriceExcTax(priceExcTax: 99.996);

        $this->assertEquals('100.00', $result);
    }

    public function test_format_price_exc_tax_handles_round_numbers(): void
    {
        $service = $this->createService();

        $result = $service->formatPriceExcTax(priceExcTax: 100.00);

        $this->assertEquals('100.00', $result);
    }

    // =========================================================================
    // calculateSavings()
    // =========================================================================

    public function test_calculate_savings_returns_amount_and_percentage(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->calculateSavings(priceExcTax: 80.00, rrpExcTax: 100.00);

        $this->assertEquals('24.00', $result['savings']);
        $this->assertEquals(20, $result['savingsPercent']);
    }

    public function test_calculate_savings_handles_equal_prices(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->calculateSavings(priceExcTax: 100.00, rrpExcTax: 100.00);

        $this->assertEquals('0.00', $result['savings']);
        $this->assertEquals(0, $result['savingsPercent']);
    }

    public function test_calculate_savings_handles_negative_savings(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->calculateSavings(priceExcTax: 120.00, rrpExcTax: 100.00);

        $this->assertEquals('-24.00', $result['savings']);
    }

    public function test_calculate_savings_guards_against_zero_rrp(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $result = $service->calculateSavings(priceExcTax: 50.00, rrpExcTax: 0.00);

        $this->assertEquals(0, $result['savingsPercent']);
    }

    // =========================================================================
    // loadKitProduct()
    // =========================================================================

    public function test_load_kit_product_returns_null_for_null_kit_id(): void
    {
        $service = $this->createService();

        $result = $service->loadKitProduct(kitId: null, loadProductFn: fn() => []);

        $this->assertNull($result);
    }

    public function test_load_kit_product_returns_null_for_zero_kit_id(): void
    {
        $service = $this->createService();

        $result = $service->loadKitProduct(kitId: 0, loadProductFn: fn() => []);

        $this->assertNull($result);
    }

    public function test_load_kit_product_returns_null_for_missing_kit(): void
    {
        $service = $this->createService();

        $loadFn = fn($ids) => [];

        $result = $service->loadKitProduct(kitId: 42, loadProductFn: $loadFn);

        $this->assertNull($result);
    }

    public function test_load_kit_product_returns_name_and_url_from_callback(): void
    {
        $service = $this->createService();

        $loadFn = fn($ids) => [
            42 => [
                'brand_name' => 'Victron',
                'model_name' => 'MultiPlus',
                'url' => '/shop/victron/multiplus',
            ],
        ];

        $result = $service->loadKitProduct(kitId: 42, loadProductFn: $loadFn);

        $this->assertEquals('Victron MultiPlus', $result['name']);
        $this->assertEquals('/shop/victron/multiplus', $result['url']);
    }

    // =========================================================================
    // buildLegacyOptions()
    // =========================================================================

    public function test_build_legacy_options_returns_all_null_for_empty_input(): void
    {
        $service = $this->createService();

        $result = $service->buildLegacyOptions(optionTypes: []);

        $this->assertNull($result['options']);
        $this->assertNull($result['optionLabel']);
        $this->assertNull($result['suboptions']);
    }

    public function test_build_legacy_options_maps_first_option(): void
    {
        $service = $this->createService();

        $optionTypes = [
            [
                'label' => 'Size',
                'defaultDescr' => 'Medium',
                'values' => ['Small', 'Medium', 'Large'],
                'weightsCSV' => '1.0,2.0,3.0',
                'premiumsCSV' => '0,5,10',
                'rrpsCSV' => '10,15,20',
            ],
        ];

        $result = $service->buildLegacyOptions(optionTypes: $optionTypes);

        $this->assertEquals('Size', $result['optionLabel']);
        $this->assertEquals('Medium', $result['optionDefault']);
        $this->assertEquals(['Small', 'Medium', 'Large'], $result['options']);
        $this->assertEquals('1.0,2.0,3.0', $result['optionWeights']);
    }

    public function test_build_legacy_options_maps_second_option_to_suboptions(): void
    {
        $service = $this->createService();

        $optionTypes = [
            [
                'label' => 'Size',
                'defaultDescr' => 'Medium',
                'values' => ['Small', 'Large'],
                'weightsCSV' => '1.0,2.0',
                'premiumsCSV' => '0,10',
                'rrpsCSV' => '10,20',
            ],
            [
                'label' => 'Color',
                'values' => ['Red', 'Blue'],
                'weightsCSV' => '0.1,0.2',
                'premiumsCSV' => '2,4',
                'rrpsCSV' => '5,8',
            ],
        ];

        $result = $service->buildLegacyOptions(optionTypes: $optionTypes);

        $this->assertEquals('Color', $result['suboptionLabel']);
        $this->assertEquals(['Red', 'Blue'], $result['suboptions']);
        $this->assertEquals('0.1,0.2', $result['suboptionWeights']);
    }

    // =========================================================================
    // buildOptionTypesJson()
    // =========================================================================

    public function test_build_option_types_json_returns_valid_json(): void
    {
        $service = $this->createService();

        $optionTypes = [
            [
                'id' => 1,
                'slug' => 'size',
                'label' => 'Size',
                'defaultDescr' => 'Medium',
                'values' => [
                    ['option_rrp' => '10', 'option_premium' => '0', 'option_packed_weight' => '1.0', 'option_descr' => 'Small', 'option_reference' => 'S'],
                ],
            ],
        ];

        $result = $service->buildOptionTypesJson(optionTypes: $optionTypes);

        $this->assertJson($result);
        $decoded = json_decode($result, associative: true);
        $this->assertEquals(1, $decoded[0]['id']);
        $this->assertEquals('size', $decoded[0]['slug']);
    }

    public function test_build_option_types_json_handles_empty_input(): void
    {
        $service = $this->createService();

        $result = $service->buildOptionTypesJson(optionTypes: []);

        $this->assertEquals('[]', $result);
    }

    // =========================================================================
    // addSalesTax() via Reflection
    // =========================================================================

    public function test_add_sales_tax_applies_correct_percentage(): void
    {
        $service = $this->createService(taxRate: 20.0);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('addSalesTax');

        $result = $method->invoke($service, 100.00);

        $this->assertEquals(120.00, $result);
    }

    public function test_add_sales_tax_uses_custom_tax_rate(): void
    {
        $service = $this->createService(taxRate: 15.0);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('addSalesTax');

        $result = $method->invoke($service, 100.00);

        $this->assertEqualsWithDelta(115.00, $result, delta: 0.01);
    }

    // =========================================================================
    // schemaPriceIncTax()
    // =========================================================================

    public function test_schema_price_inc_tax_has_no_thousands_separator(): void
    {
        $service = $this->createService();

        $this->assertSame('1440.00', $service->schemaPriceIncTax(1200.00)); // JSON-LD wants a plain number
        $this->assertSame('99.99', $service->schemaPriceIncTax(83.325));
    }

    // =========================================================================
    // buildOptionTypes()
    // =========================================================================

    private function rawValue(array $overrides = []): array
    {
        return $overrides + [
            'option_descr' => 'Black',
            'option_reference' => 'BLK',
            'option_premium' => '10.00',
            'option_rrp' => '15.00',
            'option_packed_weight' => '1.5',
            'linked_product_id' => null,
            'linked_product_restock_at' => null,
        ];
    }

    private function rawType(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'type_label' => 'Colour',
            'type_slug' => 'colour',
            'default_option_descr' => 'Choose a colour',
            'linked_product_id' => null,
            'linked_product_descr' => null,
            'linked_image_slot' => null,
            'linked_product_title' => null,
            'linked_product_description' => null,
            'linked_product_brand' => null,
            'linked_product_model' => null,
            'linked_product_url' => null,
            'linked_product_restock_at' => null,
            'values' => [$this->rawValue()],
        ];
    }

    public function test_build_option_types_returns_empty_for_no_options(): void
    {
        $this->assertSame([], $this->createService()->buildOptionTypes([], '£'));
    }

    public function test_build_option_types_shapes_a_type_and_its_values(): void
    {
        $types = $this->createService()->buildOptionTypes([$this->rawType()], '£');

        $this->assertCount(1, $types);
        $this->assertSame(7, $types[0]['id']);
        $this->assertSame('Colour', $types[0]['label']);
        $this->assertSame('colour', $types[0]['slug']);
        $this->assertSame('Choose a colour', $types[0]['defaultDescr']);
        $this->assertSame(0, $types[0]['index']);
        $this->assertSame('+£12.00', $types[0]['values'][0]['priceIncTax']); // 10.00 plus 20% VAT
    }

    public function test_build_option_types_builds_comma_separated_lists_for_the_price_script(): void
    {
        $type = $this->rawType(['values' => [
            $this->rawValue(['option_premium' => '0.00', 'option_rrp' => '0.00', 'option_packed_weight' => '0']),
            $this->rawValue(['option_premium' => '25.50', 'option_rrp' => '30.00', 'option_packed_weight' => '2.5']),
        ]]);

        $types = $this->createService()->buildOptionTypes([$type], '£');

        $this->assertSame('0,2.5', $types[0]['weightsCSV']);
        $this->assertSame('0.00,25.50', $types[0]['premiumsCSV']);
        $this->assertSame('0.00,30.00', $types[0]['rrpsCSV']);
    }

    public function test_build_option_types_numbers_each_type_in_order(): void
    {
        $types = $this->createService()->buildOptionTypes([$this->rawType(['id' => 1]), $this->rawType(['id' => 2])], '£');

        $this->assertSame([0, 1], [$types[0]['index'], $types[1]['index']]);
        $this->assertSame([1, 2], [$types[0]['id'], $types[1]['id']]);
    }

    public function test_build_option_types_marks_a_negative_premium_as_a_discount(): void
    {
        $type = $this->rawType(['values' => [$this->rawValue(['option_premium' => '-5.00'])]]);

        $types = $this->createService()->buildOptionTypes([$type], '£');

        $this->assertSame('-£6.00', $types[0]['values'][0]['priceIncTax']);
    }

    public function test_build_option_types_defaults_linked_product_flags(): void
    {
        $types = $this->createService()->buildOptionTypes([$this->rawType()], '£');

        $this->assertTrue($types[0]['linkedProductAvailable']); // Unlinked means nothing to be out of stock
        $this->assertFalse($types[0]['linkedProductRestocking']);
        $this->assertSame([], $types[0]['dependsOnValueIds']);
    }

    public function test_build_option_types_carries_linked_product_stock_state(): void
    {
        $type = $this->rawType([
            'linked_product_id' => 55,
            'linked_product_available' => 0,
            'linked_product_restocking' => 1,
            'linked_product_restock_at' => '2026-11-01',
            'depends_on_value_ids' => [3, 4],
        ]);

        $types = $this->createService()->buildOptionTypes([$type], '£');

        $this->assertSame(55, $types[0]['linkedProductId']);
        $this->assertFalse($types[0]['linkedProductAvailable']);
        $this->assertTrue($types[0]['linkedProductRestocking']);
        $this->assertSame('2026-11-01', $types[0]['linkedProductRestockDate']);
        $this->assertSame([3, 4], $types[0]['dependsOnValueIds']);
    }
}
