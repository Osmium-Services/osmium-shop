<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Osmium\Modules\Shop\Services\ShopProductService;
use Osmium\Core\Library\OsmiumPDO;

/**
 * Tests for ShopProductService pure/private methods
 * Phase 10 - Real constructor with mock DB (needs $this->taxRatePercent)
 */
final class ShopProductServicePureTest extends TestCase
{
    private ShopProductService $service;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $mockDb = $this->createMock(OsmiumPDO::class);
        $this->service = new ShopProductService($mockDb, taxRatePercent: 20.0);
        $this->reflection = new ReflectionClass(ShopProductService::class);
    }

    private function callMethod(string $method, array $args): mixed
    {
        $m = $this->reflection->getMethod($method);
        return $m->invokeArgs($this->service, $args);
    }

    // ============================================================
    // processOptionValue() tests (3 tests)
    // ============================================================

    public function test_processOptionValue_adds_price_inc_tax_with_positive_premium(): void
    {
        $value = ['option_premium' => '10.00', 'linked_product_id' => null, 'linked_product_restock_at' => null];
        $result = $this->callMethod(
            method: 'processOptionValue',
            args: [$value, '£']
        );

        $this->assertArrayHasKey(key: 'priceIncTax', array: $result);
        $this->assertSame(expected: '+£12.00', actual: $result['priceIncTax']);
    }

    public function test_processOptionValue_adds_price_inc_tax_with_negative_premium(): void
    {
        $value = ['option_premium' => '-5.00', 'linked_product_id' => null, 'linked_product_restock_at' => null];
        $result = $this->callMethod(
            method: 'processOptionValue',
            args: [$value, '£']
        );

        $this->assertSame(expected: '-£6.00', actual: $result['priceIncTax']);
    }

    public function test_processOptionValue_preserves_original_value_data(): void
    {
        $value = [
            'option_premium' => '10.00',
            'option_descr' => 'Test option',
            'option_reference' => 'REF123',
            'linked_product_id' => null,
            'linked_product_restock_at' => null,
        ];
        $result = $this->callMethod(
            method: 'processOptionValue',
            args: [$value, '£']
        );

        $this->assertSame(expected: 'Test option', actual: $result['option_descr']);
        $this->assertSame(expected: 'REF123', actual: $result['option_reference']);
    }

    // ============================================================
    // mapValuesForJson() tests (3 tests)
    // ============================================================

    public function test_mapValuesForJson_maps_value_array_to_json_structure(): void
    {
        $values = [
            [
                'option_rrp' => '100.00',
                'option_premium' => '10.00',
                'option_packed_weight' => '2.5',
                'option_descr' => 'Option 1',
                'option_reference' => 'REF1'
            ]
        ];
        $result = $this->callMethod(
            method: 'mapValuesForJson',
            args: [$values]
        );

        $this->assertCount(expectedCount: 1, haystack: $result);
        $this->assertSame(expected: 100.0, actual: $result[0]['rrp']);
        $this->assertSame(expected: 10.0, actual: $result[0]['premium']);
        $this->assertSame(expected: 2.5, actual: $result[0]['weight']);
        $this->assertSame(expected: 'Option 1', actual: $result[0]['descr']);
        $this->assertSame(expected: 'REF1', actual: $result[0]['ref']);
    }

    public function test_mapValuesForJson_casts_numeric_fields_to_float(): void
    {
        $values = [
            [
                'option_rrp' => '50',
                'option_premium' => '5',
                'option_packed_weight' => '1',
                'option_descr' => 'Test',
                'option_reference' => 'TEST'
            ]
        ];
        $result = $this->callMethod(
            method: 'mapValuesForJson',
            args: [$values]
        );

        $this->assertIsFloat(actual: $result[0]['rrp']);
        $this->assertIsFloat(actual: $result[0]['premium']);
        $this->assertIsFloat(actual: $result[0]['weight']);
    }

    public function test_mapValuesForJson_handles_multiple_values(): void
    {
        $values = [
            [
                'option_rrp' => '100.00',
                'option_premium' => '10.00',
                'option_packed_weight' => '2.5',
                'option_descr' => 'Option 1',
                'option_reference' => 'REF1'
            ],
            [
                'option_rrp' => '150.00',
                'option_premium' => '20.00',
                'option_packed_weight' => '3.0',
                'option_descr' => 'Option 2',
                'option_reference' => 'REF2'
            ]
        ];
        $result = $this->callMethod(
            method: 'mapValuesForJson',
            args: [$values]
        );

        $this->assertCount(expectedCount: 2, haystack: $result);
        $this->assertSame(expected: 150.0, actual: $result[1]['rrp']);
    }

    // ============================================================
    // addSalesTax() tests (2 tests)
    // ============================================================

    public function test_addSalesTax_applies_twenty_percent_tax(): void
    {
        $result = $this->callMethod(
            method: 'addSalesTax',
            args: [100.0]
        );

        $this->assertSame(expected: 120.0, actual: $result);
    }

    public function test_addSalesTax_handles_zero_price(): void
    {
        $result = $this->callMethod(
            method: 'addSalesTax',
            args: [0.0]
        );

        $this->assertSame(expected: 0.0, actual: $result);
    }
}
