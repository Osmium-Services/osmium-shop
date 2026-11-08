<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\UploadErrors;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\ImageProcessor;

/**
 * AdminShopImages - Product image management
 */
class AdminShopImages
{
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const DESCR_IMAGE_FIELDS = ['descr_image', 'descr2_image', 'descr3_image'];

    public function __construct(
        private Product $product,
    ) {}

    /**
     * Upload a product image
     * @return array ['slot' => string, 'imagePath' => string]
     */
    public function upload(int $productId, array $file, array $input): array
    {
        ImageUploadLog::write('=== IMAGE UPLOAD START ===');
        ImageUploadLog::write('Product ID: ' . $productId);
        ImageUploadLog::write('Input: ' . \json_encode($input));
        ImageUploadLog::write('File info: ' . \json_encode([
            'name' => $file['name'] ?? 'N/A',
            'type' => $file['type'] ?? 'N/A',
            'size' => $file['size'] ?? 'N/A',
            'tmp_name' => $file['tmp_name'] ?? 'N/A',
            'error' => $file['error'] ?? 'N/A',
        ]));

        if (!$productId) throw new \Exception('Product ID is required');

        $this->validateUploadError($file);

        $slot = $input['slot'] ?? 'main';
        $brandPath = $input['brand_path'] ?? '';
        $modelPath = $input['model_path'] ?? '';

        $missingPaths = empty($brandPath) || empty($modelPath);
        if ($missingPaths) throw new \Exception('Brand and model paths are required');

        $this->validateMimeType($file);
        $this->validateFileSize($file);
        $this->validateDimensions($file);

        $imageType = $this->getImageTypeFromSlot($slot);
        $result = $this->processImage($file, $brandPath, $modelPath, $imageType);

        $this->updateDescrField($productId, $slot, $result['imagePath']);

        $response = ['slot' => $slot, 'imagePath' => $result['imagePath']];

        $isMainSlot = $slot === 'main';
        if ($isMainSlot) {
            $response['derivations'] = ProductImageFiles::derivations(brandPath: $brandPath, modelPath: $modelPath); // For main image uploads, include derivation status for badge refresh
        }

        return $response;
    }

    /**
     * Validate PHP upload error code, throw on any failure
     */
    private function validateUploadError(array $file): void
    {
        $uploadFailed = !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK;
        if ($uploadFailed) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $message = UploadErrors::message((int) $errorCode);
            ImageUploadLog::write('Upload error: ' . $errorCode . ' - ' . $message);
            throw new \Exception($message); // Validate upload
        }

