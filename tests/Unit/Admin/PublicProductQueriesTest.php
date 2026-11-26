<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The shop front end hides a Draft by every query that lists, finds or counts products carrying the same rule
 * (P.is_published = 1), copied into each file. So a new product query that forgets it shows Drafts to visitors.
 * Every product query on the public side must carry the rule, or be named here with the reason it need not.
 */
class PublicProductQueriesTest extends TestCase
{
    /** Queries that read products without the rule, and why that is right. */
    private const EXEMPT = [
        'product-get-by-id.sql' => 'loads the product for its own page: OsmiumShop.php 404s a Draft for visitors and lets a signed-in admin preview it',
        'product-page-get-product-id.sql' => 'maps a page to its product id only; the page gate above decides what is shown',
        'product-count-all.sql' => 'the admin total shown above the Products list',
        'product-first-categories.sql' => 'the categories of products already chosen by a query that applies the rule',
        'option-get-all-for-product.sql' => 'options of a product already loaded through a query that applies the rule',
    ];

    /** @return array<string, array{string}> */
    public static function files(): array
    {
        $root = \dirname(__DIR__, 3);
        $files = [];
        foreach (\array_merge(\glob($root . '/modules/shop/models/sql/*.sql') ?: [], \glob($root . '/models/sql/*.sql') ?: []) as $path) {
            $name = \basename($path);
            $readsProducts = \preg_match('/^(product-|category-first-product|category-ids-with-direct-products)/', $name) === 1;
            if ($readsProducts) $files[$name] = [$path];
        }

        return $files;
    }

    /** @dataProvider files */
    public function test_a_public_product_query_hides_a_draft_or_says_why_not(string $path): void
    {
        $name = \basename($path);
        if (isset(self::EXEMPT[$name])) {
            $this->assertNotSame('', self::EXEMPT[$name]);
            return;
        }

        $this->assertStringContainsString('is_published', (string) \file_get_contents($path), "$name must filter on P.is_published = 1, or be added to EXEMPT with the reason");
    }
}
