<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Services\ShopListControls;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../modules/shop/services/ShopListControls.php';

/**
 * The Show / Sort By / stock values above a product list arrive from the querystring, and the sort column goes
 * straight into SQL, so every value must be checked against its fixed list.
 */
final class ShopListControlsTest extends TestCase
{
    public function test_defaults_when_nothing_is_given(): void
    {
        $controls = ShopListControls::resolve(limit: null, sort: null, stock: null, page: null);

        $this->assertSame(10, $controls['limit']);
        $this->assertSame('newest', $controls['sort']);
        $this->assertSame([], $controls['stock']);
        $this->assertSame(1, $controls['page']);
    }

    public function test_valid_values_are_kept(): void
    {
        $controls = ShopListControls::resolve(limit: '25', sort: 'price-asc', stock: ['in', 'out'], page: '3');

        $this->assertSame(25, $controls['limit']);
        $this->assertSame('price-asc', $controls['sort']);
        $this->assertSame('P.price_exc_tax ASC, P.id ASC', $controls['sortColumn']);
        $this->assertSame(['in', 'out'], $controls['stock']);
        $this->assertSame(3, $controls['page']);
    }

    public function test_an_unknown_sort_never_reaches_the_sql(): void
    {
        $controls = ShopListControls::resolve(limit: 10, sort: 'P.id; DROP TABLE x', stock: [], page: 1);

        $this->assertSame('newest', $controls['sort']);
        $this->assertSame('P.created_at DESC, P.id ASC', $controls['sortColumn']);
    }

    public function test_bad_limit_stock_and_page_fall_back(): void
    {
        $controls = ShopListControls::resolve(limit: 7, sort: 'newest', stock: ['in', 'bogus', "1' OR 1=1"], page: -4);

        $this->assertSame(10, $controls['limit']);
        $this->assertSame(['in'], $controls['stock']);
        $this->assertSame(1, $controls['page']);
    }

    public function test_paginate_returns_the_requested_slice(): void
    {
        [$ids, $page] = ShopListControls::paginate(range(1, 25), page: 2, limit: 10);

        $this->assertSame(range(11, 20), $ids);
        $this->assertSame(2, $page);
    }

    public function test_a_page_past_the_end_lands_on_the_last_page(): void
    {
        [$ids, $page] = ShopListControls::paginate(range(1, 25), page: 99, limit: 10);

        $this->assertSame(range(21, 25), $ids);
        $this->assertSame(3, $page);
    }

    public function test_an_empty_list_is_page_one(): void
    {
        [$ids, $page] = ShopListControls::paginate([], page: 5, limit: 10);

        $this->assertSame([], $ids);
        $this->assertSame(1, $page);
    }
}
