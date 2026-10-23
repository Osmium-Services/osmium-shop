<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Display values for the admin products list (SEO and availability badges,
 * VAT price, formatted dates) so the view only echoes. Pure given its inputs.
 */
class ProductListView
{
    public static function product(array $product, float|int $taxRate): array
    {

        $robots = $product['robots'] ?? 'index,follow';
        $robotsIndexed = \str_starts_with(haystack: $robots, needle: 'index');
        $robotsNoindexFollow = $robots === 'noindex,follow';
        if ($robotsIndexed) {
            $seoStatus = 'visible';
            $seoClass = 'success';
            $seoLabel = 'Visible';
            $seoTitle = 'Allow search engines to index: ';
        } elseif ($robotsNoindexFollow) {
            $seoStatus = 'partial';
            $seoClass = 'warning';
            $seoLabel = 'Partial';
            $seoTitle = 'Block indexing but follow links: ';
        } else {
            $seoStatus = 'blocked';
            $seoClass = 'danger';
            $seoLabel = 'Blocked';
            $seoTitle = 'Block search engines from indexing: '; // SEO badge calculation
        }

        $isAvailable = ($product['available'] ?? 0) == 1 && ($product['has_category'] ?? 0) == 1;
        $availClass = $isAvailable ? 'success' : 'danger';
        $availTitle = $isAvailable
            ? 'Available to buy'
            : (($product['has_category'] ?? 0) == 0 ? 'Hidden (no category)' : 'Not available to buy'); // Availability badge

        $priceExcTax = (float)($product['price'] ?? $product['price_exc_tax'] ?? 0);
        $priceIncVat = $priceExcTax * (1 + ($taxRate / 100)); // Price with VAT

        $updatedAt = \max($product['updated_at'] ?? '', $product['deleted_at'] ?? '') ?: null;
        $createdAt = $product['created_at'] ?? $product['created'] ?? null;
        $updatedFormatted = $updatedAt
            ? \date(format: 'd M H:i', timestamp: \strtotime($updatedAt))
            : ($createdAt ? \date(format: 'd M H:i', timestamp: \strtotime($createdAt)) : '-'); // Date formatting

        $brandName = $product['brand_name'] ?? $product['brand'] ?? '';
        $modelName = $product['model_name'] ?? $product['model'] ?? '';
        $productTitle = \trim($brandName . ' ' . $modelName); // Product title

        return [
            'id' => $product['id'], // Original data
            'reference' => $product['reference'] ?? '',
            'brand_name' => $brandName,
            'model_name' => $modelName,
            'price_exc_tax' => $priceExcTax,
            'rrp_exc_tax' => $product['rrp_exc_tax'] ?? $product['rrp'] ?? null,
            'status_name' => $product['status_name'] ?? $product['status'] ?? '',
            'available' => $product['available'] ?? 0,
            'has_category' => $product['has_category'] ?? 0,
            'url' => $product['url'] ?? '',
            'robots' => $robots,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'thumbnail' => $product['thumbnail'] ?? '',
            'deleted_at' => $product['deleted_at'] ?? null,
            'changed_by' => $product['changed_by'] ?? null,
            'changed_by_avatar' => $product['changed_by_avatar'] ?? null,

            'productTitle' => $productTitle, // Formatted display values
            'seoStatus' => $seoStatus,
            'seoClass' => $seoClass,
            'seoLabel' => $seoLabel,
            'seoTitle' => $seoTitle . $robots,
            'availClass' => $availClass,
            'availTitle' => $availTitle,
            'isAvailable' => $isAvailable,
            'priceIncVat' => $priceIncVat,
            'updatedFormatted' => $updatedFormatted,
        ];
    }

    public static function deletedProduct(array $product): array
    {
        $deletedFormatted = $product['deleted_at'] ?? null;
        if ($deletedFormatted) {
            $deletedFormatted = \date(format: 'd M Y, H:i', timestamp: \strtotime($deletedFormatted));
        }

        $brandName = $product['brand_name'] ?? '';
        $modelName = $product['model_name'] ?? '';
        $productTitle = \trim($brandName . ' ' . $modelName);

        return [
            'id' => $product['id'],
            'brand_name' => $brandName,
            'model_name' => $modelName,
            'reference' => $product['reference'] ?? '',
            'deleted_at' => $product['deleted_at'] ?? null,
            'changed_by' => $product['changed_by'] ?? null,
            'changed_by_avatar' => $product['changed_by_avatar'] ?? null,
            'productTitle' => $productTitle,
            'deletedFormatted' => $deletedFormatted ?: '-',
        ];
    }
}
