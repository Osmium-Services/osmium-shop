<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use Osmium\Core\Library\StoreFinance;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/StoreFinance.php';

/**
 * StoreFinance::forOsmium(): the site's money settings, built once per framework instance from Shop settings.
 */
class StoreFinanceForOsmiumTest extends TestCase
{
    private function osmium(array $shop = []): object
    {
        return new class ($shop) {
            public object $config;

            public function __construct(array $shop)
            {
                $this->config = (object) ['shop' => (object) $shop];
            }
        };
    }

    public function test_the_stores_money_settings_are_built_once_and_a_bad_setting_fails_loudly(): void
    {
        $shop = ['currency' => 'gbp', 'currencySign' => '£', 'taxName' => 'VAT', 'taxRatePercent' => 20, 'country' => 'gb'];
        $osmium = $this->osmium($shop);

        $finance = StoreFinance::forOsmium($osmium);

        $this->assertSame('£1,234.50', $finance->format(1234.5));
        $this->assertSame($finance, StoreFinance::forOsmium($osmium), 'built once');

        $this->expectException(\InvalidArgumentException::class);
        StoreFinance::forOsmium($this->osmium(['currency' => 'pounds'] + $shop));
    }

    public function test_a_site_with_no_shop_settings_cannot_ask_for_the_store_finance(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoreFinance::forOsmium($this->osmium());
    }

    public function test_a_test_can_hand_an_instance_its_own_finance(): void
    {
        $osmium = $this->osmium();
        $finance = new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB');

        StoreFinance::useFor($osmium, $finance);

        $this->assertSame($finance, StoreFinance::forOsmium($osmium));
    }
}
