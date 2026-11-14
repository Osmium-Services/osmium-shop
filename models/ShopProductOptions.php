<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Admin\Models\Product;

/**
 * Answers shop.product.options: the option types of live products and the values under each, so another service can offer
 * and price a configured product without reading the shop's tables. Read-only, no customer data. Premiums are ex tax.
 */
class ShopProductOptions
{
    /**
     * @param array{dataSource?: OsmiumPDO, productId?: int|string} $payload `productId` narrows the answer to one product
     * @return array<int, array<int, array{id: int, label: string, defaultLabel: ?string, values: array<int, array{id: int, label: string, premium: float, reference: string}>}>>|null by product id; null when no database was given
     */
    public static function options(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $byProduct = self::byProduct((new Product($db, 'products'))->listOptionsForHook());

        $productId = (int) ($payload['productId'] ?? 0);

        return $productId > 0 ? \array_intersect_key($byProduct, [$productId => true]) : $byProduct;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, array{id: int, label: string, defaultLabel: ?string, values: array<int, array{id: int, label: string, premium: float, reference: string}>}>>
     */
    public static function byProduct(array $rows): array
    {
        $byProduct = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $typeId = (int) $row['type_id'];
            $default = (string) ($row['default_option_descr'] ?? '');

            $byProduct[$productId][$typeId] ??= ['id' => $typeId, 'label' => (string) $row['type_label'], 'defaultLabel' => $default === '' ? null : $default, 'values' => []];

            $hasValue = $row['value_id'] !== null;
            if ($hasValue) $byProduct[$productId][$typeId]['values'][] = ['id' => (int) $row['value_id'], 'label' => (string) $row['option_descr'], 'premium' => (float) $row['option_premium'], 'reference' => (string) ($row['option_reference'] ?? '')];
        }

        return \array_map(\array_values(...), $byProduct);
    }
}
