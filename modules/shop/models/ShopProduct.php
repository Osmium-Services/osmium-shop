<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProduct extends ShopModel
{
    public private(set) int $id = 0;
    public private(set) int $categoryID = 0;
    public private(set) ?string $kitLink = null;
    public private(set) ?int $kitId = null;
    public private(set) int $statusID = 0;
    public private(set) int $brandID = 0;
    public private(set) string $barcode = '';
    public private(set) string $reference = '';
    public private(set) string $model = '';
    public private(set) float $priceExcTax = 0.00;
    public private(set) float $rrpExcTax = 0.00;
    public private(set) string $offer = '';
    public private(set) string $descr = '';
    public private(set) string $descrImage = '';
    public private(set) string $descr2 = '';
    public private(set) string $descrImage2 = '';
    public private(set) string $descr3 = '';
    public private(set) string $descrImage3 = '';
    public private(set) string $videoURL = '';
    public private(set) string $techspec = '';
    public private(set) string $techspecJson = '';
    public private(set) string $features = '';
    public private(set) string $accessories = '';
    public private(set) string $alternatives = '';
    public private(set) string $faq = '';
    public private(set) ?string $faqList = null;
    public private(set) string $discounts = '';
    public private(set) float $packedWeight = 0;
    public private(set) string $optionLabel = '';
    public private(set) string $optionDefault = '';
    public private(set) string $infoExternal = '';
    public private(set) string $schemaRef = '';
    public private(set) int $available = 0;
    public private(set) string $brandName = '';
    public private(set) string $statusShort = '';
    public private(set) string $statusName = '';
    public private(set) string $productStub = '';
    public private(set) ?string $restockDate = null;
    public private(set) ?string $addedDate = null;
    public private(set) string $title = '';
    public private(set) string $modelPath = '';
    public private(set) string $brandPath = '';
    public private(set) array $options = [];
    public private(set) array $optionWeights = [];

    public function loadByID(int $productID): array|false
    {
        $productData = $this->getProductDataByID($productID);

        $notFound = $productData === [];
        if ($notFound) return $productData; // Leave the model unloaded rather than half-filled with defaults

        $this->setInternalPropertiesFromData($productData, $productID);

        return $productData;
    }

    private function setInternalPropertiesFromData(array $productData, int $productID): void
    {
        $this->id = $productID;
        $this->categoryID = $productData['category_id'] ?? 0;
        $this->kitLink = $productData['kit_link'] ?? null;
        $this->kitId = isset($productData['kit_id']) ? (int)$productData['kit_id'] : null;
        $this->statusID = $productData['status_id'] ?? 0;
        $this->brandID = $productData['brand_id'] ?? 0;
        $this->barcode = $productData['barcode'] ?? '';
        $this->reference = $productData['reference'] ?? '';
        $this->model = $productData['model_name'] ?? '';
        $this->priceExcTax = (float) ($productData['price_exc_tax'] ?? 0);
        $this->rrpExcTax = (float) ($productData['rrp_exc_tax'] ?? 0);
        $this->offer = $productData['custom_offer'] ?? '';
        $this->descr = $productData['descr'] ?? '';
        $this->descrImage = $productData['descr_image'] ?? '';
        $this->descr2 = $productData['descr2'] ?? '';
        $this->descrImage2 = $productData['descr2_image'] ?? '';
        $this->descr3 = $productData['descr3'] ?? '';
        $this->descrImage3 = $productData['descr3_image'] ?? '';
        $this->videoURL = $productData['video_url'] ?? '';
        $this->techspec = $productData['techspec'] ?? '';
        $this->techspecJson = $productData['techspec_json'] ?? '';
        $this->features = $productData['features'] ?? '';
        $this->accessories = $productData['accessories'] ?? '';
        $this->alternatives = $productData['alternatives'] ?? '';
        $this->faq = $productData['faq'] ?? '';
        $this->faqList = ($productData['faq_list'] ?? '') !== '' ? $productData['faq_list'] : null;
        $this->discounts = $productData['discounts'] ?? '';
        $this->packedWeight = $productData['packed_weight'] ?? 0;
        $this->optionLabel = $productData['option_label'] ?? '';
        $this->optionDefault = $productData['option_default'] ?? '';
        $this->infoExternal = $productData['info_external'] ?? '';
        $this->schemaRef = $productData['schema_ref'] ?? '';
        $this->available = $productData['product_available'] ?? 0;
        $this->brandName = $productData['brand_name'] ?? '';
        $this->statusShort = $productData['descr_short'] ?? '';
        $this->statusName = $productData['descr_long'] ?? '';
        $this->productStub = $productData['url'] ?? '';
        $this->restockDate = $productData['restock_at'] ?? null;
        $this->addedDate = $productData['created_at'] ?? null;
        $this->title = $this->brandName . ' ' . $this->model;
        $this->modelPath = $this->sanitizePath($this->model);
        $this->brandPath = $this->sanitizePath($this->brandName);
    }

    private function getProductDataByID(int $productID): array
    {
        $query = $this->loadSqlFile('product-get-by-id.sql');
        $this->database->query($query);
        $this->database->bind(param: ':productID', value: $productID);
        $result = $this->database->single();

        if (!$result) return []; // Product not found

        $hasPageId = $result['page_id'];
        if ($hasPageId) { // Get URL from PAGES constant and check page deleted_at
            $page = $this->pagesById()[$result['page_id']] ?? null;
            $pageIsValid = $page && $page['deleted_at'] === null;
            if ($pageIsValid) {
                $result['url'] = $page['url'];
            } else {
                return []; // Page not found or deleted - return empty to indicate product unavailable
            }
        }

        return $result;
    }

    public function loadMultipleByIDs(array $productIDs): array
    {
        $idsEmpty = empty($productIDs);
        if ($idsEmpty) return [];

        $productIDs = \array_map(callback: 'intval', array: $productIDs);
        $productIDs = \array_filter($productIDs);
        $productIDs = \array_unique($productIDs);
        $productIDs = \array_values($productIDs); // Sanitize: cast all to int, filter out zeros, ensure unique

        $idsEmpty = empty($productIDs);
        if ($idsEmpty) return [];

        $placeholders = [];
        foreach ($productIDs as $index => $id) {
            $placeholders[] = ':id' . $index; // Generate placeholders: :id0, :id1, :id2, etc.
        }
        $placeholderString = \implode(separator: ', ', array: $placeholders);

        $query = $this->loadSqlFile('product-get-by-ids.sql');
        $query = \str_replace(
            search: ':productIDs',
            replace: $placeholderString,
            subject: $query
        );

        $this->database->query($query);

        foreach ($productIDs as $index => $id) {
            $this->database->bind(param: ':id' . $index, value: $id); // Bind each ID to its placeholder
        }

        $results = $this->database->resultset();

        $pagesById = $this->pagesById();
        $productsById = [];

        foreach ($results as $result) {
            $pageId = $result['page_id'] ?? null;
            $hasValidPage = $pageId && isset($pagesById[$pageId]) && $pagesById[$pageId]['deleted_at'] === null;

            if (!$hasValidPage) continue;

            $result['url'] = $pagesById[$pageId]['url'];
            $productId = (int)$result['id'];

            $brandName = $result['brand_name'] ?? '';
            $model = $result['model_name'] ?? '';
            $brandPath = $this->sanitizePath($brandName);
            $modelPath = $this->sanitizePath($model);

            $result['title'] = $brandName . ' ' . $model;
            $result['brand_path'] = $brandPath;
            $result['model_path'] = $modelPath;

            $productsById[$productId] = $result;
        }

        return $productsById;
    }

    public function all(?string $categoryId = null): array
    {
        if ($categoryId) {
            $query = $this->loadSqlFile('product-list-by-category.sql');
            $this->database->query($query);
            $this->database->bind(':categoryId', $categoryId);
        } else {
            $query = $this->loadSqlFile('product-list-all.sql');
            $this->database->query($query);
        }

        return $this->database->resultset();
    }

    public function count(): int
    {
        $query = $this->loadSqlFile('product-count-all.sql');
        $this->database->query($query);
        $result = $this->database->single();

        return (int) ($result['total'] ?? 0);
    }

    public function rangeByCategory(
        string $categoryIDs,
        bool $includeOutOfStock = true,
        string $sortColumn = 'product_model'
    ): array|false {
        $query = $this->loadSqlFile('product-range-by-category.sql', [
            '{SORT_COLUMN}' => $sortColumn,
        ]);
        if (!$includeOutOfStock) $query .= ' AND P.product_status_id NOT IN (3,4)';
        $this->database->query($query);
        $this->database->bind(':categoryIDs', $categoryIDs);

        return $this->database->single();
    }

    public function newArrivals(int $limit): array
    {
        $query = $this->loadSqlFile('product-new-arrivals.sql');
        $this->database->query($query);
        $this->database->bind(':limit', $limit);

        return $this->database->resultset();
    }

    public function getByPageUrl(string $pageUrl): array|false
    {
        $query = $this->loadSqlFile('product-get-by-page-url.sql');
        $this->database->query($query);
        $this->database->bind(param: ':page_url', value: $pageUrl);

        return $this->database->single();
    }

    public function imagePath(string $imageSize = 'medium', string $imageType = '', string $suffix = ''): string
    {
        $hasImageType = \mb_strlen($imageType) > 0;
        if ($hasImageType) $imageType = '-' . $imageType;

        $imagePath = "/shop/{$this->brandPath}/{$this->modelPath}/{$imageSize}/{$this->brandPath}-{$this->modelPath}{$imageType}{$suffix}";

        return $imagePath;
    }

    public function isNew(): bool
    {
        return (\strtotime($this->addedDate)) > (\strtotime("-1 month"));
    }

    /**
     * Sanitize a string to create a URL/filesystem-safe path.
     * Only allows a-z, 0-9, and hyphens.
     */
    private function sanitizePath(string $input): string
    {
        return $input
            |> trim(...)
            |> mb_strtolower(...)
            |> (fn($s) => \str_replace(['/', '\\', '&'], '', $s))
            |> (fn($s) => \preg_replace('/[^a-z0-9-]+/', '-', $s))
            |> (fn($s) => \preg_replace('/-+/', '-', $s))
            |> (fn($s) => \trim($s, '-'));
    }
}
