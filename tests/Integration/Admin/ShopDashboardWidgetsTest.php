<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Services\DashboardView;
use Osmium\Modules\Admin\Services\ShopDashboardWidgets;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/DashboardView.php';
require_once 'app/services/osmium-shop/models/ShopDashboardWidgets.php';

/**
 * The shop's dashboard cards: what each is told, and that core accepts
 * them as cards with their own view.
 */
class ShopDashboardWidgetsTest extends DatabaseTestCase
{
    private function payload(): array
    {
        $osmium = new class ($this->db) {
            public function __construct(public object $dataSource) {}
        };

        return ['osmium' => $osmium, 'role' => 'admin'];
    }

    public function test_the_quick_stats_count_products_and_users(): void
    {
        $before = ShopDashboardWidgets::quickStats($this->payload())['data'];
        $this->createTestProduct(['model_name' => 'ZQ dashboard widget']);

        $after = ShopDashboardWidgets::quickStats($this->payload())['data'];

        $this->assertSame($before['productCount'] + 1, $after['productCount']);
        $this->assertSame($before['userCount'], $after['userCount']);
        $this->assertGreaterThan(0, $after['userCount']);
    }

    public function test_the_basket_widget_reports_whole_numbers(): void
    {
        $data = ShopDashboardWidgets::baskets($this->payload())['data'];

        $this->assertIsInt($data['active_count']);
        $this->assertIsInt($data['converted_24h']);
        $this->assertArrayHasKey('active_value', $data);
        $this->assertArrayHasKey('converted_value', $data);
    }

    public function test_core_accepts_both_as_cards_with_their_own_view_in_the_right_order(): void
    {
        $widgets = DashboardView::serviceWidgets([
            ShopDashboardWidgets::quickStats($this->payload()),
            ShopDashboardWidgets::baskets($this->payload()),
        ], 'admin');

        $this->assertSame(['service_quick_stats', 'service_baskets'], \array_column($widgets, 'id'));
        foreach ($widgets as $widget) {
            $this->assertFileExists($widget['viewFile']);
            $this->assertSame('col-12', $widget['cols']);
        }
        $this->assertSame([22, 20], \array_column($widgets, 'order'));
    }
}
