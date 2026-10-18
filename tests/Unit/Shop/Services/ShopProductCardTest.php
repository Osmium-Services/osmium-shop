<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Services\ShopProductCard;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../modules/shop/services/ShopProductCard.php';

/**
 * The prices and flags every listing page (category, featured products, new
 * arrivals) shows for a product.
 */
final class ShopProductCardTest extends TestCase
{
    private const NOW = 1790000000; // 2026-09-21

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'url' => 'victron-mppt',
            'title' => 'Victron MPPT 100/30',
            'reference' => 'SCC1',
            'brand_path' => 'victron',
            'model_path' => 'mppt-100-30',
            'product_available' => 1,
            'descr_short' => 'In stock',
            'custom_offer' => '',
            'packed_weight' => '1.5',
            'price_exc_tax' => '100.00',
            'rrp_exc_tax' => '125.00',
            'has_options' => 1,
            'created_at' => '2025-01-01 00:00:00',
        ];
    }

    private function card(array $overrides = [], float $rate = 20.0): array
    {
        return ShopProductCard::fromRow(productData: $this->row($overrides), productId: 7, taxRatePercent: $rate, now: self::NOW);
    }

    public function test_prices_are_formatted_with_and_without_vat(): void
    {
        $card = $this->card();

        $this->assertSame('100.00', $card['priceExcTax']);
        $this->assertSame('120.00', $card['priceIncTax']);
        $this->assertSame('125.00', $card['rrpExcTax']);
        $this->assertSame('150.00', $card['rrpIncTax']);
    }

    public function test_the_saving_compares_prices_with_vat_included(): void
    {
        $this->assertSame(['savings' => '30.00', 'savingsPercent' => 20], $this->card()['savingsOnRRP']);
    }

    public function test_the_vat_rate_is_applied_as_given(): void
    {
        $card = $this->card(rate: 5.0);

        $this->assertSame('105.00', $card['priceIncTax']);
        $this->assertSame('131.25', $card['rrpIncTax']);
    }

    public function test_thousands_are_separated_in_displayed_prices(): void
    {
        $this->assertSame('1,440.00', $this->card(['price_exc_tax' => '1200'])['priceIncTax']);
    }

    public function test_no_rrp_gives_a_zero_rrp_and_no_percentage_saving(): void
    {
        $card = $this->card(['rrp_exc_tax' => null]);

        $this->assertSame('0.00', $card['rrpIncTax']);
        $this->assertSame(0, $card['savingsOnRRP']['savingsPercent']);
    }

    public function test_a_price_above_the_rrp_is_a_negative_saving_with_a_real_percentage(): void
    {
        $this->assertSame(['savings' => '-30.00', 'savingsPercent' => -25], ShopProductCard::savings(125.0, 100.0, 20.0));
    }

    public function test_zero_prices_save_nothing(): void
    {
        $this->assertSame(0, ShopProductCard::savings(0.0, 100.0, 20.0)['savingsPercent']);
        $this->assertSame(0, ShopProductCard::savings(100.0, 0.0, 20.0)['savingsPercent']);
    }

    public function test_a_product_added_within_a_month_is_new(): void
    {
        $this->assertTrue($this->card(['created_at' => '2026-09-10 12:00:00'])['isNew']);
        $this->assertFalse($this->card(['created_at' => '2026-07-01 12:00:00'])['isNew']);
        $this->assertFalse($this->card(['created_at' => null])['isNew']);
    }

    public function test_flags_and_labels_are_carried_over_with_safe_defaults(): void
    {
        $card = $this->card();

        $this->assertSame(7, $card['id']);
        $this->assertSame('victron-mppt', $card['stub']);
        $this->assertSame(1, $card['isAvailable']);
        $this->assertSame(1.5, $card['packedWeight']);
        $this->assertTrue($card['hasOptions']);
        $this->assertSame('In stock', $card['shortStatus']);

        $bare = ShopProductCard::fromRow(productData: [], productId: 9, taxRatePercent: 20.0, now: self::NOW);

        $this->assertSame(0, $bare['isAvailable']);
        $this->assertFalse($bare['hasOptions']);
        $this->assertSame('', $bare['stub']);
        $this->assertSame('0.00', $bare['priceIncTax']);
    }

    public function test_the_image_base_path_follows_the_brand_and_model(): void
    {
        $this->assertSame('/shop/victron/mppt-100-30/medium/victron-mppt-100-30', ShopProductCard::imageBasePath('victron', 'mppt-100-30'));
    }
}
