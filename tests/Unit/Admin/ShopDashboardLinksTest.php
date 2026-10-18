<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Osmium\Modules\Admin\Services\ShopDashboardLinks;
use PHPUnit\Framework\TestCase;

require_once 'app/core/admin/services/SetupChecklist.php';
require_once 'app/services/osmium-shop/models/ShopDashboardLinks.php';

class ShopDashboardLinksTest extends TestCase
{
    private function osmium(bool $shop, string $delivery = ''): array
    {
        return ['osmium' => new class ($shop, $delivery) {
            public object $config;

            public function __construct(private bool $shop, string $delivery)
            {
                $this->config = (object) ['shop' => (object) ['deliveryMessage' => $delivery]];
            }

            public function shopEnabled(): bool
            {
                return $this->shop;
            }
        }];
    }

    public function test_the_products_tile_is_offered_only_on_a_shop(): void
    {
        $this->assertSame('shop/products/', ShopDashboardLinks::quickLink($this->osmium(true))['url']);
        $this->assertNull(ShopDashboardLinks::quickLink($this->osmium(false)));
    }

    public function test_the_delivery_message_step_is_done_once_it_is_not_a_placeholder(): void
    {
        $this->assertFalse(ShopDashboardLinks::checklistItem($this->osmium(true, 'YOUR_DELIVERY_MESSAGE'))['done']);
        $this->assertTrue(ShopDashboardLinks::checklistItem($this->osmium(true, 'Free delivery over £50'))['done']);
        $this->assertNull(ShopDashboardLinks::checklistItem($this->osmium(false)));
    }
}
