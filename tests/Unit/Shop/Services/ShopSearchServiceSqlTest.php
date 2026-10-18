<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Osmium\Modules\Shop\Services\ShopSearchService;

/**
 * Tests for ShopSearchService SQL fragment building methods
 * Phase 10 - Private SQL builder methods
 */
final class ShopSearchServiceSqlTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(ShopSearchService::class);
    }

    private function callMethod(string $method, array $args): mixed
    {
        $instance = $this->reflection->newInstanceWithoutConstructor();
        $m = $this->reflection->getMethod($method);
        return $m->invokeArgs($instance, $args);
    }

    // ============================================================
    // buildRelevanceExpressions() tests (3 tests)
    // ============================================================

    public function test_buildRelevanceExpressions_generates_case_when_for_all_score_types(): void
    {
        $scores = ['exact' => 100, 'starts' => 50, 'word' => 30, 'contains' => 10];
        $result = $this->callMethod(
            method: 'buildRelevanceExpressions',
            args: ['P.model_name', $scores, 1.0, 5]
        );

        $this->assertCount(expectedCount: 4, haystack: $result);
        $this->assertStringContainsString(needle: 'P.model_name = :exact5', haystack: $result[0]);
        $this->assertStringContainsString(needle: 'THEN 100', haystack: $result[0]);
        $this->assertStringContainsString(needle: 'P.model_name LIKE :starts5', haystack: $result[1]);
        $this->assertStringContainsString(needle: 'THEN 50', haystack: $result[1]);
        $this->assertStringContainsString(needle: 'P.model_name LIKE :word5', haystack: $result[2]);
        $this->assertStringContainsString(needle: 'THEN 30', haystack: $result[2]);
        $this->assertStringContainsString(needle: 'P.model_name LIKE :search5', haystack: $result[3]);
        $this->assertStringContainsString(needle: 'THEN 10', haystack: $result[3]);
    }

    public function test_buildRelevanceExpressions_applies_multiplier_for_synonyms(): void
    {
        $scores = ['exact' => 100, 'starts' => 50, 'word' => 30, 'contains' => 10];
        $result = $this->callMethod(
            method: 'buildRelevanceExpressions',
            args: ['P.model_name', $scores, 0.5, 0]
        );

        $this->assertStringContainsString(needle: 'THEN 50', haystack: $result[0]);
        $this->assertStringContainsString(needle: 'THEN 25', haystack: $result[1]);
        $this->assertStringContainsString(needle: 'THEN 15', haystack: $result[2]);
        $this->assertStringContainsString(needle: 'THEN 5', haystack: $result[3]);
    }

    public function test_buildRelevanceExpressions_skips_zero_scored_fields(): void
    {
        $scores = ['exact' => 0, 'starts' => 0, 'word' => 15, 'contains' => 3];
        $result = $this->callMethod(
            method: 'buildRelevanceExpressions',
            args: ['P.features', $scores, 1.0, 0]
        );

        $this->assertCount(expectedCount: 2, haystack: $result);
        $this->assertStringContainsString(needle: 'P.features LIKE :word0', haystack: $result[0]);
        $this->assertStringContainsString(needle: 'THEN 15', haystack: $result[0]);
        $this->assertStringContainsString(needle: 'P.features LIKE :search0', haystack: $result[1]);
        $this->assertStringContainsString(needle: 'THEN 3', haystack: $result[1]);
    }

    // ============================================================
    // buildWordQueryParts() tests (3 tests)
    // ============================================================

    public function test_buildWordQueryParts_generates_where_parts_for_all_search_fields(): void
    {
        $result = $this->callMethod(
            method: 'buildWordQueryParts',
            args: ['solar', true, 0]
        );

        $whereParts = $result['where'];
        $this->assertCount(expectedCount: 6, haystack: $whereParts);
        $this->assertContains(needle: 'PB.name LIKE :search0', haystack: $whereParts);
        $this->assertContains(needle: 'P.model_name LIKE :search0', haystack: $whereParts);
        $this->assertContains(needle: 'P.reference LIKE :search0', haystack: $whereParts);
        $this->assertContains(needle: 'P.barcode LIKE :search0', haystack: $whereParts);
        $this->assertContains(needle: 'P.features LIKE :search0', haystack: $whereParts);
        $this->assertContains(needle: 'P.descr LIKE :search0', haystack: $whereParts);
    }

    public function test_buildWordQueryParts_creates_four_binding_types_per_word(): void
    {
        $result = $this->callMethod(
            method: 'buildWordQueryParts',
            args: ['panel', true, 3]
        );

        $bindings = $result['bindings'];
        $this->assertCount(expectedCount: 4, haystack: $bindings);
        $this->assertSame(expected: 'panel', actual: $bindings[':exact3']);
        $this->assertSame(expected: 'panel%', actual: $bindings[':starts3']);
        $this->assertSame(expected: '% panel%', actual: $bindings[':word3']);
        $this->assertSame(expected: '%panel%', actual: $bindings[':search3']);
    }

    public function test_buildWordQueryParts_synonym_uses_half_multiplier(): void
    {
        $result = $this->callMethod(
            method: 'buildWordQueryParts',
            args: ['pv', false, 0]
        );

        $relevanceParts = $result['relevance'];
        $firstExpression = $relevanceParts[0];
        $this->assertStringContainsString(needle: 'THEN 50', haystack: $firstExpression);
    }

    // ============================================================
    // buildImagePath() tests (3 tests)
    // ============================================================

    public function test_buildImagePath_lowercases_and_replaces_spaces(): void
    {
        $result = $this->callMethod(
            method: 'buildImagePath',
            args: ['Tesla Power', 'Model X']
        );

        $expected = '/shop/tesla-power/model-x/thumb/tesla-power-model-x';
        $this->assertSame(expected: $expected, actual: $result);
    }

    public function test_buildImagePath_replaces_dots_in_model_name(): void
    {
        $result = $this->callMethod(
            method: 'buildImagePath',
            args: ['Renogy', 'RNG-100D.30']
        );

        $expected = '/shop/renogy/rng-100d_30/thumb/renogy-rng-100d_30';
        $this->assertSame(expected: $expected, actual: $result);
    }

    public function test_buildImagePath_trims_whitespace(): void
    {
        $result = $this->callMethod(
            method: 'buildImagePath',
            args: ['  Tesla  ', '  Model S  ']
        );

        $expected = '/shop/tesla/model-s/thumb/tesla-model-s';
        $this->assertSame(expected: $expected, actual: $result);
    }

    // ============================================================
    // formatPrice() tests (3 tests)
    // ============================================================

    public function test_formatPrice_applies_tax_rate(): void
    {
        $result = $this->callMethod(
            method: 'formatPrice',
            args: [100.00, 1.2]
        );

        $this->assertSame(expected: '120.00', actual: $result);
    }

    public function test_formatPrice_uses_dot_separator(): void
    {
        $result = $this->callMethod(
            method: 'formatPrice',
            args: [1234.56, 1.2]
        );

        $this->assertSame(expected: '1481.47', actual: $result);
        $this->assertStringNotContainsString(needle: ',', haystack: $result);
    }

    public function test_formatPrice_handles_zero_price(): void
    {
        $result = $this->callMethod(
            method: 'formatPrice',
            args: [0.0, 1.2]
        );

        $this->assertSame(expected: '0.00', actual: $result);
    }
}
