<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\SetupChecklist;

/**
 * What the shop adds to the dashboard's quick links tile row (dashboard.quick_links) and to the "Finish setting up
 * your site" checklist (setup.checklist).
 */
class ShopDashboardLinks
{
    /**
     * @param array{osmium: object} $payload
     * @return array<string, string>|null the Manage Products tile
     */
    public static function quickLink(array $payload): ?array
    {
        return [
            'label' => 'Products',
            'title' => 'Manage Products',
            'icon' => 'bx-box',
            'button' => 'View Products',
            'url' => 'shop/products/',
        ];
    }

    /**
     * @param array{osmium: object} $payload
     * @return array{done: bool, label: string, url: string}|null the delivery message step
     */
    public static function checklistItem(array $payload): ?array
    {
        $osmium = $payload['osmium'];
        $message = (string)(((array)($osmium->config->shop ?? []))['deliveryMessage'] ?? '');

        return [
            'done' => !SetupChecklist::isPlaceholder($message),
            'label' => 'Write your delivery message',
            'url' => 'settings/shop/',
        ];
    }
}
