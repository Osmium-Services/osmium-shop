<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\Brand;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminShopOptions;

use const Osmium\Config\PAGES;

require_once 'app/core/admin/models/AdminModel.php';
require_once 'app/core/admin/models/Page.php';
require_once __DIR__ . '/Product.php';
require_once 'app/core/admin/services/AdminPages.php';

/**
 * AdminShop - Product and shop management helpers for the admin module
 */
class AdminShop
{
    public const PDF_TYPES = ['brochure', 'datasheet', 'instructions'];
    public const PRODUCT_DELETE_GRACE_PERIOD = 3600; // 1 hour in seconds: a product this new can be deleted for good
    private const MAX_UPLOAD_SIZE = 10 * 1024 * 1024; // 10MB

    private string $basePath;

    public function __construct(
        private Product $product,
        private Brand $brand,
        private ProductOptionType $optionType,
        private ProductOptionValue $optionValue,
        private bool $urlTrailingSlash = false,
    ) {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? ''; // Get document root with fallback
        $docRootEmpty = empty($docRoot);
        if ($docRootEmpty) {
            $docRoot = \dirname(path: __DIR__, levels: 4); // Fallback: derive from current file location
        }
        $this->basePath = \rtrim(string: $docRoot, characters: '/');
    }

    /**
     * Normalize a path for consistent directory matching
     * Decodes HTML entities and replaces forward slashes
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
     * Upload a PDF file for a product
     * @return array ['pdfType' => string, 'pdfPath' => string]
     * @throws \Exception on validation or save failure
     */
    public function uploadPdf(
        array $file,
        string $pdfType,
        string $brandPath,
        string $modelPath
    ): array {
        $uploadFailed = !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK;
        if ($uploadFailed) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
            ];
            throw new \Exception($errorMessages[$errorCode] ?? 'Unknown upload error'); // Validate upload
        }

        $validTypes = self::PDF_TYPES;
        $invalidPdfType = !\in_array(needle: $pdfType, haystack: $validTypes);
        if ($invalidPdfType) throw new \Exception('Invalid PDF type'); // Validate PDF type

        $missingPaths = empty($brandPath) || empty($modelPath);
        if ($missingPaths) throw new \Exception('Brand and model paths are required'); // Validate paths

        $finfo = \finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = \finfo_file($finfo, $file['tmp_name']); // Validate MIME type (the finfo handle is freed automatically)

        $isNotPdf = $mimeType !== 'application/pdf';
        if ($isNotPdf) throw new \Exception('Invalid file type. Only PDF files allowed');

        $tooLarge = $file['size'] > self::MAX_UPLOAD_SIZE;
        if ($tooLarge) throw new \Exception('File too large. Maximum 10MB allowed'); // Validate file size

        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $relativeDir = '/assets/images/shop/' . $brandPath . '/' . $modelPath . '/' . $pdfType;
        $pdfDir = $this->basePath . $relativeDir;
        $pdfFilename = $brandPath . '-' . $modelPath . '-' . $pdfType . '.pdf';
        $fullPath = $pdfDir . '/' . $pdfFilename; // Build paths with absolute base path

        $pdfDirMissing = !\is_dir($pdfDir);
        if ($pdfDirMissing) {
            $created = \mkdir(directory: $pdfDir, permissions: 0755, recursive: true);
            if (!$created) throw new \Exception('Failed to create directory'); // Create directory if needed
        }

        $moved = \move_uploaded_file(from: $file['tmp_name'], to: $fullPath);
        if (!$moved) throw new \Exception('Failed to save file'); // Move uploaded file

        return [
            'pdfType' => $pdfType,
            'pdfPath' => $relativeDir . '/' . $pdfFilename
        ];
    }

    /**
     * Delete a PDF file for a product
     * @throws \Exception on failure
     */
    public function deletePdf(string $pdfType, string $brandPath, string $modelPath): void
    {
        $validTypes = self::PDF_TYPES;
        $invalidPdfType = !\in_array(needle: $pdfType, haystack: $validTypes);
        if ($invalidPdfType) throw new \Exception('Invalid PDF type');

        $missingPaths = empty($brandPath) || empty($modelPath);
        if ($missingPaths) throw new \Exception('Brand and model paths are required');

        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $pdfDir = $this->basePath . '/assets/images/shop/' . $brandPath . '/' . $modelPath . '/' . $pdfType;
        $pdfFilename = $brandPath . '-' . $modelPath . '-' . $pdfType . '.pdf';
        $fullPath = $pdfDir . '/' . $pdfFilename;

        $fileExists = \file_exists($fullPath);
        if ($fileExists) {
            $unlinkFailed = !\unlink($fullPath);
            if ($unlinkFailed) throw new \Exception('Failed to delete file');
            @\rmdir($pdfDir);
        }
    }

    /**
     * Get existing PDF paths for a product
     * @return array ['brochure' => string|null, 'datasheet' => string|null, 'instructions' => string|null]
     */
    public function getPdfPaths(string $brandPath, string $modelPath): array
    {
        $brandPath = $this->normalizePath($brandPath);
        $modelPath = $this->normalizePath($modelPath); // Normalize paths for consistent directory matching

        $pdfPaths = [];
        foreach (self::PDF_TYPES as $type) {
            $relativeDir = '/assets/images/shop/' . $brandPath . '/' . $modelPath . '/' . $type;
            $pdfFile = $brandPath . '-' . $modelPath . '-' . $type . '.pdf';
            $fullPath = $this->basePath . $relativeDir . '/' . $pdfFile;
            $pdfPaths[$type] = \file_exists($fullPath) ? $relativeDir . '/' . $pdfFile : null;
        }
        return $pdfPaths;
    }

    /**
     * Soft delete a product
     */
    public function softDelete(int $productId): void
    {
        $this->product->softDelete($productId);
    }

    /**
     * Restore a soft-deleted product
     */
    public function restore(int $productId): void
    {
        $this->product->restore($productId);
    }

    /**
     * Discontinue a product
     */
    public function discontinue(int $productId): void
    {
        $this->product->discontinue($productId);
    }

    /**
     * Duplicate a product with all its data
     * @return int New product ID
     * @throws \Exception on failure
     */
    public function duplicate(int $productId): int
    {
        $original = $this->duplicateGetOriginal($productId);
        $brandName = $this->duplicateGetBrandName($original['brand_id']); // Get original product

        $newModelName = $original['model_name'] . ' (Copy)';
        $urlStub = $this->duplicateGenerateUrlStub($brandName, $newModelName); // Generate new model name and URL stub

        $parentPageId = $this->duplicateGetParentPageId($productId); // Get parent page ID from original product's category (for page hierarchy)

        $newProductId = $this->duplicateInsertProduct($original, $newModelName); // Insert duplicate product

        $newPageId = $this->duplicateInsertPageLink($newProductId); // Link product to page WITH RETRY (do this BEFORE Routes.php to fail early)

        $this->duplicateCopyCategories(newProductId: $newProductId, oldProductId: $productId); // Copy categories from original product

        $this->duplicateCopyOptions(sourceProductId: $productId, targetProductId: $newProductId); // Copy product options from original product

        $this->duplicateAddRoute(
            urlStub: $urlStub,
            pageId: $newPageId,
            parentPageId: $parentPageId,
            brandName: $brandName,
            modelName: $newModelName,
        ); // Finally, add route to Routes.php (safe - DB operations succeeded)

        return $newProductId;
    }

    private function duplicateGetOriginal(int $productId): array
    {
        $original = $this->product->getAll($productId);

        if (!$original) throw new \Exception('Product not found');

        return $original;
    }

    private function duplicateGetBrandName(int $brandId): string
    {
        $name = $this->brand->getName($brandId);

        return $name !== '' ? $name : 'product';
    }

    private function duplicateGenerateUrlStub(string $brandName, string $modelName): string
    {
        $combinedName = $brandName . '-' . $modelName;
        $replaced = \str_replace(
            search: [' ', '.', '(', ')'],
            replace: ['-', '-', '', ''],
            subject: $combinedName,
        );
        $baseUrlStub = \strtolower($replaced);
        $baseUrlStub = \preg_replace(pattern: '/-+/', replacement: '-', subject: $baseUrlStub);
        $baseUrlStub = \trim(string: $baseUrlStub, characters: '-');

        $urlStub = $baseUrlStub;
        $counter = 1;
        while (isset(PAGES[$urlStub]) && $counter < 100) {
            $counter++;
            $urlStub = $baseUrlStub . '-' . $counter; // Find unique URL stub (check Routes.php)
        }

        return $urlStub;
    }

    private function duplicateGetParentPageId(int $productId): ?string
    {
        $result = $this->product->getCategoryPageId($productId); // Get the first category's page_id from the original product

        return $result ? (string)$result['page_id'] : null;
    }

    private function duplicateInsertProduct(array $original, string $newModelName): int
    {
        return $this->product->duplicateInsert($original, $newModelName);
    }

    private function duplicateInsertPageLink(int $productId): int
    {
        $maxRetries = 5;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $newPageId = $this->duplicateCalculateNextPageId(); // Calculate next page_id from both Routes.php and database

            try {
                $this->product->insertPageLink($productId, $newPageId);

                return $newPageId;
            } catch (\PDOException $e) {
                $isDuplicateKey = $e->getCode() === '23000';
                $isLastAttempt = $attempt === $maxRetries; // Check if it's a duplicate key error (SQLSTATE 23000)

                $shouldRethrow = !$isDuplicateKey || $isLastAttempt;
                if ($shouldRethrow) throw $e;

                \usleep(10000 * $attempt); // Duplicate key - retry with a new page_id, brief delay increases with each attempt
            }
        }

        throw new \Exception('Failed to generate unique page ID after multiple attempts');
    }

    private function duplicateCalculateNextPageId(): int
    {
        $maxPageIdRoutes = 0;
        foreach (PAGES as $page) {
            $pageId = (int)$page['id'];
            $belowThreshold = $pageId < OsmiumAdmin::ADMIN_PAGE_ID_THRESHOLD;
            $isNewMax = $pageId > $maxPageIdRoutes && $belowThreshold;
            if ($isNewMax) $maxPageIdRoutes = $pageId; // Check max page_id in Routes.php (PAGES constant)
        }

        $maxPageIdDb = $this->product->getMaxPageId(); // Check max page_id in database

        return \max($maxPageIdRoutes, $maxPageIdDb) + 1;
    }

    private function duplicateCopyCategories(int $newProductId, int $oldProductId): void
    {
        $this->product->copyCategories($newProductId, $oldProductId);
    }

    private function duplicateCopyOptions(int $sourceProductId, int $targetProductId): void
    {
        require_once __DIR__ . '/AdminShopOptions.php';
        $shopOptions = new AdminShopOptions(
            optionType: $this->optionType,
            optionValue: $this->optionValue,
        );
        $shopOptions->copyOptionsFromProduct(
            sourceProductId: $sourceProductId,
            targetProductId: $targetProductId,
        );
    }

    private function duplicateAddRoute(
        string $urlStub,
        int $pageId,
        ?string $parentPageId,
        string $brandName,
        string $modelName,
    ): void {
        $pagesService = $this->pagesService();

        $pageTitle = $brandName . ' ' . $modelName;

        $pagesService->createWithId(
            id: $pageId,
            input: [
                'url' => $urlStub,
                'title' => $pageTitle,
                'description' => '',
                'robots' => 'index,follow',
                'parent_page_id' => $parentPageId,
            ],
        ); // Insert page into database with specific ID and regenerate routes.php
    }

    protected function pagesService(): AdminPages
    {
        return new AdminPages(page: new Page($this->product->database, 'pages'), urlTrailingSlash: $this->urlTrailingSlash);
    }

    /**
     * Permanently delete a product (only within grace period)
     * @throws \Exception on failure or if outside grace period
     */
    public function deleteCompletely(
        int $productId,
        callable $generateBrandPath,
        callable $generateModelPath
    ): void {
        $product = $this->product->getWithBrand($productId); // Get product with brand info

        if (!$product) throw new \Exception('Product not found');

        $createdAt = \strtotime($product['created_at']);
        $oneHourAgo = \time() - self::PRODUCT_DELETE_GRACE_PERIOD;
        $outsideGracePeriod = $createdAt <= $oneHourAgo;
        if ($outsideGracePeriod) throw new \Exception('Can only undo products created within the last hour'); // Check grace period

        $pageRecord = $this->product->getPageId($productId);
        $pageId = $pageRecord ? (int)$pageRecord['page_id'] : null; // Get page ID

        $brandPath = $generateBrandPath($product['brand_name']);
        $modelPath = $generateModelPath($product['model_name']);
        $imageFolderPath = $this->imageFolderToDelete($productId, $brandPath, $modelPath, $generateBrandPath, $generateModelPath); // Null when the folder cannot be named safely or another product uses it

        $this->product->deleteCategories($productId); // Delete product categories

        $this->product->deletePages($productId); // Delete product pages link (must be done before page deletion)

        $this->product->hardDelete($productId); // Hard delete product

        if ($pageId) {
            $pagesService = $this->pagesService();
            $pagesService->permanentDelete($pageId); // Delete page from database and regenerate routes.php
        }

        if ($imageFolderPath !== null) $this->deleteImageFolder($imageFolderPath); // Delete image folder
    }

    /**
     * The folder holding this product's images, or null when deleting it would be unsafe.
     *
     * The brand and model slugs are lossy: a name with no letters or digits gives an empty slug (which would point at
     * the whole brand folder, or the whole shop folder), and different names can give the same slug. So an empty slug,
     * a folder that does not sit strictly inside the shop images folder, or a folder another product also maps to, is
     * left alone.
     */
    private function imageFolderToDelete(int $productId, string $brandPath, string $modelPath, callable $generateBrandPath, callable $generateModelPath): ?string
    {
        $slugEmpty = $brandPath === '' || $modelPath === '';
        if ($slugEmpty) return null;

        $shopRoot = \realpath('assets/images/shop');
        $folder = \realpath('assets/images/shop/' . $brandPath . '/' . $modelPath);
        $missing = $shopRoot === false || $folder === false;
        if ($missing) return null;

        $outsideShop = !\str_starts_with($folder, $shopRoot . DIRECTORY_SEPARATOR . $brandPath . DIRECTORY_SEPARATOR);
        if ($outsideShop) return null;

        foreach ($this->product->getBrandModelExcept($productId) as $other) {
            $sharesFolder = $generateBrandPath((string)$other['brand_name']) === $brandPath
                && $generateModelPath((string)$other['model_name']) === $modelPath;
            if ($sharesFolder) return null;
        }

        return $folder;
    }

    /**
     * Recursively delete an image folder and its contents
     */
    private function deleteImageFolder(string $folderPath): void
    {
        $dirIterator = new \RecursiveDirectoryIterator(
            directory: $folderPath,
            flags: \RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $files = new \RecursiveIteratorIterator(
            iterator: $dirIterator,
            mode: \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $path = $file->getRealPath();
            $file->isDir() ? \rmdir($path) : \unlink($path);
        }
        \rmdir($folderPath);
    }
}
