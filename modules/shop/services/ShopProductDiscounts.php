<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

use Osmium\Core\Library\PaymentProviders;
use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;

require_once __DIR__ . '/../../../models/CheckoutModel.php';
require_once __DIR__ . '/../../../models/CheckoutDiscount.php';
require_once __DIR__ . '/../../../models/ShopDiscountService.php';
require_once __DIR__ . '/../../../models/ShopTotalsService.php';

/**
 * The discounts a product or listing page can advertise: what is on offer for a product and the price it would
 * cost, worked out by the same rule the basket uses (ShopTotalsService::discountedUnitPrice), so a page never
 * promises a price the basket does not charge. Data only: the theme decides how, or whether, to show it.
 *
 * Only per-product discounts (a product, category or brand scope) are advertised on a product. Coupons depend on a
 * code and a minimum spend depends on the basket, so neither is promised here. When the selected payment provider
 * does not support discounts, or an unconditional sitewide sale is running (it takes the place of a per-product
 * discount in the basket), nothing is advertised.
 */
class ShopProductDiscounts
{
    /**
     * @param array<int, array> $productsData Product rows keyed by product id (price_exc_tax is read from each)
     * @return array<int, array> Described discounts keyed by product id; a product with none is absent
     */
    public static function forProducts(object $osmium, array $productsData, float $taxRatePercent, string $currencySign): array
    {
        if ($productsData === []) return [];

        $service = self::service($osmium);
        if ($service === null) return [];

        $unconditionalSale = \array_filter(
            $service->activeSitewideDiscounts(),
            static fn(array $discount): bool => (float)$discount['value'] > 0 && ($discount['min_spend_inc_tax'] ?? null) === null,
        );
        if ($unconditionalSale !== []) return [];

        $found = $service->activeProductDiscounts(\array_map('intval', \array_keys($productsData)));

        $described = [];
        foreach ($found as $productId => $discount) {
            $priceExcTax = (float)($productsData[$productId]['price_exc_tax'] ?? 0);
            if ($priceExcTax <= 0) continue;

            $described[(int)$productId] = self::describe($discount, $priceExcTax, $taxRatePercent, $currencySign);
        }

        return $described;
    }

    /**
     * One discount against one price: its label and the price after it.
     *
     * @return array{id: int, name: string, valueType: string, value: float, label: string, priceExcTax: string, priceIncTax: string}
     */
    public static function describe(array $discount, float $priceExcTax, float $taxRatePercent, string $currencySign): array
    {
        $afterExcTax = ShopTotalsService::discountedUnitPrice($priceExcTax, $discount);
        $afterIncTax = \round($afterExcTax + \round($afterExcTax * ($taxRatePercent / 100), 2), 2); // VAT per unit, as the basket does

        $isPercent = $discount['value_type'] === 'percent';
        $value = (float)$discount['value'];

        return [
            'id' => (int)$discount['id'],
            'name' => (string)$discount['name'],
            'valueType' => (string)$discount['value_type'],
            'value' => $value,
            'label' => $isPercent ? self::trimNumber($value) . '% off' : $currencySign . self::trimNumber($value) . ' off',
            'priceExcTax' => \number_format($afterExcTax, 2),
            'priceIncTax' => \number_format($afterIncTax, 2),
        ];
    }

    /**
     * The sitewide free delivery on offer, for a banner: the lowest spend that earns it. Null when there is none or
     * discounts are not supported.
     *
     * @return array{minSpendIncTax: ?float, label: string}|null
     */
    public static function freeDeliveryOffer(object $osmium, string $currencySign): ?array
    {
        $service = self::service($osmium);
        if ($service === null) return null;

        $offers = \array_filter($service->activeSitewideDiscounts(), static fn(array $discount): bool => !empty($discount['free_delivery']));
        if ($offers === []) return null;

        $minimums = \array_map(static fn(array $discount): float => (float)($discount['min_spend_inc_tax'] ?? 0), $offers);
        $lowest = \min($minimums);

        return [
            'minSpendIncTax' => $lowest > 0 ? $lowest : null,
            'label' => $lowest > 0 ? 'Free delivery over ' . $currencySign . self::trimNumber($lowest) : 'Free delivery',
        ];
    }

    /** Discounts as the basket applies them, or null when the selected payment provider does not support them */
    private static function service(object $osmium): ?ShopDiscountService
    {
        $provider = PaymentProviders::configured($osmium->config->checkout, PaymentProviders::external($osmium));
        if (!PaymentProviders::supportsDiscounts($provider)) return null;

        return new ShopDiscountService(new CheckoutDiscount($osmium->dataSource, 'discounts'));
    }

    private static function trimNumber(float $number): string
    {
        return $number == \floor($number) ? (string)(int)$number : \number_format($number, 2, '.', ''); // 200 not 200.00, but 2.50 keeps its pence
    }
}
