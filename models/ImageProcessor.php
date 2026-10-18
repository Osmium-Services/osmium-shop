<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Image Processor Service
 * Handles product image processing and variant generation
 */
class ImageProcessor
{
    private const SIZES = [
        'thumb'  => ['width' => 136, 'height' => 136],
        'medium' => ['width' => 235, 'height' => 235],
        'large'  => ['width' => 450, 'height' => 450],
        'extra'  => ['width' => 450, 'height' => 450],
    ];

    private const QUALITY = [
        'jpg'  => 85,
        'webp' => 80,
        'avif' => 60,
    ];

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = \rtrim($basePath, '/');
    }

    /**
     * Process an uploaded product image and generate all variants
     */
    public function processProductImage(
        string $tempPath,
        string $brandPath,
        string $modelPath,
        string $imageType = ''
    ): array {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths - decode HTML entities for consistent directory paths

        $this->debugLogStart($tempPath, $brandPath, $modelPath, $imageType);

        $sourceImage = $this->loadSourceImage($tempPath);
        if (!$sourceImage) {
            return ['success' => false, 'error' => 'Failed to load image'];
        }

        $srcWidth = imagesx($sourceImage);
        $srcHeight = imagesy($sourceImage);
        $baseDir = $this->basePath . '/assets/images/shop/' . $brandPath . '/' . $modelPath;
        $baseName = $this->buildBaseName($brandPath, $modelPath, $imageType);

        $this->debugLog("Source dimensions: {$srcWidth}x{$srcHeight}");
        $this->debugLog("baseDir: {$baseDir}");
        $this->debugLog("baseName (sanitized): {$baseName}");

        try {
            $generatedFiles = [];
            foreach (self::SIZES as $sizeName => $dimensions) {
                $sizeFiles = $this->processSizeVariant($sourceImage, $srcWidth, $srcHeight, $baseDir, $sizeName, $dimensions, $baseName);
                $generatedFiles = \array_merge($generatedFiles, $sizeFiles);
            }

            $this->debugLog('=== IMAGEPROCESSOR COMPLETE - SUCCESS ===');
            $this->debugLog('Generated ' . \count($generatedFiles) . ' files');
            return [
                'success' => true,
                'files' => $generatedFiles,
                'imagePath' => '/shop/' . $brandPath . '/' . $modelPath . '/large/' . $baseName . '.jpg'
            ];
        } catch (\Exception $e) {
            $this->debugLog('=== IMAGEPROCESSOR EXCEPTION: ' . $e->getMessage() . ' ===');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete all variants of an image
     */
    public function deleteImageVariants(
        string $brandPath,
        string $modelPath,
        string $imageType = ''
    ): array {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $baseDir = $this->basePath . '/assets/images/shop/' . $brandPath . '/' . $modelPath;
        $baseName = $this->buildBaseName($brandPath, $modelPath, $imageType);

        $deletedFiles = [];
        $errors = [];

        foreach (\array_keys(self::SIZES) as $sizeName) {
            $sizeDir = $baseDir . '/' . $sizeName;
            $webpDir = $sizeDir . '/webp';
            $avifDir = $sizeDir . '/avif';

            foreach ([1, 2] as $scale) {
                $suffix = $scale === 2 ? '@2x' : '';

                $jpgPath = $sizeDir . '/' . $baseName . $suffix . '.jpg';
                $this->tryDeleteFile($jpgPath, $deletedFiles, $errors); // Delete JPG from size folder

                $webpPath = $webpDir . '/' . $baseName . $suffix . '.webp';
                $this->tryDeleteFile($webpPath, $deletedFiles, $errors); // Delete WebP from webp subfolder

                $avifPath = $avifDir . '/' . $baseName . $suffix . '.avif';
                $this->tryDeleteFile($avifPath, $deletedFiles, $errors); // Delete AVIF from avif subfolder
            }
        }

        return [
            'success' => empty($errors),
            'deleted' => $deletedFiles,
            'errors' => $errors
        ];
    }

    /**
     * Check if an image exists for a given slot
     */
    public function imageExists(
        string $brandPath,
        string $modelPath,
        string $imageType = ''
    ): bool {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $baseDir = $this->basePath . '/assets/images/shop/' . $brandPath . '/' . $modelPath;
        $baseName = $this->buildBaseName($brandPath, $modelPath, $imageType);

        $checkPath = $baseDir . '/large/' . $baseName . '.jpg';
        $exists = \file_exists($checkPath); // Check for the large JPG as the canonical file

        $this->debugLog("imageExists check:");
        $this->debugLog("  brandPath: {$brandPath}");
        $this->debugLog("  modelPath: {$modelPath}");
        $this->debugLog("  baseName: {$baseName}");
        $this->debugLog("  checkPath: {$checkPath}");
        $this->debugLog("  exists: " . ($exists ? 'YES' : 'NO'));

        return $exists;
    }

    /**
     * Get all existing images for a product
     */
    public function getProductImages(string $brandPath, string $modelPath): array
    {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $images = [];
        $imageSlots = ['main', 'extra1', 'extra2', 'extra3', 'extra4', 'extra5'];

        foreach ($imageSlots as $slot) {
            $images[$slot] = $this->getSlotImagePath($brandPath, $modelPath, $slot);
        }

        return $images;
    }

    private function getSlotImagePath(string $brandPath, string $modelPath, string $slot): ?string
    {
        $imageType = $slot === 'main' ? '' : $slot;
        $imageNotFound = !$this->imageExists($brandPath, $modelPath, $imageType);
        if ($imageNotFound) return null; // Paths already normalized by caller (getProductImages)

        $baseName = $this->buildBaseName($brandPath, $modelPath, $imageType);

        return '/assets/images/shop/' . $brandPath . '/' . $modelPath
            . '/large/' . $baseName . '.jpg';
    }

    /**
     * Find the large source image for a product (checks multiple extensions)
     * @return string|null Full path to the image file, or null if not found
     */
    public function findLargeImage(
        string $brandPath,
        string $modelPath,
        string $imageType = ''
    ): ?string {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $baseDir = $this->basePath . '/assets/images/shop/' . $brandPath . '/' . $modelPath . '/large';
        $baseName = $this->buildBaseName($brandPath, $modelPath, $imageType);

        foreach (['.jpg', '.png', '.webp'] as $ext) {
            $path = $baseDir . '/' . $baseName . $ext;
            $pathExists = \file_exists($path);
            if ($pathExists) return $path; // Check for each supported extension
        }

        return null;
    }

    /**
     * Regenerate thumb and medium sizes from existing large image
     */
    public function regenerateFromLarge(
        string $brandPath,
        string $modelPath,
        string $imageType = ''
    ): array {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $sourcePath = $this->findLargeImage($brandPath, $modelPath, $imageType);
        if (!$sourcePath) return ['success' => false, 'error' => 'Large image not found']; // Find the source large image

        $sourceImage = $this->loadImage($sourcePath);
        if (!$sourceImage) return ['success' => false, 'error' => 'Failed to load image: ' . $sourcePath]; // Load source image

        $srcWidth = imagesx($sourceImage);
        $srcHeight = imagesy($sourceImage);

        $baseDir = $this->basePath . '/assets/images/shop/' . $brandPath . '/' . $modelPath;
        $baseName = $this->buildBaseName($brandPath, $modelPath, $imageType);

        $generatedFiles = [];

        try {
            foreach (['thumb', 'medium', 'large'] as $sizeName) {
                $dimensions = self::SIZES[$sizeName];
                $includeJpg = $sizeName !== 'large'; // Large JPG already exists on disk
                $sizeFiles = $this->processSizeVariant($sourceImage, $srcWidth, $srcHeight, $baseDir, $sizeName, $dimensions, $baseName, $includeJpg);
                $generatedFiles = \array_merge($generatedFiles, $sizeFiles);
            }

            return [
                'success' => true,
                'files' => $generatedFiles,
                'source' => $sourcePath
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Log the initial context for a processProductImage call
     */
    private function debugLogStart(
        string $tempPath,
        string $brandPath,
        string $modelPath,
        string $imageType
    ): void {
        $this->debugLog('=== IMAGEPROCESSOR START ===');
        $this->debugLog("tempPath: {$tempPath}");
        $this->debugLog("brandPath: {$brandPath}, modelPath: {$modelPath}, imageType: {$imageType}");
        $this->debugLog("basePath: {$this->basePath}");
        $this->debugLog('tempPath exists: ' . (\file_exists($tempPath) ? 'YES' : 'NO'));
        $this->debugLog('tempPath readable: ' . (\is_readable($tempPath) ? 'YES' : 'NO'));
    }

    /**
     * Load and validate the source image, logging each stage
     */
    private function loadSourceImage(string $tempPath): ?\GdImage
    {
        $this->debugLog('Loading source image...');
        $sourceImage = $this->loadImage($tempPath);
        if (!$sourceImage) {
            $this->debugLog('FAILED to load source image');
            return null;
        }

        $this->debugLog('Source image loaded OK');
        return $sourceImage;
    }

    /**
     * Build the base filename from brand/model paths and optional image type.
     * Centralises the sanitize + concatenate logic used across all public methods.
     */
    private function buildBaseName(
        string $brandPath,
        string $modelPath,
        string $imageType
    ): string {
        $safeBrand = $this->sanitizeForFilename($brandPath);
        $safeModel = $this->sanitizeForFilename($modelPath);
        $baseName = $safeBrand . '-' . $safeModel;
        $hasImageType = $imageType !== '';
        if ($hasImageType) $baseName .= '-' . $imageType;

        return $baseName;
    }

    /**
     * Create size/webp/avif directories and process all scale variants for one size.
     * Returns array of generated file paths for this size.
     */
    private function processSizeVariant(
        \GdImage $source,
        int $srcWidth,
        int $srcHeight,
        string $baseDir,
        string $sizeName,
        array $dimensions,
        string $baseName,
        bool $includeJpg = true
    ): array {
        $sizeDir = $baseDir . '/' . $sizeName;
        $webpDir = $sizeDir . '/webp';
        $avifDir = $sizeDir . '/avif';

        $this->debugLog("--- Processing size: {$sizeName} ---");
        $this->debugLog("Creating dir: {$sizeDir}");
        $this->ensureDirectory($sizeDir);
        $this->debugLog("Creating dir: {$webpDir}");
        $this->ensureDirectory($webpDir);
        $this->debugLog("Creating dir: {$avifDir}");
        $this->ensureDirectory($avifDir);

        $generatedFiles = [];

        foreach ([1, 2] as $scale) {
            $suffix = $scale === 2 ? '@2x' : '';
            $targetWidth = $dimensions['width'] * $scale;
            $targetHeight = $dimensions['height'] * $scale; // Generate standard and @2x for each density

            $resized = $this->resizeAndCrop(
                source: $source,
                srcWidth: $srcWidth,
                srcHeight: $srcHeight,
                targetWidth: $targetWidth,
                targetHeight: $targetHeight,
            ); // Resize image maintaining aspect ratio and center-cropping to square

            $savedPaths = $this->saveImageFormats($resized, $sizeDir, $baseName, $suffix, $includeJpg);
            $generatedFiles = \array_merge($generatedFiles, $savedPaths);
        }

        return $generatedFiles;
    }

    /**
     * Save JPG (optional), WebP, and AVIF variants for one scale of one size.
     * Returns array of successfully saved file paths.
     */
    private function saveImageFormats(
        \GdImage $resized,
        string $sizeDir,
        string $baseName,
        string $suffix,
        bool $includeJpg = true
    ): array {
        $webpDir = $sizeDir . '/webp';
        $avifDir = $sizeDir . '/avif';
        $saved = [];

        if ($includeJpg) {
            $jpgPath = $sizeDir . '/' . $baseName . $suffix . '.jpg';
            $this->debugLog("Saving JPG: {$jpgPath}");
            $jpgResult = \imagejpeg($resized, $jpgPath, self::QUALITY['jpg']);
            $this->debugLog("JPG save result: " . ($jpgResult ? 'OK' : 'FAILED'));
            if ($jpgResult) {
                $saved[] = $jpgPath;
            } else {
                $this->debugLog("JPG save error - dir writable: " . (\is_writable($sizeDir) ? 'YES' : 'NO')); // Save JPG in size folder
            }
        }

        $webpPath = $webpDir . '/' . $baseName . $suffix . '.webp';
        $this->debugLog("Saving WebP: {$webpPath}");
        $webpResult = \imagewebp($resized, $webpPath, self::QUALITY['webp']);
        $this->debugLog("WebP save result: " . ($webpResult ? 'OK' : 'FAILED'));
        if ($webpResult) $saved[] = $webpPath; // Save WebP in webp subfolder

        $avifSupported = \function_exists('imageavif');
        if ($avifSupported) {
            $avifPath = $avifDir . '/' . $baseName . $suffix . '.avif';
            $this->debugLog("Saving AVIF: {$avifPath}");
            $avifResult = \imageavif($resized, $avifPath, self::QUALITY['avif']);
            $this->debugLog("AVIF save result: " . ($avifResult ? 'OK' : 'FAILED'));
            if ($avifResult) $saved[] = $avifPath; // Save AVIF in avif subfolder (PHP 8.1+)
        } else {
            $this->debugLog("AVIF not available (no imageavif function)");
        }

        return $saved;
    }

    /**
     * Load an image from file
     */
    private function loadImage(string $path): ?\GdImage
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        $image = match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false
        };

        return $image ?: null;
    }

    /**
     * Resize and center-crop image to target dimensions
     */
    private function resizeAndCrop(
        \GdImage $source,
        int $srcWidth,
        int $srcHeight,
        int $targetWidth,
        int $targetHeight
    ): \GdImage {
        $srcRatio = $srcWidth / $srcHeight;
        $targetRatio = $targetWidth / $targetHeight; // Calculate crop dimensions to center the image

        $srcWider = $srcRatio > $targetRatio;
        if ($srcWider) {
            $cropHeight = $srcHeight; // Source is wider - crop sides
            $cropWidth = (int)($srcHeight * $targetRatio);
            $cropX = (int)(($srcWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $srcWidth;
            $cropHeight = (int)($srcWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int)(($srcHeight - $cropHeight) / 2); // Source is taller - crop top/bottom
        }

        $dest = imagecreatetruecolor($targetWidth, $targetHeight); // Create destination image with white background

        $white = imagecolorallocate($dest, 255, 255, 255);
        imagefill($dest, 0, 0, $white); // Fill with white background (for transparency handling)

        imagecopyresampled(
            $dest,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        ); // Copy and resize

        return $dest;
    }

    /**
     * Ensure directory exists with proper permissions
     */
    private function ensureDirectory(string $path): void
    {
        $dirExists = \is_dir($path);
        if ($dirExists) return;
        $dirCreated = \mkdir($path, 0755, true);
        if ($dirCreated) return;

        throw new \Exception('Failed to create directory: ' . $path);
    }

    /**
     * Try to delete a file and track result
     */
    private function tryDeleteFile(string $path, array &$deleted, array &$errors): void
    {
        $fileNotFound = !\file_exists($path);
        if ($fileNotFound) return;
        $fileDeleted = \unlink($path);
        if ($fileDeleted) {
            $deleted[] = $path;
            return;
        }

        $errors[] = 'Failed to delete: ' . $path;
    }

    /**
     * Normalize a path for consistent directory matching
     * - Decodes HTML entities so &amp; becomes & regardless of input source
     * - Replaces forward slashes with dashes to prevent directory splitting
     */
    /**
     * Brand and model paths come from the client, so only the shape the shop itself generates is accepted
     * (lower-case letters, digits and single hyphens). That rules out '..', separators and anything else
     * that could step outside the product image folder.
     *
     * @throws \InvalidArgumentException when the path is not of that shape
     */
    private function normalizePath(string $path): string
    {
        $decoded = \html_entity_decode(string: $path, flags: ENT_QUOTES | ENT_HTML5);

        $unsafe = \preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $decoded) !== 1;
        if ($unsafe) throw new \InvalidArgumentException('Invalid brand or model path');

        return $decoded;
    }

    /**
     * Sanitize a path segment for use in filenames
     * Removes/replaces characters that are problematic in filenames
     */
    private function sanitizeForFilename(string $path): string
    {
        $decoded = \html_entity_decode(string: $path, flags: ENT_QUOTES | ENT_HTML5); // Decode HTML entities first (e.g., &amp; -> &)

        $noSlashes = \str_replace(search: ['/', '\\'], replace: '-', subject: $decoded); // Replace forward/back slashes with dashes

        $safe = \preg_replace(
            pattern: '/[&<>:"\'|?*]+/',
            replacement: '-',
            subject: $noSlashes
        ); // Replace other problematic characters with dashes

        $collapsed = \preg_replace(pattern: '/-+/', replacement: '-', subject: $safe); // Collapse multiple dashes into one

        return \trim(string: $collapsed, characters: '-'); // Trim dashes from ends
    }

    /**
     * Debug logging - writes to app/logs/image-upload.log
     */
    private function debugLog(string $message): void
    {
        $logDir = \dirname(path: __DIR__, levels: 3) . '/logs';
        $logDirMissing = !\is_dir($logDir);
        if ($logDirMissing) @\mkdir(directory: $logDir, permissions: 0755, recursive: true);

        $logFile = $logDir . '/image-upload.log';
        $timestamp = \date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] [ImageProcessor] {$message}\n";

        @\file_put_contents(filename: $logFile, data: $entry, flags: FILE_APPEND);
    }
}