        ImageUploadLog::write('Upload validation passed');
    }

    /**
     * Validate MIME type against allowed image types, throw on invalid
     */
    private function validateMimeType(array $file): void
    {
        ImageUploadLog::write('Checking MIME type...');
        $finfo = \finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = \finfo_file($finfo, $file['tmp_name']);
        //\finfo_close($finfo); deprecated
        ImageUploadLog::write('MIME type: ' . $mimeType); // Validate file type

        $allowedTypes    = self::ALLOWED_IMAGE_TYPES;
        $invalidMimeType = !\in_array(needle: $mimeType, haystack: $allowedTypes);
        if ($invalidMimeType) {
            ImageUploadLog::write('MIME type rejected');
            throw new \Exception('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
        }
    }

    /**
     * Validate file size against the admin upload cap, throw if too large
     */
    private function validateFileSize(array $file): void
    {
        ImageUploadLog::write('Checking file size: ' . $file['size'] . ' bytes');
        $fileTooLarge = $file['size'] > OsmiumAdmin::MAX_UPLOAD_SIZE;
        if ($fileTooLarge) {
            ImageUploadLog::write('File too large');
            throw new \Exception('File too large. Maximum 10MB allowed'); // Validate file size
        }
    }

    /**
     * Validate image dimensions — must be exactly 900×900, throw otherwise
     */
    private function validateDimensions(array $file): void
    {
        ImageUploadLog::write('Checking dimensions...');
        $dimensions = \getimagesize($file['tmp_name']);
        ImageUploadLog::write('Dimensions: ' . \json_encode($dimensions));

        $dimensionsFailed = $dimensions === false;
        if ($dimensionsFailed) {
            ImageUploadLog::write('Could not read dimensions');
            throw new \Exception('Could not read image dimensions'); // Validate image dimensions
        }

        $wrongDimensions = $dimensions[0] !== 900 || $dimensions[1] !== 900;
        if ($wrongDimensions) {
            $width  = $dimensions[0];
            $height = $dimensions[1];
            ImageUploadLog::write("Wrong dimensions: {$width}×{$height}");
            throw new \Exception("Image must be 900×900 pixels. Uploaded: {$width}×{$height}.");
        }
    }

    /**
     * Run ImageProcessor to save all image variants, throw on failure
     * @return array ['success' => bool, 'imagePath' => string, ...]
     */
    private function processImage(
        array $file,
        string $brandPath,
        string $modelPath,
        string $imageType,
    ): array {
        ImageUploadLog::write("Processing image - imageType: {$imageType}");
        ImageUploadLog::write('DOCUMENT_ROOT: ' . $_SERVER['DOCUMENT_ROOT']); // Determine image type based on slot

        require_once __DIR__ . '/ImageProcessor.php';
        $processor = new ImageProcessor($_SERVER['DOCUMENT_ROOT']);
        ImageUploadLog::write('Calling ImageProcessor::processProductImage()');
        $result = $processor->processProductImage(
            tempPath: $file['tmp_name'],
            brandPath: $brandPath,
            modelPath: $modelPath,
            imageType: $imageType,
        ); // Process the image

        ImageUploadLog::write('ImageProcessor result: ' . \json_encode($result));

        $failed = !$result['success'];
        if ($failed) {
            ImageUploadLog::write('Image processing FAILED');
            throw new \Exception($result['error'] ?? 'Image processing failed');
        }

        ImageUploadLog::write('Image processing SUCCESS');

        return $result;
    }

    /**
     * Update descr_image slot in the database if the slot is a descr field
     */
    private function updateDescrField(int $productId, string $slot, string $imagePath): void
    {
        $descrFields  = self::DESCR_IMAGE_FIELDS;
        $isDescrField = \in_array(needle: $slot, haystack: $descrFields);
        if (!$isDescrField) return;

        $this->product->updateDescrImageField($productId, $slot, $imagePath);
    }

    /**
     * Delete a product image
     * @return array ['slot' => string, 'deleted' => int]
     */
    public function delete(int $productId, array $input): array
    {
        if (!$productId) throw new \Exception('Product ID is required');

        $slot = $input['slot'] ?? '';
        $brandPath = $input['brand_path'] ?? '';
        $modelPath = $input['model_path'] ?? '';

        $missingFields = empty($slot) || empty($brandPath) || empty($modelPath);
        if ($missingFields) {
            throw new \Exception('Slot, brand path and model path are required');
        }

        $imageType = $this->getImageTypeFromSlot($slot);

        require_once __DIR__ . '/ImageProcessor.php';
        $processor = new ImageProcessor($_SERVER['DOCUMENT_ROOT']);
        $result = $processor->deleteImageVariants($brandPath, $modelPath, $imageType); // Delete image files

        $descrFields = self::DESCR_IMAGE_FIELDS;
        $isDescrField = \in_array(needle: $slot, haystack: $descrFields);
        if ($isDescrField) {
            $this->product->updateDescrImageField($productId, $slot, null);
        }

        return [
            'slot' => $slot,
            'deleted' => \count($result['deleted'])
        ];
    }

    /**
     * Update a description image path in the database
     * @return array ['field' => string, 'value' => string, 'html' => string|null]
     */
    public function updateDescrImage(int $productId, array $input): array
    {
        if (!$productId) throw new \Exception('Product ID is required');

        $field = $input['field'] ?? '';
        $imagePath = $input['value'] ?? '';

        $descrFields = self::DESCR_IMAGE_FIELDS;
        $invalidField = !\in_array(needle: $field, haystack: $descrFields);
        if ($invalidField) throw new \Exception('Invalid field');

        $htmlValue = null;
        $hasImagePath = !empty($imagePath);
        if ($hasImagePath) {
            $productInfo = $this->product->getBrandModel($productId);

            $brandName = $productInfo['brand_name'] ?? '';
            $modelName = $productInfo['model_name'] ?? '';
            $altText = \trim($brandName . ' ' . $modelName);
            $fullSrc = '/assets/images' . $imagePath;
            $escapedSrc = \htmlspecialchars($fullSrc);
            $escapedAlt = \htmlspecialchars($altText);
            $htmlValue = '<img src="' . $escapedSrc . '" alt="' . $escapedAlt . '" loading="lazy">';
        }

        $this->product->updateDescrImageField($productId, $field, $htmlValue);

        return [
            'field' => $field,
            'value' => $imagePath,
            'html' => $htmlValue,
        ];
    }

    /**
     * Get all images for a product
     */
    public function getAll(int $productId, string $brandPath, string $modelPath): array
    {
        if (!$productId) throw new \Exception('Product ID is required');

        $missingPaths = empty($brandPath) || empty($modelPath);
        if ($missingPaths) throw new \Exception('Brand and model paths are required');

        require_once __DIR__ . '/ImageProcessor.php';
        $processor = new ImageProcessor($_SERVER['DOCUMENT_ROOT']);
        $images = $processor->getProductImages($brandPath, $modelPath);

        $productData = $this->product->getDescrImages($productId);

        if ($productData) {
            $images['descr_image'] = $productData['descr_image'] ?: null;
            $images['descr2_image'] = $productData['descr2_image'] ?: null;
            $images['descr3_image'] = $productData['descr3_image'] ?: null;
        }

        return $images;
    }

    /**
     * Regenerate image variants from the large image
     */
    public function regenerate(int $productId, string $brandPath, string $modelPath): array
    {
        if (!$productId) throw new \Exception('Product ID is required');

        $missingPaths = empty($brandPath) || empty($modelPath);
        if ($missingPaths) throw new \Exception('Brand and model paths are required');

        require_once __DIR__ . '/ImageProcessor.php';
        $processor = new ImageProcessor($_SERVER['DOCUMENT_ROOT']);
        return $processor->regenerateFromLarge($brandPath, $modelPath);
    }

    /**
     * Rename product image folder and all files when model name changes.
     * This preserves images when a product's model name is edited.
     *
     * @param string $brandName The brand name (used for path calculation)
     * @param string $oldModelName The old model name
     * @param string $newModelName The new model name
     * @return array ['success' => bool, 'renamed' => int, 'error' => string|null]
     */
    public function renameProductImages(
        string $brandName,
        string $oldModelName,
        string $newModelName,
    ): array {
        $brandPath = $this->generateBrandPath($brandName);
        $oldModelPath = $this->generateModelPath($oldModelName);
        $newModelPath = $this->generateModelPath($newModelName);

        $oldBaseName = $brandPath . '-' . $oldModelPath;
        $newBaseName = $brandPath . '-' . $newModelPath; // Build old and new base filenames

        $baseDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/shop/' . $brandPath;
        $oldDir = $baseDir . '/' . $oldModelPath;
        $newDir = $baseDir . '/' . $newModelPath;

        ImageUploadLog::write("=== RENAME PRODUCT IMAGES ===");
        ImageUploadLog::write("Brand: {$brandName} -> {$brandPath}");
        ImageUploadLog::write("Old model: {$oldModelName} -> {$oldModelPath}");
        ImageUploadLog::write("New model: {$newModelName} -> {$newModelPath}");
        ImageUploadLog::write("Old dir: {$oldDir}");
        ImageUploadLog::write("New dir: {$newDir}");

        $oldDirMissing = !\is_dir($oldDir);
        if ($oldDirMissing) {
            ImageUploadLog::write("Old dir does not exist - skipping rename");
            return ['success' => true, 'renamed' => 0, 'skipped' => true]; // Skip if old folder doesn't exist (no images uploaded yet)
        }

        $newDirExists = \is_dir($newDir);
        if ($newDirExists) {
            ImageUploadLog::write("ERROR: New dir already exists");
            return [
                'success' => false,
                'renamed' => 0,
                'error' => "Target folder already exists: {$newModelPath}",
            ]; // Error if new folder already exists (would cause collision)
        }

        $folderRenamed = @\rename(from: $oldDir, to: $newDir);
        if (!$folderRenamed) {
            ImageUploadLog::write("ERROR: Failed to rename folder");
            return [
                'success' => false,
                'renamed' => 0,
                'error' => "Failed to rename folder from {$oldModelPath} to {$newModelPath}",
            ]; // Step 1: Rename the folder
        }
        ImageUploadLog::write("Folder renamed successfully");

        $renamedCount = $this->renameFilesRecursively(
            dir: $newDir,
            oldBaseName: $oldBaseName,
            newBaseName: $newBaseName,
        );
        ImageUploadLog::write("Renamed {$renamedCount} files"); // Step 2: Rename all files inside (recursively)

        return ['success' => true, 'renamed' => $renamedCount];
    }

    /**
     * Recursively rename files in a directory that match the old base name.
     */
    private function renameFilesRecursively(
        string $dir,
        string $oldBaseName,
        string $newBaseName,
    ): int {
        $renamed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                directory: $dir,
                flags: \FilesystemIterator::SKIP_DOTS,
            ),
            mode: \RecursiveIteratorIterator::SELF_FIRST,
        );

        $filesToRename = [];
        foreach ($iterator as $file) {
            $isNotFile = !$file->isFile();
            if ($isNotFile) continue; // Collect files first (avoid modifying during iteration)

            $filename = $file->getFilename();
            $startsWithOldName = \str_starts_with(haystack: $filename, needle: $oldBaseName);
            if (!$startsWithOldName) continue;

            $filesToRename[] = $file->getPathname();
        }

        foreach ($filesToRename as $oldPath) {
            $dir = \dirname($oldPath);
            $filename = \basename($oldPath);
            $newFilename = \str_replace(
                search: $oldBaseName,
                replace: $newBaseName,
                subject: $filename,
            );
            $newPath = $dir . '/' . $newFilename; // Now rename them

            ImageUploadLog::write("Renaming: {$filename} -> {$newFilename}");

            $fileRenamed = @\rename(from: $oldPath, to: $newPath);
            if ($fileRenamed) {
                $renamed++;
            } else {
                ImageUploadLog::write("WARNING: Failed to rename {$filename}");
            }
        }

        return $renamed;
    }

    private function generateBrandPath(string $brandName): string
    {
        return ShopPaths::brand($brandName);
    }

    private function generateModelPath(string $modelName): string
    {
        return ShopPaths::model($modelName);
    }

    /**
     * Helper: Convert slot name to image type
     */
    private function getImageTypeFromSlot(string $slot): string
    {
        $isMainSlot = $slot === 'main';
        if ($isMainSlot) return '';

        $matched = \preg_match(pattern: '/^extra([1-5])$/', subject: $slot, matches: $matches);
        if ($matched) return 'extra' . $matches[1];

        $isWarrantySlot = $slot === 'warranty';
        if ($isWarrantySlot) return 'warranty';

        $descrFields = self::DESCR_IMAGE_FIELDS;
        $isDescrField = \in_array(needle: $slot, haystack: $descrFields);
        if ($isDescrField) {
            return \str_replace(search: '_image', replace: '', subject: $slot);
        }

        throw new \Exception("Unknown image slot: {$slot}"); // Fail loudly — never silently fall back to the main image ('')
    }
}
