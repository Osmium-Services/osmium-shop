<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Osmium;

use const Osmium\Config\PAGES;

/**
 * Lookups the admin product add and edit screens need: dropdown lists,
 * category paths, linked pages, PDF and image state for one product.
 */
class ProductFormData
{
    public function __construct(private Osmium $osmium, private OsmiumAdmin $admin)
    {
    }


    public function fetchBrands(): array
    {
        return $this->admin->model->brand->getActive();
    }

    public function fetchStatuses(): array
    {
        return $this->admin->model->status->getAll();
    }

    public function fetchCategoriesWithPaths(): array
    {
        $allCategoriesRaw = $this->admin->model->productCategory->getSimpleList();
        $categoryLookup = \array_column(array: $allCategoriesRaw, column_key: null, index_key: 'id');

        $allCategories = [];
        foreach ($allCategoriesRaw as $cat) {
            $allCategories[] = $this->buildCategoryPath(cat: $cat, categoryLookup: $categoryLookup);
        }
        \usort($allCategories, fn($a, $b) => \strcmp(string1: $a['path'], string2: $b['path']));
        return $allCategories;
    }

    private function buildCategoryPath(array $cat, array $categoryLookup): array
    {
        $path = [];
        $current = $cat;
        while ($current) {
            \array_unshift($path, $current['label']);
            $current = $current['parent_id'] !== null ? ($categoryLookup[$current['parent_id']] ?? null) : null;
        }
        return ['id' => $cat['id'], 'label' => $cat['label'], 'path' => \implode(separator: ' › ', array: $path)];
    }

    public function fetchParentPages(): array
    {
        $productPageIds = $this->admin->model->product->getProductPageIds();
        $qrPageIds = $this->admin->model->product->getQrPageIds();
        $allPages = $this->admin->model->page->getActive();

        $parentPages = [];
        foreach ($allPages as $page) {
            $pageId = (int)$page['id'];
            $url = $page['url'] ?? '';
            $isProductPage = \in_array(needle: $pageId, haystack: $productPageIds);
            if ($isProductPage) continue;
            $isQrPage = \in_array(needle: $pageId, haystack: $qrPageIds);
            if ($isQrPage) continue;
            $parentPages[] = ['id' => $pageId, 'url' => $url, 'title' => $page['title'] ?? ''];
        }
        \usort($parentPages, fn($a, $b) => \strcmp(string1: $a['url'], string2: $b['url']));
        return $parentPages;
    }

    public function fetchAllProducts(): array
    {
        $products = $this->admin->model->product->getAllWithBrand();
        foreach ($products as &$product) {
            $product['thumbnail'] = $this->buildProductThumbnail(
                brandName: $product['brand_name'] ?? '',
                modelName: $product['model_name'] ?? '',
            );
        }
        return $products;
    }

    private function buildProductThumbnail(string $brandName, string $modelName): string
    {
        $brandPath = ShopPaths::brand($brandName);
        $modelPath = ShopPaths::model($modelName);
        $basePath = '/shop/' . $brandPath . '/' . $modelPath . '/';
        $fileName = $brandPath . '-' . $modelPath;

        $thumbnailPath = $this->osmium->imagePath($basePath . 'thumb/' . $fileName);
        $thumbnailError = \str_contains(haystack: $thumbnailPath, needle: 'data-error');
        if ($thumbnailError) {
            $thumbnailPath = $this->osmium->imagePath($basePath . 'medium/' . $fileName);
        }
        $stillMissing = \str_contains(haystack: $thumbnailPath, needle: 'data-error');
        return $stillMissing ? '' : $thumbnailPath;
    }

    public function fetchProductCategories(int $productId): array
    {
        return $this->admin->model->productCategory->getByProduct($productId);
    }

    public function fetchProductPages(int $productId): array
    {
        $productPagesRaw = $this->admin->model->product->getPages($productId);

        $pagesById = [];
        foreach (PAGES as $slug => $page) {
            $pageHasId = isset($page['id']);
            if ($pageHasId) $pagesById[$page['id']] = \array_merge($page, ['slug' => $slug]);
        }

        $productPages = [];
        foreach ($productPagesRaw as $pp) {
            $pageId = (int)$pp['page_id'];
            $pageIdMissing = !isset($pagesById[$pageId]);
            if ($pageIdMissing) continue;
            $productPages[] = [
                'page_id' => $pageId,
                'is_qr' => $pp['is_qr'],
                'title' => $pagesById[$pageId]['title'] ?? '',
                'url' => $pagesById[$pageId]['url'] ?? '',
                'description' => $pagesById[$pageId]['description'] ?? '',
                'robots' => $pagesById[$pageId]['robots'] ?? 'index,follow',
                'parent_page_id' => $pagesById[$pageId]['parent_page_id'] ?? null,
                'slug' => $pagesById[$pageId]['slug'] ?? '',
            ];
        }

        \usort(array: $productPages, callback: function ($a, $b) {
            $qrDiffers = $a['is_qr'] != $b['is_qr'];
            if ($qrDiffers) return $a['is_qr'] - $b['is_qr'];
            return \strcasecmp(string1: $a['title'], string2: $b['title']);
        });

        return $productPages;
    }

