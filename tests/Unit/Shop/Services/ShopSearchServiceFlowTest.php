<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Models\ShopSearch;
use Osmium\Modules\Shop\Services\ShopSearchService;
use PHPUnit\Framework\TestCase;

/**
 * The search from query text to the products a customer is shown: which words
 * are searched, how the query is handed to the database, and which results
 * are allowed through.
 */
final class ShopSearchServiceFlowTest extends TestCase
{
    private string $synonymsFile;

    protected function setUp(): void
    {
        $this->synonymsFile = \tempnam(\sys_get_temp_dir(), 'syn');
        \file_put_contents($this->synonymsFile, "<?php\nreturn ['panel' => ['module']];");
    }

    protected function tearDown(): void
    {
        \unlink($this->synonymsFile);
    }

    private function service(ShopSearch $model): ShopSearchService
    {
        return new ShopSearchService($model, $this->synonymsFile);
    }

    private function product(array $overrides = []): array
    {
        return $overrides + [
            'id' => '12',
            'page_id' => 5,
            'brand_name' => 'Victron Energy',
            'model_name' => 'MPPT 100.30',
            'reference' => 'SCC1',
            'price_exc_tax' => '100.00',
            'product_available' => 1,
            'descr_short' => 'Charge controller',
        ];
    }

    private function format(array $product, array $pagesById, float $taxRate = 1.2): ?array
    {
        $method = new \ReflectionMethod(ShopSearchService::class, 'formatProduct');
        $service = $this->service($this->createMock(ShopSearch::class));

        return $method->invoke($service, $product, $pagesById, $taxRate);
    }

    // ── search() ─────────────────────────────────────────────────────────────

    public function test_a_query_of_only_short_or_stop_words_searches_nothing(): void
    {
        $model = $this->createMock(ShopSearch::class);
        $model->expects($this->never())->method('searchProducts');

        $this->assertSame([], $this->service($model)->search('a of to 1', 1.2));
        $this->assertSame([], $this->service($model)->search('', 1.2));
    }

    public function test_each_word_must_match_and_synonyms_are_alternatives_within_a_word(): void
    {
        $captured = null;
        $model = $this->createMock(ShopSearch::class);
        $model->method('searchProducts')->willReturnCallback(function (string $where, string $relevance, array $bindings, int $limit) use (&$captured) {
            $captured = \compact('where', 'relevance', 'bindings', 'limit');

            return [];
        });

        $this->service($model)->search('panel victron', 1.2);

        $groups = \explode(' AND ', $captured['where']);
        $this->assertCount(2, $groups); // One group per typed word
        $this->assertSame(12, \substr_count($groups[0], ' LIKE :search'), 'panel + synonym "module": 2 words x 6 fields');
        $this->assertSame(6, \substr_count($groups[1], ' LIKE :search')); // victron has no synonyms
        $this->assertSame('%panel%', $captured['bindings'][':search0']);
        $this->assertSame('%module%', $captured['bindings'][':search1']);
        $this->assertSame('%victron%', $captured['bindings'][':search2']);
        $this->assertSame(10, $captured['limit']);
        $this->assertStringContainsString('CASE WHEN P.model_name = :exact0 THEN 100', $captured['relevance']);
        $this->assertStringContainsString('THEN 50 ELSE 0', $captured['relevance']); // The synonym scores half the original
    }

    public function test_user_text_only_reaches_the_database_as_bound_values(): void
    {
        $captured = null;
        $model = $this->createMock(ShopSearch::class);
        $model->method('searchProducts')->willReturnCallback(function (string $where, string $relevance, array $bindings) use (&$captured) {
            $captured = \compact('where', 'relevance', 'bindings');

            return [];
        });

        $this->service($model)->search("x' OR 1=1 --", 1.2);

        $this->assertStringNotContainsString('1=1', $captured['where']);
        $this->assertStringNotContainsString('1=1', $captured['relevance']);
        $this->assertContains("%x'%", $captured['bindings']);
    }

    public function test_results_for_pages_that_no_longer_exist_are_not_shown(): void
    {
        $model = $this->createMock(ShopSearch::class);
        $model->method('searchProducts')->willReturn([$this->product(['page_id' => 987654321])]);

        $this->assertSame([], $this->service($model)->search('victron', 1.2));
    }

    // ── formatProduct() ──────────────────────────────────────────────────────

    public function test_a_live_product_is_formatted_for_the_results_list(): void
    {
        $result = $this->format($this->product(), [5 => ['id' => 5, 'url' => 'victron-mppt', 'deleted_at' => null]]);

        $this->assertSame(12, $result['id']);
        $this->assertSame('Victron Energy MPPT 100.30', $result['title']);
        $this->assertSame('/victron-mppt/', $result['url']);
        $this->assertSame('120.00', $result['price']); // 100.00 ex VAT at 1.2
        $this->assertTrue($result['available']);
        $this->assertNull($result['status']);
        $this->assertSame('/shop/victron-energy/mppt-100_30/thumb/victron-energy-mppt-100_30', $result['image']);
    }

    public function test_an_unavailable_product_carries_its_status_text(): void
    {
        $result = $this->format(
            $this->product(['product_available' => 0, 'descr_short' => 'Out of stock']),
            [5 => ['id' => 5, 'url' => 'p', 'deleted_at' => null]],
        );

        $this->assertFalse($result['available']);
        $this->assertSame('Out of stock', $result['status']);
    }

    public function test_a_deleted_or_unknown_page_gives_no_result(): void
    {
        $this->assertNull($this->format($this->product(), [5 => ['id' => 5, 'url' => 'p', 'deleted_at' => '2026-01-01 10:00:00']]));
        $this->assertNull($this->format($this->product(), []));
    }

    public function test_large_prices_have_no_thousands_separator(): void
    {
        $result = $this->format(
            $this->product(['price_exc_tax' => '1200.00']),
            [5 => ['id' => 5, 'url' => 'p', 'deleted_at' => null]],
        );

        $this->assertSame('1440.00', $result['price']);
    }
}
