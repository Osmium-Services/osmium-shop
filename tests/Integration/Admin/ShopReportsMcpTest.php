<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\McpServer;
use Osmium\Modules\Admin\Services\ShopReportsMcp;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/services/osmium-shop/models/ShopReportsMcp.php';

/**
 * The shop's report tools for the MCP server: both are accepted by the server, and each handler answers from the
 * real tables with the shape the assistant relies on.
 */
class ShopReportsMcpTest extends DatabaseTestCase
{
    public function test_both_tools_are_accepted_by_the_server_and_are_read_only(): void
    {
        $tools = McpServer::withServiceTools(coreTools: [], descriptors: [ShopReportsMcp::tools([])], writesEnabled: false, context: []);

        $this->assertSame(['orders_summary', 'products_attention'], \array_keys($tools));
    }

    public function test_the_orders_summary_has_totals_and_the_newest_orders(): void
    {
        $result = ShopReportsMcp::ordersSummary(['dataSource' => $this->db]);

        $this->assertSame(['totals', 'newest'], \array_keys($result));
        $this->assertLessThanOrEqual(10, \count($result['newest']));
    }

    public function test_products_attention_answers_with_its_report(): void
    {
        $result = ShopReportsMcp::productsAttention(['dataSource' => $this->db]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }
}
