<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Osmium\Modules\Admin\Services\ShopDashboardLinks;
use PHPUnit\Framework\TestCase;

require_once 'app/core/admin/services/SetupChecklist.php';
require_once 'app/services/osmium-shop/models/ShopDashboardLinks.php';

class ShopDashboardLinksTest extends TestCase
{
    private function osmium(string $delivery = ''): array
    {
        return ['osmium' => new class ($delivery) {
            public object $config;

            public function __construct(string $delivery)
            {
                $this->config = (object) ['shop' => (object) ['deliveryMessage' => $delivery]];
            }
        }];
    }

    public function test_the_products_tile_points_at_the_products_screen(): void
    {
        $this->assertSame('shop/products/', ShopDashboardLinks::quickLink($this->osmium())['url']);
    }

    public function test_the_delivery_message_step_is_done_once_it_is_not_a_placeholder(): void
    {
        $this->assertFalse(ShopDashboardLinks::checklistItem($this->osmium('YOUR_DELIVERY_MESSAGE'))['done']);
        $this->assertTrue(ShopDashboardLinks::checklistItem($this->osmium('Free delivery over £50'))['done']);
    }
}
