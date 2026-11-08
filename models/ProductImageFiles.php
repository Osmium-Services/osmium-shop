<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Which generated copies of a product's main image exist on disk. The admin product form and the image upload both
 * ask this, so there is one answer.
 */
class ProductImageFiles
{
    /**
     * @return array{large: bool, medium: bool, thumb: bool, webp: bool, avif: bool}
     */
    public static function derivations(string $brandPath, string $modelPath): array
    {
        $baseDir = $_SERVER['DOCUMENT_ROOT'] . ShopPaths::productDir($brandPath, $modelPath);
        $baseName = $brandPath . '-' . $modelPath;

        return [
            'large' => self::hasSize($baseDir . '/large', $baseName),
            'medium' => self::hasSize($baseDir . '/medium', $baseName),
            'thumb' => self::hasSize($baseDir . '/thumb', $baseName),
            'webp' => \file_exists(filename: $baseDir . '/large/webp/' . $baseName . '.webp'),
            'avif' => \file_exists(filename: $baseDir . '/large/avif/' . $baseName . '.avif'),
        ];
    }

    /** Whether a size folder holds the image in any supported extension. */
    private static function hasSize(string $dir, string $baseName): bool
    {
        foreach (['.jpg', '.png', '.webp'] as $extension) {
            $imageExists = \file_exists(filename: $dir . '/' . $baseName . $extension);
            if ($imageExists) return true;
        }

        return false;
    }
}
