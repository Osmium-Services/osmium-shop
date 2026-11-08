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

    /**
     * A brand or model path as stored, which must be only a-z, 0-9 and single hyphens, so it can't leave the product folder.
     *
     * @throws \InvalidArgumentException when the path is not of that shape
     */
    public static function normalize(string $path): string
    {
        $decoded = \html_entity_decode(string: $path, flags: ENT_QUOTES | ENT_HTML5);

        $unsafe = \preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $decoded) !== 1;
        if ($unsafe) throw new \InvalidArgumentException('Invalid brand or model path');

        return $decoded;
    }

    /** The site-relative folder a product's images and files live in, with no trailing slash. */
    public static function productDir(string $brandPath, string $modelPath): string
    {
        return '/assets/images/shop/' . $brandPath . '/' . $modelPath;
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
