<?php
use Osmium\Modules\Shop\Services\ShopProductCard;
use Osmium\Modules\Shop\Services\ShopProductPageData;

require_once __DIR__ . '/../services/ShopProductCard.php';
require_once __DIR__ . '/../services/ShopProductPageData.php';

$productCategory = $this->shop->model->productCategory;
$shopConfig = $this->osmium->config->shop;

$this->data['category']['ID'] = $productCategory->id;
$this->data['category']['label'] = $productCategory->label;
$this->data['category']['title'] = $productCategory->title;
$this->data['category']['subtitle'] = $productCategory->subtitle;
$this->data['category']['description'] = $productCategory->description; // category

$this->data['site']['socialImage'] = $this->data['site']['FQDN'] . $this->osmium->imagePath('/social/' . $productCategory->socialImage);

$this->data['category']['showCounts'] = $shopConfig->categoryShowCounts;
$this->data['category']['showSummaryText'] = $shopConfig->categoryShowSummaryText;
$this->data['category']['showCTA'] = $shopConfig->categoryShowCallToAction;

$this->data['subcategories'] = $productCategory->subcategories();
$this->data['subcategoryCount'] = count($this->data['subcategories']);
$this->data['subcategoryClass'] = 'lighting'; // subcategories

foreach ($this->data['subcategories'] as &$category) {
    $category['image'] = $this->osmium->imagePath('/shop/categories/' . $category['image'] . '/' . $category['image']); // subcategory images
}

$this->data['category']['range'] =
    $this->shop->model->product->rangeByCategory(
        $categoryIDs = $this->data['category']['ID'],
        $includeOutOfStock = true,
        $sortColumn = 'PC.sort'
    ); // products in category

$this->data['category']['productCount'] = 0;
$hasProductRange = (bool)$this->data['category']['range']['products'];
if ($hasProductRange) {
    $this->data['category']['productCount'] = count(
        explode(',', $this->data['category']['range']['products'])
    ); // count products
}

$this->data['category']['summary'] = ShopProductPageData::categorySummary(
    $this->data['category']['productCount'],
    $this->data['subcategoryCount']
); // summarise category

$noProducts = !$this->data['category']['productCount'];
if ($noProducts) {
    return;
}

$productRange = $this->data['category']['range']['products'];
$productIDs = \explode(separator: ',', string: $productRange);
$productsData = $this->shop->model->product->loadMultipleByIDs($productIDs); // load product range in category - batch load all products at once

foreach ($productIDs as $productID) {
    $productID = (int)$productID;
    $productData = $productsData[$productID] ?? null;

    if (!$productData) continue;

    $card = ShopProductCard::fromRow(
        productData: $productData,
        productId: $productID,
        taxRatePercent: (float)$shopConfig->taxRatePercent,
    );
    $imageBasePath = ShopProductCard::imageBasePath(brandPath: $card['brandPath'], modelPath: $card['modelPath']);

    $hasRrp = (float)($productData['rrp_exc_tax'] ?? 0) > 0;
    if (!$hasRrp) unset($card['savingsOnRRP']); // Nothing to save against

    $this->data['category']['products'][$productID] = $card + [
        'image' => $this->osmium->imagePath($imageBasePath),
        'image2x' => $this->osmium->imagePath($imageBasePath . '@2x'),
    ];
}
