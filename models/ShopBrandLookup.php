<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Admin\Models\Brand;

/**
 * Answers shop.brands: the live brands, for a service that needs to let the admin pick some (such as brands left out of
 * a trade discount). Read-only, no customer data.
 */
class ShopBrandLookup
{
    /**
     * @param array{dataSource?: OsmiumPDO} $payload
     * @return array<int, array{id: int, name: string}>|null the live brands by name; null when no database was given
     */
    public static function brands(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        return self::shape((new Brand($db))->getActive());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id: int, name: string}>
     */
    public static function shape(array $rows): array
    {
        return \array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $rows);
    }
}
