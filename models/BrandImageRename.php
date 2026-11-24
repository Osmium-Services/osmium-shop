<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Brand;

/**
 * Renames a brand's product images when the brand is renamed. Images live in /assets/images/shop/<brand>/<model>/ and are
 * named <brand>-<model>..., so the folder, every file inside it and the description-image paths stored on the products
 * must all follow the new name. Everything is checked first and undone if any step fails, so a brand is never left half
 * renamed.
 */
final class BrandImageRename
{
    /**
     * Move the images to the new brand name, then run $save (the brand's own update). If anything fails the images and
     * description paths go back as they were.
     *
     * @param callable(): void $save
     * @return string|null a plain-words reason the rename was refused, or null when it went through
     */
    public static function run(Brand $brands, int $brandId, string $oldName, string $newName, callable $save, ?string $docRoot = null): ?string
    {
        $oldPath = ShopPaths::brand($oldName);
        $newPath = ShopPaths::brand($newName);
        $docRoot ??= (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        $sameFolder = $oldPath === $newPath;
        if ($sameFolder || $oldPath === '') {
            $save();
            return null;
        }
        if ($newPath === '') return 'That name leaves no letters or numbers to name the image folder with.';

        $base = $docRoot . '/assets/images/shop';
        $oldDir = $base . '/' . $oldPath;
        $newDir = $base . '/' . $newPath;

        $hasImages = \is_dir($oldDir);
        if ($hasImages && \file_exists($newDir)) return "The image folder \"{$newPath}\" already exists, so the images cannot be moved. Nothing was changed.";
        if ($hasImages && (!\is_writable($base) || !\is_writable($oldDir))) return 'The image folders are not writable, so the images cannot be moved. Nothing was changed.';

        ImageUploadLog::write("=== RENAME BRAND IMAGES === {$oldPath} -> {$newPath}");
        $files = $hasImages ? self::filesToRename($oldDir, $oldPath . '-') : [];

        $done = [];
        $originalRows = [];
        try {
            if ($hasImages) {
                if (!@\rename($oldDir, $newDir)) return 'The image folder could not be renamed. Nothing was changed.';
                $done[] = [$newDir, $oldDir];

                foreach ($files as $relative) {
                    $from = $newDir . '/' . $relative;
                    $to = $newDir . '/' . self::newRelative($relative, $oldPath, $newPath);
                    if (!@\rename($from, $to)) throw new \RuntimeException("Could not rename {$relative}");
                    $done[] = [$to, $from];
                }
            }

            foreach ($brands->descrImagesMentioning($brandId, $oldPath) as $row) {
                $original = ['descr_image' => $row['descr_image'], 'descr2_image' => $row['descr2_image'], 'descr3_image' => $row['descr3_image']];
                $originalRows[(int) $row['id']] = $original;
                $brands->setDescrImages((int) $row['id'], \array_map(fn ($html) => self::rewriteHtml($html, $oldPath, $newPath), $original));
            }

            $save();
        } catch (\Throwable $e) {
            ImageUploadLog::write('ERROR: brand image rename failed, undoing: ' . $e->getMessage());
            foreach ($originalRows as $productId => $original) {
                try { $brands->setDescrImages($productId, $original); } catch (\Throwable) {}
            }
            foreach (\array_reverse($done) as [$from, $to]) {
                @\rename($from, $to);
            }
            return 'The images could not all be renamed, so nothing was changed (' . $e->getMessage() . ').';
        }

        ImageUploadLog::write('Renamed ' . \count($files) . ' files and ' . \count($originalRows) . ' product descriptions');
        return null;
    }

    /** @return list<string> paths relative to the brand folder of every file named <oldbrand>-... */
    private static function filesToRename(string $dir, string $prefix): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !\str_starts_with($file->getFilename(), $prefix)) continue;
            $found[] = \substr($file->getPathname(), \strlen($dir) + 1);
        }

        return $found;
    }

    private static function newRelative(string $relative, string $oldPath, string $newPath): string
    {
        $folder = \dirname($relative);
        $name = $newPath . \substr(\basename($relative), \strlen($oldPath));

        return ($folder === '.' ? '' : $folder . '/') . $name;
    }

    /** Point every /shop/<old>/... address in a description's HTML at the new folder and file names. */
    private static function rewriteHtml(?string $html, string $oldPath, string $newPath): ?string
    {
        if ($html === null || $html === '') return $html;

        $pattern = '#(/shop/)' . \preg_quote($oldPath, '#') . '/((?:[^"\'\s<>?]*/)?)([^/"\'\s<>?]*)#';

        return \preg_replace_callback($pattern, static function (array $m) use ($oldPath, $newPath): string {
            $file = \str_starts_with($m[3], $oldPath . '-') ? $newPath . \substr($m[3], \strlen($oldPath)) : $m[3];
            return $m[1] . $newPath . '/' . $m[2] . $file;
        }, $html) ?? $html;
    }
}