    public function findPrimaryProductPage(array $productPages): ?array
    {
        foreach ($productPages as $pp) {
            $isPrimaryPage = $pp['is_qr'] == 0;
            if ($isPrimaryPage) return $pp;
        }
        return null;
    }

    public function fetchProductsWithTechspec(): array
    {
        return $this->admin->model->product->getWithTechspec();
    }

    public function fetchProductsWithFaq(): array
    {
        return $this->admin->model->product->getWithFaq();
    }

    public function buildPdfPaths($product): array
    {
        $pdfPaths = [];
        foreach (AdminShop::PDF_TYPES as $type) {
            $brandPath = $product->brandPath;
            $modelPath = $product->modelPath;
            $pdfDir = 'assets/images/shop/' . $brandPath . '/' . $modelPath . '/' . $type;
            $pdfFile = $brandPath . '-' . $modelPath . '-' . $type . '.pdf';
            $fullPath = $pdfDir . '/' . $pdfFile;
            $pdfPaths[$type] = \file_exists($fullPath) ? '/' . $fullPath : null;
        }
        return $pdfPaths;
    }

    public function buildImageUrls($product): array
    {
        $cacheBust = function (string $partial): string {
            $resolved = $this->osmium->imagePath($partial);
            $isMissing = \str_contains(haystack: $resolved, needle: 'data-error');
            if ($isMissing) return '';
            return $this->admin->cacheBustedImagePath(imagePath: $resolved);
        };
        return [
            'main' => $cacheBust($product->imagePath('large')),
            'extra1' => $cacheBust($product->imagePath(imageSize: 'extra', imageType: 'extra1')),
            'extra2' => $cacheBust($product->imagePath(imageSize: 'extra', imageType: 'extra2')),
            'extra3' => $cacheBust($product->imagePath(imageSize: 'extra', imageType: 'extra3')),
            'extra4' => $cacheBust($product->imagePath(imageSize: 'extra', imageType: 'extra4')),
            'extra5' => $cacheBust($product->imagePath(imageSize: 'extra', imageType: 'extra5')),
            'warranty' => $cacheBust($product->imagePath(imageSize: 'extra', imageType: 'warranty')),
        ];
    }

    /**
     * Which of the fixed gallery slots actually have an uploaded image, so
     * the option-value "linked image" picker only offers real choices.
     */
    public function buildAvailableImageSlots($product): array
    {
        $slots = ['main' => '', 'extra1' => 'extra1', 'extra2' => 'extra2', 'extra3' => 'extra3',
            'extra4' => 'extra4', 'extra5' => 'extra5', 'warranty' => 'warranty'];

        $available = [];
        foreach ($slots as $slot => $suffix) {
            $thumbPath = $this->osmium->imagePath($product->imagePath('thumb', $suffix));
            $available[$slot] = $this->osmium->fileSystem->fileExists(\ltrim($thumbPath, '/\\'));
        }

        return $available;
    }

    public function checkImageDerivations($product): array
    {
        $brandPath = $product->brandPath;
        $modelPath = $product->modelPath;
        $baseImagePath = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/shop/' . $brandPath . '/' . $modelPath;
        $baseFilename = $brandPath . '-' . $modelPath;

        $findImage = function (string $dir) use ($baseFilename): bool {
            foreach (['.jpg', '.png', '.webp'] as $ext) {
                $imageExists = \file_exists(filename: $dir . '/' . $baseFilename . $ext);
                if ($imageExists) return true;
            }
            return false;
        };

        return [
            'large' => $findImage($baseImagePath . '/large'),
            'medium' => $findImage($baseImagePath . '/medium'),
            'thumb' => $findImage($baseImagePath . '/thumb'),
            'webp' => \file_exists(filename: $baseImagePath . '/large/webp/' . $baseFilename . '.webp'),
            'avif' => \file_exists(filename: $baseImagePath . '/large/avif/' . $baseFilename . '.avif'),
        ];
    }

    public function shouldShowRegenerateButton(array $imageDerivations): bool
    {
        $hasLarge = $imageDerivations['large'];
        $missingSizes = !$imageDerivations['medium'] || !$imageDerivations['thumb'];
        $missingWebp = !$imageDerivations['webp'];
        $missingAvif = !$imageDerivations['avif'] && \function_exists('imageavif');
        return $hasLarge && ($missingSizes || $missingWebp || $missingAvif);
    }

    public function findById(array $items, int $targetId, string $idKey = 'id'): ?array
    {
        foreach ($items as $item) {
            $isMatch = $item[$idKey] == $targetId;
            if ($isMatch) return $item;
        }
        return null;
    }
}
