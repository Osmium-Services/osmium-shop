<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * The URL- and folder-safe names a product's images live under: a brand path and a model path, each only a-z, 0-9
 * and hyphens.
 */
class ShopPaths
{
    public static function brand(string $brandName): string
    {
        return self::slug($brandName);
    }

    public static function model(string $modelName): string
    {
        return self::slug($modelName);
    }

    private static function slug(string $name): string
    {
        $path = \mb_strtolower(\trim($name));

        $path = \str_replace(search: ['/', '\\', '&'], replace: '', subject: $path); // Explicitly strip dangerous filesystem/URL characters

        $path = \preg_replace(pattern: '/[^a-z0-9-]+/', replacement: '-', subject: $path); // Strip any character that isn't a-z, 0-9, or hyphen

        $path = \preg_replace(pattern: '/-+/', replacement: '-', subject: $path); // Collapse consecutive hyphens

        return \trim(string: $path, characters: '-'); // Trim hyphens from edges
    }
}
