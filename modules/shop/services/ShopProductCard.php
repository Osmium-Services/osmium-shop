<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

/**
 * The product summary a listing page (category, featured products, new arrivals)
 * prints for each product: formatted prices with and without VAT, the saving
 * against the RRP, and the flags the card shows.
 *
 * One place for the price arithmetic, so every listing shows the same figures.
 * Image paths are left to the caller, which owns the image lookup.
 */
class ShopProductCard
{
    /**
     * @param array $productData One product row from ShopProduct::loadMultipleByIDs()
     * @param float $taxRatePercent VAT rate, e.g. 20.0
     * @param int|null $now Timestamp "new" is measured from (defaults to the current time)
     */
    public static function fromRow(array $productData, int $productId, float $taxRatePercent, ?int $now = null): array
    {
        $priceExcTax = (float)($productData['price_exc_tax'] ?? 0);
        $rrpExcTax = (float)($productData['rrp_exc_tax'] ?? 0);

        $addedDate = $productData['created_at'] ?? null;
        $isNew = $addedDate && \strtotime($addedDate) > \strtotime(datetime: '-1 month', baseTimestamp: $now);

        return [
            'id' => $productId,
            'stub' => $productData['url'] ?? '',
            'isNew' => (bool)$isNew,
            'isAvailable' => (int)($productData['product_available'] ?? 0),
            'title' => $productData['title'] ?? '',
            'reference' => $productData['reference'] ?? '',
            'modelPath' => $productData['model_path'] ?? '',
            'brandPath' => $productData['brand_path'] ?? '',
            'packedWeight' => (float)($productData['packed_weight'] ?? 0),
            'shortStatus' => $productData['descr_short'] ?? '',
            'customOffer' => $productData['custom_offer'] ?? '',
            'priceExcTax' => \number_format(num: $priceExcTax, decimals: 2),
            'priceIncTax' => \number_format(num: self::addTax($priceExcTax, $taxRatePercent), decimals: 2),
            'rrpExcTax' => \number_format(num: $rrpExcTax, decimals: 2),
            'rrpIncTax' => \number_format(num: self::addTax($rrpExcTax, $taxRatePercent), decimals: 2),
            'hasOptions' => (bool)($productData['has_options'] ?? false),
            'savingsOnRRP' => self::savings($priceExcTax, $rrpExcTax, $taxRatePercent),
        ];
    }

    /**
     * Base path of a product's medium listing image, without extension or @2x.
     */
    public static function imageBasePath(string $brandPath, string $modelPath): string
    {
        return "/shop/{$brandPath}/{$modelPath}/medium/{$brandPath}-{$modelPath}";
    }

    /**
     * Saving against the RRP, both prices compared with VAT included.
     *
     * @return array{savings: string, savingsPercent: int}
     */
    public static function savings(float $priceExcTax, float $rrpExcTax, float $taxRatePercent): array
    {
        $rrp = self::addTax($rrpExcTax, $taxRatePercent);
        $selling = self::addTax($priceExcTax, $taxRatePercent);

        $eitherZero = $rrp == 0 || $selling == 0;
        $percent = $eitherZero ? 0 : (int)\round((($rrp - $selling) / $rrp) * 100, 0);

        return [
            'savings' => \number_format($rrp - $selling, 2),
            'savingsPercent' => $percent,
        ];
    }

    private static function addTax(float $priceExcTax, float $taxRatePercent): float
    {
        return $priceExcTax + ($priceExcTax * ($taxRatePercent / 100));
    }
}
