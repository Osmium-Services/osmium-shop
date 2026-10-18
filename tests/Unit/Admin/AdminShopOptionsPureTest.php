<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Osmium\Modules\Admin\Services\AdminShopOptions;

/**
 * Tests for AdminShopOptions pure/private methods
 * Phase 10 - Constructor bypass + Reflection
 */
final class AdminShopOptionsPureTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(AdminShopOptions::class);
    }

    private function callMethod(string $method, array $args): mixed
    {
        $instance = $this->reflection->newInstanceWithoutConstructor();
        $m = $this->reflection->getMethod($method);
        return $m->invokeArgs($instance, $args);
    }

    // ============================================================
    // assembleTypesWithValues() tests (3 tests)
    // ============================================================

    public function test_assembleTypesWithValues_merges_values_into_types_by_type_id(): void
    {
        $types = [
            ['id' => 1, 'type_label' => 'Color'],
            ['id' => 2, 'type_label' => 'Size'],
        ];
        $valuesByType = [
            1 => [
                ['option_descr' => 'Red'],
                ['option_descr' => 'Blue'],
            ],
            2 => [
                ['option_descr' => 'Small'],
            ],
        ];
        $result = $this->callMethod(
            method: 'assembleTypesWithValues',
            args: [$types, $valuesByType]
        );

        $this->assertCount(expectedCount: 2, haystack: $result);
        $this->assertCount(expectedCount: 2, haystack: $result[0]['values']);
        $this->assertCount(expectedCount: 1, haystack: $result[1]['values']);
    }

    public function test_assembleTypesWithValues_returns_empty_values_when_no_matching_values(): void
    {
        $types = [['id' => 1, 'type_label' => 'Color']];
        $valuesByType = [];
        $result = $this->callMethod(
            method: 'assembleTypesWithValues',
            args: [$types, $valuesByType]
        );

        $this->assertCount(expectedCount: 0, haystack: $result[0]['values']);
    }

    public function test_assembleTypesWithValues_preserves_all_type_fields(): void
    {
        $types = [
            [
                'id' => 1,
                'type_label' => 'Color',
                'type_slug' => 'color',
                'default_option_descr' => 'Standard',
            ],
        ];
        $valuesByType = [1 => [['option_descr' => 'Red']]];
        $result = $this->callMethod(
            method: 'assembleTypesWithValues',
            args: [$types, $valuesByType]
        );

        $this->assertSame(expected: 'Color', actual: $result[0]['type_label']);
        $this->assertSame(expected: 'color', actual: $result[0]['type_slug']);
        $this->assertSame(expected: 'Standard', actual: $result[0]['default_option_descr']);
    }

    // ============================================================
    // processNullableField() tests (4 tests)
    // ============================================================

    public function test_processNullableField_sets_column_to_null_when_int_value_is_null(): void
    {
        $def = ['col' => 'linked_product_id', 'type' => 'int', 'bind' => ':linkId'];
        $input = ['linkedProductId' => null];
        $updates = [];
        $bindings = [];
        $this->callMethod(
            method: 'processNullableField',
            args: ['linkedProductId', $def, $input, &$updates, &$bindings]
        );

        $this->assertContains(needle: 'linked_product_id = NULL', haystack: $updates);
        $this->assertEmpty(actual: $bindings);
    }

    public function test_processNullableField_sets_column_to_null_when_string_trims_to_empty(): void
    {
        $def = ['col' => 'type_slug', 'type' => 'string', 'bind' => ':slug'];
        $input = ['typeSlug' => '   '];
        $updates = [];
        $bindings = [];
        $this->callMethod(
            method: 'processNullableField',
            args: ['typeSlug', $def, $input, &$updates, &$bindings]
        );

        $this->assertContains(needle: 'type_slug = NULL', haystack: $updates);
        $this->assertEmpty(actual: $bindings);
    }

    public function test_processNullableField_adds_column_with_binding_for_valid_int_value(): void
    {
        $def = ['col' => 'linked_product_id', 'type' => 'int', 'bind' => ':linkId'];
        $input = ['linkedProductId' => '42'];
        $updates = [];
        $bindings = [];
        $this->callMethod(
            method: 'processNullableField',
            args: ['linkedProductId', $def, $input, &$updates, &$bindings]
        );

        $this->assertContains(needle: 'linked_product_id = :linkId', haystack: $updates);
        $this->assertSame(expected: 42, actual: $bindings[':linkId']);
    }

    public function test_processNullableField_skips_entirely_when_field_missing(): void
    {
        $def = ['col' => 'linked_product_id', 'type' => 'int', 'bind' => ':linkId'];
        $input = [];
        $updates = [];
        $bindings = [];
        $this->callMethod(
            method: 'processNullableField',
            args: ['linkedProductId', $def, $input, &$updates, &$bindings]
        );

        $this->assertEmpty(actual: $updates);
        $this->assertEmpty(actual: $bindings);
    }
}
