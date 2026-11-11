<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Osmium\Modules\Shop\Services\ShopProductDiscounts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../modules/shop/services/ShopProductDiscounts.php';

/**
 * What a product or listing page advertises for a discount: the label, and the price after it, which must be the
 * price the basket charges.
 */
final class ShopProductDiscountsTest extends TestCase
{
    private function percent(float $value = 15.0): array
    {
        return ['id' => 7, 'name' => 'Spring bolts', 'value_type' => 'percent', 'value' => $value];
    }

    public function test_a_percentage_discount_is_labelled_and_priced(): void
    {
        $described = ShopProductDiscounts::describe($this->percent(), 3.50, 20.0, '£');

        $this->assertSame('15% off', $described['label']);
        $this->assertSame('2.97', $described['priceExcTax']); // 3.50 less 0.53
        $this->assertSame('3.56', $described['priceIncTax']); // VAT taken on the discounted unit price
        $this->assertSame(7, $described['id']);
    }

    public function test_a_fixed_discount_keeps_its_pence_in_the_label(): void
    {
        $whole = ShopProductDiscounts::describe(['id' => 1, 'name' => 'x', 'value_type' => 'fixed', 'value' => 2.0], 5.00, 20.0, '£');
        $pence = ShopProductDiscounts::describe(['id' => 1, 'name' => 'x', 'value_type' => 'fixed', 'value' => 2.5], 5.00, 20.0, '£');

        $this->assertSame('£2 off', $whole['label']);
        $this->assertSame('3.00', $whole['priceExcTax']);
        $this->assertSame('£2.50 off', $pence['label']);
    }

    public function test_a_discount_never_takes_the_price_below_nothing(): void
    {
        $described = ShopProductDiscounts::describe(['id' => 1, 'name' => 'x', 'value_type' => 'fixed', 'value' => 9.0], 5.00, 20.0, '£');

        $this->assertSame('0.00', $described['priceExcTax']);
    }

    public function test_the_advertised_price_is_the_price_the_basket_uses(): void
    {
        foreach ([0.99, 3.50, 12.34, 100.00] as $price) {
            $discount = $this->percent(15.0);

            $this->assertSame(
                \number_format(ShopTotalsService::discountedUnitPrice($price, $discount), 2),
                ShopProductDiscounts::describe($discount, $price, 20.0, '£')['priceExcTax'],
                "price {$price}",
            );
        }
    }
}
