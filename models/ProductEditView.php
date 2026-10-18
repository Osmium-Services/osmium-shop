<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Prepares the display values the product edit page needs (image choices,
 * category dropdown state, PDF types, "other products" lists...) so the
 * views/products/edit*.phtml files only echo and loop. Pure given its inputs;
 * the images root is passed in so file checks stay out of the views.
 */
class ProductEditView
{
    private const IMAGE_SLOT_LABELS = [
        'main' => 'Main', 'extra1' => 'Extra 1', 'extra2' => 'Extra 2',
        'extra3' => 'Extra 3', 'extra4' => 'Extra 4', 'extra5' => 'Extra 5',
        'warranty' => 'Warranty',
    ];

    private const GALLERY_LABELS = [
        'extra1' => 'Extra 1', 'extra2' => 'Extra 2', 'extra3' => 'Extra 3',
        'extra4' => 'Extra 4', 'extra5' => 'Extra 5', 'warranty' => 'Warranty',
    ];

    private const PDF_TYPES = [
        'brochure' => ['label' => 'Brochure', 'icon' => 'bx-book-open'],
        'datasheet' => ['label' => 'Datasheet', 'icon' => 'bx-spreadsheet'],
        'instructions' => ['label' => 'Instructions', 'icon' => 'bx-file'],
    ];

    /**
     * @param array<string, mixed> $admin  the controller's $this->data['admin'] so far
     * @param string $imagesRoot           filesystem path of /assets/images (no trailing slash)
     * @return array<string, mixed>
     */
    public static function build(array $admin, string $imagesRoot): array
    {
        $product = $admin['product'];
        $model = $admin['productModel'];
        $currentId = (int) $product['id'];

        return [
            'optionImageSlots' => self::optionImageSlots($admin),
            'descriptionImages' => self::descriptionImages($product, $model, $imagesRoot),
            'gallerySlots' => self::GALLERY_LABELS,
            'categoryChoices' => self::categoryChoices($admin['allCategories'], $admin['productCategories']),
            'sidebarGallery' => self::sidebarGallery($model, $imagesRoot),
            'pdfTypes' => self::PDF_TYPES,
            'kitDisplayValue' => self::productLabel($admin['currentKitProduct'] ?? null),
            'otherProducts' => self::excluding($admin['allProducts'] ?? [], $currentId),
            'otherProductsWithOptions' => self::excluding($admin['productsWithOptions'] ?? [], $currentId),
            'otherProductsWithTechspec' => self::excluding($admin['productsWithTechspec'] ?? [], $currentId),
            'otherProductsWithFaq' => self::excluding($admin['productsWithFaq'] ?? [], $currentId),
            'pageRobots' => $admin['productPage']['robots'] ?? 'index,follow',
            'currentRestocking' => $admin['currentStatus']['restocking'] ?? 0,
        ];
    }

    /**
     * Image slots that have an uploaded image: [['slot', 'label', 'thumb'], ...].
     *
     * @param array<string, mixed> $admin
     * @return array<int, array{slot: string, label: string, thumb: string}>
     */
    private static function optionImageSlots(array $admin): array
    {
        $slots = [];
        foreach (self::IMAGE_SLOT_LABELS as $slot => $label) {
            if (empty($admin['availableImageSlots'][$slot])) continue;
            $slots[] = ['slot' => $slot, 'label' => $label, 'thumb' => $admin['imageUrls'][$slot] ?? ''];
        }

        return $slots;
    }

    /**
     * Description-tab image pickers: the selectable images, and for each of the
     * three descriptions its current image path and whether its row is disabled
     * (no text yet).
     *
     * @param array<string, mixed> $product
     * @return array{choices: array<string, string>, main: string, slots: array<int, array{path: string, src: string, isFallback: bool, disabled: bool}>}
     */
    private static function descriptionImages(array $product, object $model, string $imagesRoot): array
    {
        $main = $model->imagePath('large', '', '.jpg');
        $choices = ['' => '-- None --', $main => 'Main Image'];
        foreach (['extra1' => 'Gallery 1', 'extra2' => 'Gallery 2', 'extra3' => 'Gallery 3', 'extra4' => 'Gallery 4', 'extra5' => 'Gallery 5', 'warranty' => 'Warranty'] as $suffix => $name) {
            $path = $model->imagePath('extra', $suffix, '.jpg');
            if (\file_exists($imagesRoot . $path)) $choices[$path] = $name;
        }

        $slots = [];
        foreach ([1 => ['descr_image', 'descr'], 2 => ['descr2_image', 'descr2'], 3 => ['descr3_image', 'descr3']] as $number => [$imageKey, $textKey]) {
            $src = self::srcFromHtml($product[$imageKey] ?? '');
            $slots[$number] = [
                'src' => $src,
                'path' => $src !== '' ? $src : $main, // description 1 falls back to the main image
                'isFallback' => $src === '',
                'disabled' => \trim($product[$textKey] ?? '') === '',
            ];
        }

        return ['choices' => $choices, 'main' => $main, 'slots' => $slots];
    }

    /**
     * Description images store full HTML; pull out the src relative to /assets/images.
     */
    private static function srcFromHtml(?string $html): string
    {
        if (empty($html)) return '';
        if (\preg_match('/src="([^"]+)"/', $html, $matches)) {
            return \str_replace('/assets/images', '', $matches[1]);
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $allCategories
     * @param array<int, array<string, mixed>> $productCategories
     * @return array<int, array<string, mixed>>
     */
    private static function categoryChoices(array $allCategories, array $productCategories): array
    {
        $assignedIds = \array_column($productCategories, 'id');
        foreach ($allCategories as &$category) {
            $category['isAssigned'] = \in_array($category['id'], $assignedIds);
        }
        unset($category);

        return $allCategories;
    }

    /**
     * Sidebar gallery thumbnails: slot => ['url' (cache-busted, null when missing), 'label'].
     *
     * @return array<string, array{url: ?string, label: string}>
     */
    private static function sidebarGallery(object $model, string $imagesRoot): array
    {
        $gallery = [];
        foreach (self::GALLERY_LABELS as $suffix => $label) {
            $path = $model->imagePath('thumb', $suffix, '.jpg');
            $file = $imagesRoot . $path;
            $exists = \file_exists($file);
            $gallery[$suffix] = [
                'url' => $exists ? '/assets/images' . $path . '?v=' . \filemtime($file) : null,
                'label' => $label,
            ];
        }

        return $gallery;
    }

    /**
     * "Brand Model" text for a product row, or '' when there is none.
     *
     * @param array<string, mixed>|null $product
     */
    private static function productLabel(?array $product): string
    {
        if (!$product) return '';

        return ($product['brand_name'] ?? '') . ' ' . ($product['model_name'] ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private static function excluding(array $products, int $productId): array
    {
        return \array_values(\array_filter($products, fn(array $p): bool => (int) $p['id'] !== $productId));
    }
}
