<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Services\ProductMcp;
use PHPUnit\Framework\TestCase;

final class ProductMcpTest extends TestCase
{
    private const TODAY = '2026-10-11';

    public function test_a_blank_date_clears_the_restock_date(): void
    {
        $this->assertNull(ProductMcp::dateError('', self::TODAY));
    }

    public function test_today_and_later_are_fine(): void
    {
        $this->assertNull(ProductMcp::dateError('2026-10-11', self::TODAY));
        $this->assertNull(ProductMcp::dateError('2026-12-01', self::TODAY));
        $this->assertNull(ProductMcp::dateError('2028-10-10', self::TODAY));
    }

    public function test_a_past_date_is_refused(): void
    {
        $this->assertNotNull(ProductMcp::dateError('2026-10-10', self::TODAY));
        $this->assertNotNull(ProductMcp::dateError('2020-01-01', self::TODAY));
    }

    public function test_a_date_over_two_years_away_is_refused_as_a_likely_typo(): void
    {
        $this->assertNotNull(ProductMcp::dateError('2028-10-12', self::TODAY));
        $this->assertNotNull(ProductMcp::dateError('2206-01-01', self::TODAY));
    }

    public function test_only_a_real_date_in_year_month_day_form_is_accepted(): void
    {
        foreach (['2026-02-30', '2026-13-01', '11/10/2026', '2026-1-5', 'tomorrow', '2026-10-11 10:00', ' 2026-10-20', '20261020'] as $date) {
            $this->assertNotNull(ProductMcp::dateError($date, self::TODAY), $date);
        }
    }

    public function test_the_name_is_brand_then_model_as_the_attention_report_lists_it(): void
    {
        $this->assertSame('Globex Panel 400W', ProductMcp::name(['brand_name' => 'Globex', 'model_name' => 'Panel 400W']));
        $this->assertSame('Panel 400W', ProductMcp::name(['brand_name' => null, 'model_name' => 'Panel 400W']));
    }

    public function test_the_category_list_names_each_parent(): void
    {
        $list = ProductMcp::shapeCategories([
            ['id' => '1', 'label' => 'Panels', 'parent_id' => null],
            ['id' => '2', 'label' => 'Seed Panels', 'parent_id' => '1'],
            ['id' => '3', 'label' => 'Orphan', 'parent_id' => '99'],
        ]);

        $this->assertSame(['id' => 1, 'label' => 'Panels', 'parent' => null], $list[0]);
        $this->assertSame('Panels', $list[1]['parent']);
        $this->assertNull($list[2]['parent']);
    }

    public function test_every_tool_needs_the_product_id_and_exact_name(): void
    {
        $this->assertSame([5, 'Globex Panel 400W'], ProductMcp::idAndName(['id' => 5, 'expected_name' => ' Globex Panel 400W ']));

        foreach ([[], ['id' => '5', 'expected_name' => 'x'], ['id' => 0, 'expected_name' => 'x'], ['id' => 5, 'expected_name' => ' ']] as $arguments) {
            try {
                ProductMcp::idAndName($arguments);
                $this->fail('Expected a refusal');
            } catch (McpToolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_the_lists_are_read_tools_and_nothing_removes_or_reprices(): void
    {
        $tools = [];
        foreach (ProductMcp::tools([]) as $tool) $tools[$tool['name']] = $tool['write'] ?? false;

        $this->assertSame(['product_categories_list' => false, 'products_find' => false, 'product_statuses_list' => false, 'product_status_set' => true, 'product_category_remove' => true, 'product_category_rename' => true, 'product_restock_date_set' => true, 'product_category_add' => true], $tools);
    }

    public function test_a_new_category_label_cannot_be_blank_too_long_or_a_siblings(): void
    {
        $live = [
            ['id' => 1, 'label' => 'Panels', 'parent_id' => null],
            ['id' => 2, 'label' => 'Inverters', 'parent_id' => null],
            ['id' => 3, 'label' => 'Accessories', 'parent_id' => 1],
        ];

        $this->assertNull(ProductMcp::labelError('Solar Panels', 1, null, $live));
        $this->assertNull(ProductMcp::labelError('PANELS', 1, null, $live), 'Its own label in another case is fine');
        $this->assertNull(ProductMcp::labelError('Inverters', 3, 1, $live), 'The same label under a different parent is fine');
        $this->assertNotNull(ProductMcp::labelError('', 1, null, $live));
        $this->assertNotNull(ProductMcp::labelError(\str_repeat('a', 51), 1, null, $live));
        $this->assertNotNull(ProductMcp::labelError('inverters', 1, null, $live));
    }

    public function test_the_status_list_shows_what_each_status_means(): void
    {
        $list = ProductMcp::shapeStatuses([
            ['id' => '1', 'descr_short' => 'In stock', 'descr_long' => 'In stock now', 'product_available' => '1', 'restocking' => '0', 'enabled' => '1'],
            ['id' => '3', 'descr_short' => 'Restocking', 'descr_long' => 'Back soon', 'product_available' => '0', 'restocking' => '1', 'enabled' => '0'],
        ]);

        $this->assertSame(['id' => 1, 'label' => 'In stock', 'long_label' => 'In stock now', 'available' => true, 'restocking' => false, 'enabled' => true], $list[0]);
        $this->assertSame([3, false, true, false], [$list[1]['id'], $list[1]['available'], $list[1]['restocking'], $list[1]['enabled']]);
    }

    public function test_a_search_becomes_a_pattern_with_the_words_in_order(): void
    {
        $this->assertSame('%acme%100w%', ProductMcp::searchPattern('  acme   100w '));
        $this->assertSame('%100\\%%', ProductMcp::searchPattern('100%'), 'A percent sign is taken literally');
        $this->assertSame('%a\\_b%', ProductMcp::searchPattern('a_b'));
        $this->assertNull(ProductMcp::searchPattern(' x '));
        $this->assertNull(ProductMcp::searchPattern(''));
    }

    public function test_found_products_are_named_and_a_long_list_says_there_is_more(): void
    {
        $row = ['id' => '7', 'reference' => 'SKU1', 'model_name' => 'Panel 100W', 'brand_name' => 'Acme', 'status_name' => 'In stock', 'restock_at' => null];

        $one = ProductMcp::shapeFound([$row]);
        $this->assertSame(['id' => 7, 'name' => 'Acme Panel 100W', 'reference' => 'SKU1', 'status' => 'In stock', 'restock_at' => null], $one['products'][0]);
        $this->assertFalse($one['more']);

        $many = ProductMcp::shapeFound(\array_fill(0, 26, $row));
        $this->assertSame([25, true], [$many['shown'], $many['more']]);
    }
}
