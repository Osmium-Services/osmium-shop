<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Models\ShopSearch;
use Osmium\Modules\Shop\Services\ShopSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShopSearchService
 *
 * Covers:
 * - parseQueryWords() - splits query and filters stop words
 * - isValidSearchWord() - validates search terms
 * - expandWord() - synonym expansion and plural handling
 * - buildWordGroups() - groups words for search
 */
class ShopSearchServiceTest extends TestCase
{
    private string $tempSynonymsFile;

    protected function setUp(): void
    {
        $synonymsArray = [
            'panel' => ['module'],
            'charger' => ['controller', 'regulator'],
            'inverter' => ['invertor'],
        ];

        $this->tempSynonymsFile = sys_get_temp_dir() . '/test_synonyms_' . uniqid() . '.php';
        file_put_contents(
            filename: $this->tempSynonymsFile,
            data: '<?php return ' . var_export($synonymsArray, return: true) . ';'
        );
    }

    protected function tearDown(): void
    {
        $fileExists = file_exists($this->tempSynonymsFile);
        if ($fileExists) unlink($this->tempSynonymsFile);
    }

    private function createService(): ShopSearchService
    {
        $mockSearch = $this->createMock(ShopSearch::class);
        return new ShopSearchService($mockSearch, synonymsPath: $this->tempSynonymsFile);
    }

    // =========================================================================
    // parseQueryWords()
    // =========================================================================

    public function test_parse_query_words_splits_on_whitespace(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('parseQueryWords');

        $result = $method->invoke($service, 'solar panel inverter');

        $this->assertEquals(['solar', 'panel', 'inverter'], $result);
    }

    public function test_parse_query_words_filters_stop_words(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('parseQueryWords');

        $result = $method->invoke($service, 'the solar panel and inverter');

        $this->assertEquals(['solar', 'panel', 'inverter'], $result);
    }

    public function test_parse_query_words_filters_short_words(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('parseQueryWords');

        $result = $method->invoke($service, 'solar a b panel');

        $this->assertEquals(['solar', 'panel'], $result);
    }

    public function test_parse_query_words_handles_multiple_spaces(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('parseQueryWords');

        $result = $method->invoke($service, 'solar   panel    inverter');

        $this->assertEquals(['solar', 'panel', 'inverter'], $result);
    }

    // =========================================================================
    // isValidSearchWord()
    // =========================================================================

    public function test_is_valid_search_word_accepts_normal_words(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isValidSearchWord');

        $result = $method->invoke($service, 'solar');

        $this->assertTrue($result);
    }

    public function test_is_valid_search_word_rejects_stop_words(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isValidSearchWord');

        $result = $method->invoke($service, 'the');

        $this->assertFalse($result);
    }

    public function test_is_valid_search_word_rejects_single_chars(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('isValidSearchWord');

        $result = $method->invoke($service, 'a');

        $this->assertFalse($result);
    }

    // =========================================================================
    // expandWord()
    // =========================================================================

    public function test_expand_word_returns_word_plus_synonyms(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('expandWord');

        $result = $method->invoke($service, 'panel');

        $this->assertContains('panel', $result);
        $this->assertContains('module', $result);
    }

    public function test_expand_word_singularizes_plurals(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('expandWord');

        $result = $method->invoke($service, 'panels');

        $this->assertContains('panels', $result);
        $this->assertContains('panel', $result);
        $this->assertContains('module', $result);
    }

    public function test_expand_word_returns_just_original_when_no_synonyms(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('expandWord');

        $result = $method->invoke($service, 'battery');

        $this->assertEquals(['battery'], $result);
    }

    public function test_expand_word_does_not_singularize_short_words(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('expandWord');

        $result = $method->invoke($service, 'ups');

        $this->assertEquals(['ups'], $result);
    }

    // =========================================================================
    // buildWordGroups()
    // =========================================================================

    public function test_build_word_groups_creates_one_group_per_input_word(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildWordGroups');

        $result = $method->invoke($service, ['solar', 'panel']);

        $this->assertCount(2, $result);
        $this->assertEquals(['solar'], $result[0]);
        $this->assertContains('panel', $result[1]);
        $this->assertContains('module', $result[1]);
    }
}
