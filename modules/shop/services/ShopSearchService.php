<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

use Osmium\Modules\Shop\Models\ShopSearch;

/**
 * ShopSearchService
 *
 * Handles product search with synonym expansion and relevance scoring.
 * Search results are cached at the controller level (60s TTL).
 */
class ShopSearchService
{
    private const MIN_WORD_LENGTH = 2;
    private const RESULTS_LIMIT = 10;

    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'for', 'with',
        'to', 'of', 'in', 'on', 'is', 'it', 'by',
    ];

    /**
     * Field weights for relevance scoring.
     * Higher scores = more relevant matches.
     */
    private const SEARCH_FIELDS = [
        'PB.name' => ['exact' => 100, 'starts' => 50, 'word' => 30, 'contains' => 10],
        'P.model_name' => ['exact' => 100, 'starts' => 50, 'word' => 30, 'contains' => 10],
        'P.reference' => ['exact' => 100, 'starts' => 40, 'word' => 25, 'contains' => 8],
        'P.barcode' => ['exact' => 50, 'starts' => 20, 'word' => 10, 'contains' => 5],
        'P.features' => ['exact' => 0, 'starts' => 0, 'word' => 15, 'contains' => 3],
        'P.descr' => ['exact' => 0, 'starts' => 0, 'word' => 5, 'contains' => 1],
    ];

    private array $synonyms = [];

    public function __construct(
        private ShopSearch $search,
        private string $synonymsPath,
    ) {
        $this->synonyms = require $this->synonymsPath;
    }

    /**
     * Search products by query string.
     *
     * @param string $query Search query (min 2 chars)
     * @param float $taxRate Tax multiplier (e.g., 1.2 for 20% VAT)
     * @return array Search results with product data
     */
    public function search(string $query, float $taxRate): array
    {
        $inputWords = $this->parseQueryWords($query);

        $noWords = empty($inputWords);
        if ($noWords) return [];

        $wordGroups = $this->buildWordGroups($inputWords);
        $queryParts = $this->buildQueryParts($wordGroups);
        $products = $this->executeSearch($queryParts);

        return $this->formatResults(
            products: $products,
            taxRate: $taxRate,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Query parsing
    // ─────────────────────────────────────────────────────────────────────────

    private function parseQueryWords(string $query): array
    {
        $words = \preg_split(pattern: '/\s+/', subject: $query);

        $filtered = \array_filter(
            array: $words,
            callback: fn($w) => $this->isValidSearchWord($w),
        );

        return \array_values($filtered);
    }

    private function isValidSearchWord(string $word): bool
    {
        $tooShort = \strlen($word) < self::MIN_WORD_LENGTH;
        $isStopWord = \in_array(
            needle: \strtolower($word),
            haystack: self::STOP_WORDS,
        );

        return !$tooShort && !$isStopWord;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Synonym expansion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build word groups with synonyms.
     *
     * Each input word expands to: [original, singular form, synonyms]
     * Results are ORed within groups, ANDed between groups.
     */
    private function buildWordGroups(array $inputWords): array
    {
        $wordGroups = [];

        foreach ($inputWords as $word) {
            $wordGroups[] = $this->expandWord($word);
        }

        return $wordGroups;
    }

    private function expandWord(string $word): array
    {
        $group = [$word];
        $lowerWord = \strtolower($word);

        $canBePlural = \strlen($lowerWord) > 3;
        $endsInS = \substr(string: $lowerWord, offset: -1) === 's'; // Add singular form if word ends in 's' (simple plural handling)

        $shouldAddSingular = $canBePlural && $endsInS;
        if ($shouldAddSingular) {
            $singular = \substr(string: $word, offset: 0, length: -1);
            $group[] = $singular;

            $singularLower = \strtolower($singular);
            $hasSingularSynonyms = isset($this->synonyms[$singularLower]);
            if ($hasSingularSynonyms) {
                $group = \array_merge($group, $this->synonyms[$singularLower]); // Check synonyms for singular form too
            }
        }

        $hasWordSynonyms = isset($this->synonyms[$lowerWord]);
        if ($hasWordSynonyms) $group = \array_merge($group, $this->synonyms[$lowerWord]); // Add synonyms for original word

        return \array_unique($group);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SQL building
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build WHERE clauses and relevance scoring expressions.
     */
    private function buildQueryParts(array $wordGroups): array
    {
        $whereClauses = [];
        $relevanceParts = [];
        $bindings = [];
        $bindIndex = 0;

        foreach ($wordGroups as $group) {
            $groupResult = $this->buildGroupQueryParts(
                group: $group,
                bindIndex: $bindIndex,
            );

            $whereClauses[] = '(' . \implode(separator: ' OR ', array: $groupResult['where']) . ')';
            $relevanceParts = \array_merge($relevanceParts, $groupResult['relevance']);
            $bindings = \array_merge($bindings, $groupResult['bindings']);
            $bindIndex = $groupResult['nextIndex'];
        }

        return [
            'where' => \implode(separator: ' AND ', array: $whereClauses),
            'relevance' => \implode(separator: ' + ', array: $relevanceParts),
            'bindings' => $bindings,
        ];
    }

    private function buildGroupQueryParts(array $group, int $bindIndex): array
    {
        $whereParts = [];
        $relevanceParts = [];
        $bindings = [];

        foreach ($group as $word) {
            $wordResult = $this->buildWordQueryParts(
                word: $word,
                isOriginal: ($word === $group[0]),
                index: $bindIndex,
            );

            $whereParts = \array_merge($whereParts, $wordResult['where']);
            $relevanceParts = \array_merge($relevanceParts, $wordResult['relevance']);
            $bindings = \array_merge($bindings, $wordResult['bindings']);
            $bindIndex++;
        }

        return [
            'where' => $whereParts,
            'relevance' => $relevanceParts,
            'bindings' => $bindings,
            'nextIndex' => $bindIndex,
        ];
    }

    private function buildWordQueryParts(string $word, bool $isOriginal, int $index): array
    {
        $whereParts = [];
        $relevanceParts = [];

        $multiplier = $isOriginal ? 1.0 : 0.5; // Synonyms score half as much as original words

        foreach (self::SEARCH_FIELDS as $field => $scores) {
            $whereParts[] = "{$field} LIKE :search{$index}";

            $relevanceParts = \array_merge(
                $relevanceParts,
                $this->buildRelevanceExpressions(
                    field: $field,
                    scores: $scores,
                    multiplier: $multiplier,
                    index: $index,
                ),
            );
        }

        $bindings = [
            ":exact{$index}" => $word,
            ":starts{$index}" => $word . '%',
            ":word{$index}" => '% ' . $word . '%',
            ":search{$index}" => '%' . $word . '%',
        ];

        return [
            'where' => $whereParts,
            'relevance' => $relevanceParts,
            'bindings' => $bindings,
        ];
    }

    private function buildRelevanceExpressions(
        string $field,
        array $scores,
        float $multiplier,
        int $index
    ): array {
        $expressions = [];

        $hasExactScore = $scores['exact'] > 0;
        if ($hasExactScore) {
            $score = (int)($scores['exact'] * $multiplier);
            $expressions[] = "CASE WHEN {$field} = :exact{$index} THEN {$score} ELSE 0 END";
        }

        $hasStartsScore = $scores['starts'] > 0;
        if ($hasStartsScore) {
            $score = (int)($scores['starts'] * $multiplier);
            $expressions[] = "CASE WHEN {$field} LIKE :starts{$index} THEN {$score} ELSE 0 END";
        }

        $hasWordScore = $scores['word'] > 0;
        if ($hasWordScore) {
            $score = (int)($scores['word'] * $multiplier);
            $expressions[] = "CASE WHEN {$field} LIKE :word{$index} THEN {$score} ELSE 0 END";
        }

        $score = (int)($scores['contains'] * $multiplier);
        $expressions[] = "CASE WHEN {$field} LIKE :search{$index} THEN {$score} ELSE 0 END";

        return $expressions;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Query execution
    // ─────────────────────────────────────────────────────────────────────────

    private function executeSearch(array $queryParts): array
    {
        return $this->search->searchProducts(
            where: $queryParts['where'],
            relevance: $queryParts['relevance'],
            bindings: $queryParts['bindings'],
            limit: self::RESULTS_LIMIT,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Result formatting
    // ─────────────────────────────────────────────────────────────────────────

    private function formatResults(array $products, float $taxRate): array
    {
        $pagesById = \Osmium\Core\Models\Model::pagesById(); // The PAGES constant indexed by ID (loaded in Routes.php)
        $results = [];

        foreach ($products as $product) {
            $formatted = $this->formatProduct(
                product: $product,
                pagesById: $pagesById,
                taxRate: $taxRate,
            );

            $hasResult = $formatted !== null;
            if ($hasResult) $results[] = $formatted;
        }

        return $results;
    }

    private function formatProduct(array $product, array $pagesById, float $taxRate): ?array
    {
        $pageId = $product['page_id'];
        $page = $pagesById[$pageId] ?? null;

        $pageDeleted = $page === null || $page['deleted_at'] !== null;
        if ($pageDeleted) return null;

        $brandName = $product['brand_name'];
        $modelName = $product['model_name'];

        return [
            'id' => (int)$product['id'],
            'title' => $brandName . ' ' . $modelName,
            'reference' => $product['reference'],
            'url' => '/' . $page['url'] . '/',
            'image' => $this->buildImagePath(brandName: $brandName, modelName: $modelName),
            'price' => $this->formatPrice(
                priceExcTax: (float)$product['price_exc_tax'],
                taxRate: $taxRate,
            ),
            'available' => (bool)$product['product_available'],
            'status' => $product['product_available'] ? null : $product['descr_short'],
        ];
    }

    private function buildImagePath(string $brandName, string $modelName): string
    {
        $brandPath = \mb_strtolower(
            \str_replace(search: [' '], replace: ['-'], subject: \trim($brandName))
        );

        $modelPath = \mb_strtolower(
            \str_replace(search: [' ', '.'], replace: ['-', '_'], subject: \trim($modelName))
        );

        return "/shop/{$brandPath}/{$modelPath}/thumb/{$brandPath}-{$modelPath}"; // No extension - optimised format selected after cache retrieval
    }

    private function formatPrice(float $priceExcTax, float $taxRate): string
    {
        return \number_format(
            num: $priceExcTax * $taxRate,
            decimals: 2,
            decimal_separator: '.',
            thousands_separator: '',
        );
    }
}
