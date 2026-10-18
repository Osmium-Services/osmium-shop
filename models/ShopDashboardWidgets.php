<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Basket;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\User;

/**
 * The shop's dashboard cards, offered through the dashboard.widgets hook: the products and users tiles and the
 * baskets tiles. Each draws with its own view from this service's views/widgets/.
 */
class ShopDashboardWidgets
{
    private const BASKET_ABANDONED_HOURS = 2;

    /**
     * @param array{osmium: object, role: string} $payload
     * @return array<string, mixed>|null the products and users tiles
     */
    public static function quickStats(array $payload): ?array
    {
        $osmium = $payload['osmium'];
        $dataSource = $osmium->dataSource;

        return [
            'id' => 'quick_stats',
            'viewFile' => __DIR__ . '/../views/widgets/quick-stats.phtml',
            'order' => 22,
            'cols' => 'col-12',
            'data' => [
                'productCount' => (new Product($dataSource, 'products'))->countActive(),
                'userCount' => (new User($dataSource, 'users'))->countAll(),
            ],
        ];
    }

    /**
     * @param array{osmium: object, role: string} $payload
     * @return array<string, mixed>|null the baskets tiles
     */
    public static function baskets(array $payload): ?array
    {
        $osmium = $payload['osmium'];
        $baskets = new Basket($osmium->dataSource, 'checkout_baskets');
        $stats = $baskets->stats(abandonedHours: self::BASKET_ABANDONED_HOURS);

        return [
            'id' => 'baskets',
            'viewFile' => __DIR__ . '/../views/widgets/baskets.phtml',
            'order' => 20,
            'cols' => 'col-12',
            'data' => [
                'active_count' => (int) ($stats['active_count'] ?? 0),
                'converted_24h' => (int) ($stats['converted_24h'] ?? 0),
                'active_value' => $baskets->activeValue(),
                'converted_value' => $baskets->convertedValue24h(),
            ],
        ];
    }
}
