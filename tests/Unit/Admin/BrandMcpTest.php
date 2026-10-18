<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Modules\Admin\Services\BrandMcp;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/BrandMcp.php';

final class BrandMcpTest extends TestCase
{
    public function test_a_new_brand_needs_a_name(): void
    {
        $this->assertNotNull(BrandMcp::fieldsError([], nameRequired: true));
        $this->assertNull(BrandMcp::fieldsError(['name' => 'Acme'], nameRequired: true));
    }

    public function test_the_name_cannot_be_blank_or_over_50_characters(): void
    {
        $this->assertNotNull(BrandMcp::fieldsError(['name' => '   '], nameRequired: false));
        $this->assertNull(BrandMcp::fieldsError(['name' => \str_repeat('a', 50)], nameRequired: false));
        $this->assertNotNull(BrandMcp::fieldsError(['name' => \str_repeat('a', 51)], nameRequired: false));
        $this->assertNull(BrandMcp::fieldsError(['name' => \str_repeat('é', 50)], nameRequired: false));
    }

    public function test_text_must_be_one_line_of_text(): void
    {
        $this->assertNotNull(BrandMcp::fieldsError(['name' => "Two\nlines"], nameRequired: false));
        $this->assertNotNull(BrandMcp::fieldsError(['name' => 12], nameRequired: false));
        $this->assertNotNull(BrandMcp::fieldsError(['page_url' => null], nameRequired: false));
    }

    public function test_a_page_url_is_a_lower_case_path(): void
    {
        foreach (['brands/acme', '/brands/acme/', 'acme', 'brands/acme-2', ''] as $ok) {
            $this->assertNull(BrandMcp::fieldsError(['page_url' => $ok], nameRequired: false), "'{$ok}'");
        }
        foreach (['Brands/Acme', 'brands acme', 'brands/acme?x=1', 'https://x.com/a', '-acme', 'brands//..//x', 'br@nds'] as $bad) {
            $this->assertNotNull(BrandMcp::fieldsError(['page_url' => $bad], nameRequired: false), "'{$bad}'");
        }
        $this->assertNotNull(BrandMcp::fieldsError(['page_url' => \str_repeat('a', 101)], nameRequired: false));
    }

    public function test_the_info_link_must_be_a_web_address(): void
    {
        $this->assertNull(BrandMcp::fieldsError(['info_external' => 'https://example.com/brand'], nameRequired: false));
        $this->assertNull(BrandMcp::fieldsError(['info_external' => 'http://example.com'], nameRequired: false));
        $this->assertNull(BrandMcp::fieldsError(['info_external' => ''], nameRequired: false));

        foreach (['javascript:alert(1)', 'ftp://example.com', 'example.com', 'https://', 'https://exa mple.com', '//example.com', 'data:text/html,x'] as $bad) {
            $this->assertNotNull(BrandMcp::fieldsError(['info_external' => $bad], nameRequired: false), $bad);
        }
        $this->assertNotNull(BrandMcp::fieldsError(['info_external' => 'https://example.com/' . \str_repeat('a', 250)], nameRequired: false));
    }

    public function test_blank_clears_a_field_and_a_page_path_loses_its_end_slashes(): void
    {
        $this->assertNull(BrandMcp::blankToNull('', false));
        $this->assertNull(BrandMcp::blankToNull('  ', true));
        $this->assertNull(BrandMcp::blankToNull(null, true));
        $this->assertSame('brands/acme', BrandMcp::blankToNull('/brands/acme/', true));
        $this->assertSame('https://example.com/', BrandMcp::blankToNull(' https://example.com/ ', false));
    }

    public function test_the_list_shape_differs_for_the_bin_and_has_no_extra_columns(): void
    {
        $live = BrandMcp::shapeList([['id' => '3', 'name' => 'Acme', 'page_url' => 'brands/acme', 'info_external' => null, 'created_at' => 'x', 'product_count' => '4']]);
        $bin = BrandMcp::shapeList([['id' => '5', 'name' => 'Old', 'deleted_at' => '2026-10-11 10:00:00', 'product_count' => '2']]);

        $this->assertSame([['id' => 3, 'name' => 'Acme', 'page_url' => 'brands/acme', 'info_external' => null, 'live_products' => 4]], $live);
        $this->assertSame([['id' => 5, 'name' => 'Old', 'products_in_total' => 2, 'deleted_at' => '2026-10-11 10:00:00']], $bin);
    }

    public function test_every_change_needs_the_brand_id_and_exact_name(): void
    {
        $this->assertSame([3, 'Acme'], BrandMcp::idAndName(['id' => 3, 'expected_name' => ' Acme ']));

        foreach ([[], ['id' => '3', 'expected_name' => 'Acme'], ['id' => 0, 'expected_name' => 'Acme'], ['id' => 3, 'expected_name' => ' ']] as $arguments) {
            try {
                BrandMcp::idAndName($arguments);
                $this->fail('Expected a refusal');
            } catch (McpToolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_the_list_is_a_read_tool_and_there_is_no_permanent_delete(): void
    {
        $tools = [];
        foreach (BrandMcp::tools([]) as $tool) {
            $tools[$tool['name']] = $tool['write'] ?? false;
        }

        $this->assertSame(['brands_list' => false, 'brand_create' => true, 'brand_update' => true, 'brand_delete' => true, 'brand_restore' => true], $tools);
    }
}
