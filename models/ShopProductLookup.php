<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Admin\Models\Product;

/**
 * Answers shop.product.get: one live product's facts for another service, so it never reads the shop's tables.
 * Read-only, no customer data. Prices are ex tax; the tax rate stays in Shop settings.
 */
class ShopProductLookup
{
    /**
     * @param array{productId?: int|string, dataSource?: OsmiumPDO} $payload
     * @return array{id: int, brandId: int, brandName: string, name: string, reference: string, priceExcTax: float, available: bool}|null
     *         null when the product does not exist, is deleted, or no database was given
     */
    public static function get(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $row = (new Product($db, 'products'))->getForLookup((int) ($payload['productId'] ?? 0));

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, brandId: int, brandName: string, name: string, reference: string, priceExcTax: float, available: bool}
     */
    public static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'brandId' => (int) $row['brand_id'],
            'brandName' => (string) ($row['brand_name'] ?? ''),
            'name' => (string) $row['model_name'],
            'reference' => (string) ($row['reference'] ?? ''),
            'priceExcTax' => (float) $row['price_exc_tax'],
            'available' => (bool) $row['available'],
        ];
    }
}
