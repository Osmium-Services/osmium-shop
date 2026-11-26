<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The Products list is fed by two queries (all products, and one category's). Each must carry the columns the list
 * reads, or the list quietly shows the default: a missing is_published made every product Published when the Category
 * filter was on.
 */
class ProductListQueriesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function queries(): array
    {
        return ['the one list select' => ['product-list-select.sql']];
    }

    /** @dataProvider queries */
    public function test_a_list_query_selects_what_the_list_reads(string $file): void
    {
        $sql = (string) \file_get_contents(__DIR__ . '/../../../admin/sql/' . $file);

        $this->assertStringContainsString('is_published', $sql, "$file must select is_published");
        $this->assertStringContainsString('has_category', $sql, "$file must select has_category");
    }
}
