<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use WeakMap;

/**
 * The shop's admin helpers (products, images, options), built on first use from the models the admin was given.
 * They used to be properties of OsmiumAdmin; core no longer knows about them.
 */
final class ShopAdminServices
{
    /** @var WeakMap<OsmiumAdmin, array<string, object>>|null */
    private static ?WeakMap $built = null;

    public static function shop(OsmiumAdmin $admin, bool $urlTrailingSlash = false): AdminShop
    {
        return self::get($admin, 'shop', static fn () => new AdminShop(
            $admin->model->product,
            $admin->model->brand,
            $admin->model->productOptionType,
            $admin->model->productOptionValue,
            $urlTrailingSlash,
        ));
    }

    public static function images(OsmiumAdmin $admin): AdminShopImages
    {
        return self::get($admin, 'images', static fn () => new AdminShopImages($admin->model->product));
    }

    public static function options(OsmiumAdmin $admin): AdminShopOptions
    {
        return self::get($admin, 'options', static fn () => new AdminShopOptions($admin->model->productOptionType, $admin->model->productOptionValue));
    }

    /** Use this helper instead of the one that would be built (tests). */
    public static function override(OsmiumAdmin $admin, string $name, object $service): void
    {
        self::$built ??= new WeakMap();
        $services = self::$built[$admin] ?? [];
        $services[$name] = $service;
        self::$built[$admin] = $services;
    }

    private static function get(OsmiumAdmin $admin, string $name, callable $build): object
    {
        self::$built ??= new WeakMap();
        $services = self::$built[$admin] ?? [];
        $services[$name] ??= $build();
        self::$built[$admin] = $services;

        return $services[$name];
    }
}
