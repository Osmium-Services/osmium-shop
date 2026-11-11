<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\ImageTools;

/**
 * Stores and removes the two images of a product category: the card image shown on the parent's page and
 * the social (share) image. Each upload is cropped to its shape and saved as one JPG under a fresh random name,
 * so a replaced image is never served from a browser's cache.
 */
class CategoryImages
{
    private const KINDS = [
        'image' => ['width' => 450, 'height' => 338, 'folder' => '/shop/categories/{name}', 'file' => '{name}'],
        'social_image' => ['width' => 1200, 'height' => 630, 'folder' => '/social', 'file' => '{name}'],
    ];

    private const SOURCES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const QUALITY = 85;

    public function __construct(
        private ImageTools $tools,
        private string $imagesRoot,
    ) {}

    /**
     * @param array<string, mixed> $file One entry of $_FILES
     * @return string The name to keep in the category's image column
     * @throws \InvalidArgumentException when the upload can't be used
     * @throws \RuntimeException when the file can't be written
     */
    public function store(string $kind, array $file): string
    {
        $spec = $this->spec($kind);

        $uploadFailed = ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK;
        if ($uploadFailed) throw new \InvalidArgumentException('The image could not be uploaded. Please try again');

        $tooLarge = ($file['size'] ?? 0) > self::MAX_BYTES;
        if ($tooLarge) throw new \InvalidArgumentException('The image is too large. Maximum 10MB allowed');

        $source = $this->tools->load(path: $file['tmp_name'], allowedTypes: self::SOURCES);
        $image = $this->tools->cover(image: $source, width: $spec['width'], height: $spec['height'], keepAlpha: false);

        $name = 'cat-' . \bin2hex(\random_bytes(6));
        $directory = $this->imagesRoot . \str_replace('{name}', $name, $spec['folder']);
        $dirMissing = !\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory);
        if ($dirMissing) throw new \RuntimeException('The image folder could not be created');

        $saved = \imagejpeg($image, $directory . '/' . $name . '.jpg', self::QUALITY);
        if (!$saved) throw new \RuntimeException('The image could not be saved');

        return $name;
    }

    /** Delete every file of an image: the JPG and any WebP or AVIF copies. Unknown or unsafe names are ignored. */
    public function delete(string $kind, string $name): void
    {
        $spec = $this->spec($kind);
        $unsafe = $name === '' || \preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1;
        if ($unsafe) return;

        $directory = $this->imagesRoot . \str_replace('{name}', $name, $spec['folder']);
        $isOwnFolder = $kind === 'image';
        $paths = [
            $directory . '/' . $name . '.jpg',
            $directory . '/' . $name . '.png',
            $directory . '/webp/' . $name . '.webp',
            $directory . '/avif/' . $name . '.avif',
        ];
        foreach ($paths as $path) {
            if (\is_file($path)) @\unlink($path);
        }

        if ($isOwnFolder) {
            @\rmdir($directory . '/webp');
            @\rmdir($directory . '/avif');
            @\rmdir($directory); // Only goes when empty, so a folder holding anything else stays
        }
    }

    /** @return array{width: int, height: int, folder: string, file: string} */
    private function spec(string $kind): array
    {
        $unknown = !isset(self::KINDS[$kind]);
        if ($unknown) throw new \InvalidArgumentException('Unknown image type');

        return self::KINDS[$kind];
    }
}
