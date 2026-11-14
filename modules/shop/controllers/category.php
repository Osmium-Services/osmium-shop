<?php
use Osmium\Modules\Shop\Services\ShopListControls;
use Osmium\Modules\Shop\Services\ShopProductCard;
use Osmium\Modules\Shop\Services\ShopProductDiscounts;
use Osmium\Modules\Shop\Services\ShopProductPageData;

require_once __DIR__ . '/../services/ShopListControls.php';
require_once __DIR__ . '/../services/ShopProductCard.php';
require_once __DIR__ . '/../services/ShopProductDiscounts.php';
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

// Sidebar category tree, with this category's node flagged active
$this->data['shop']['categoryTree'] = $productCategory->tree(activeCategoryID: (int) $this->data['category']['ID']);

// ?show=&sort=&stock[]=&page= are stashed in the session, since the querystring is stripped before a controller runs
$controls = ShopListControls::resolve(
    limit: $this->osmium->getStashedParam(key: 'show', default: ShopListControls::configuredLimit($shopConfig->categoryListLimit ?? null)),
    sort: $this->osmium->getStashedParam(key: 'sort', default: ShopListControls::DEFAULT_SORT),
    stock: $this->osmium->getStashedParam(key: 'stock', default: []),
    page: $this->osmium->getStashedParam(key: 'page', default: 1),
);
$this->osmium->clearStashedQuerystring();

// The untouched "Newest" default keeps the category's own admin-set order (PC.sort)
$categorySortColumn = $controls['sort'] === ShopListControls::DEFAULT_SORT ? 'PC.sort' : $controls['sortColumn'];

// Opt-in: with "categoryListsBranch": true in the shop config a category lists the products of every category
// beneath it too, not only those assigned to it directly. Off (the default) for sites that don't set it.
$listsBranch = (bool) ($shopConfig->categoryListsBranch ?? false);
$this->data['category']['range'] = $listsBranch
    ? $this->shop->model->product->rangeByBranch(
        categoryIDs: $productCategory->idsBeneath((int) $this->data['category']['ID']),
        sortColumn: $categorySortColumn,
        stock: $controls['stock'],
    )
    : $this->shop->model->product->rangeByCategory(
        categoryIDs: (string) $this->data['category']['ID'],
        sortColumn: $categorySortColumn,
        stock: $controls['stock'],
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

$this->data['category']['products'] = []; // A category with only subcategories has none, and the view counts them

$this->data['shop']['listLimit'] = $controls['limit'];
$this->data['shop']['listLimitOptions'] = ShopListControls::LIMIT_OPTIONS;
$this->data['shop']['listSort'] = $controls['sort'];
$this->data['shop']['listSortOptions'] = ShopListControls::SORT_OPTIONS;
$this->data['shop']['listStock'] = $controls['stock'];
$this->data['shop']['listTotal'] = $this->data['category']['productCount'];
$this->data['shop']['listPage'] = $controls['page'];

$noProducts = !$this->data['category']['productCount'];
if ($noProducts) {
    return;
}

$productRange = $this->data['category']['range']['products'];
$productIDs = \explode(separator: ',', string: $productRange);
[$productIDs, $page] = ShopListControls::paginate($productIDs, $controls['page'], $controls['limit']);
$this->data['shop']['listPage'] = $page; // clamped to the last real page
$productsData = $this->shop->model->product->loadMultipleByIDs($productIDs); // load product range in category - batch load all products at once
$productDiscounts = ShopProductDiscounts::forProducts($this->osmium, $productsData, (float)$shopConfig->taxRatePercent, \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium)->currencySign());

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
        'activeDiscount' => $productDiscounts[$productID] ?? null,
        'image' => $this->osmium->imagePath($imageBasePath),
        'image2x' => $this->osmium->imagePath($imageBasePath . '@2x'),
    ];
}
