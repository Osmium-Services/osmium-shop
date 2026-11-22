<?php
use Osmium\Modules\Shop\Services\ShopListControls;
use Osmium\Modules\Shop\Services\ShopProductCard;
use Osmium\Modules\Shop\Services\ShopProductDiscounts;

require_once __DIR__ . '/../services/ShopListControls.php';
require_once __DIR__ . '/../services/ShopProductCard.php';
require_once __DIR__ . '/../services/ShopProductDiscounts.php';

// A brand's own page: every live product of the brand. A page with many products is split under its categories.
$shopConfig = $this->osmium->config->shop;
$brand = $this->shop->brand;

$this->data['brand']['ID'] = $brand['id'];
$this->data['brand']['name'] = $brand['name'];
$this->data['brand']['logo'] = $brand['logo'] ? $this->osmium->imagePath('/shop/brands/' . $brand['logo']) : null;
$this->data['brand']['infoExternal'] = $brand['info_external'];

// Sidebar category tree: no category is active on a brand page
$this->data['shop']['categoryTree'] = $this->shop->model->productCategory->tree();

// ?show=&sort=&stock[]=&page= are stashed in the session, since the querystring is stripped before a controller runs
$controls = ShopListControls::resolve(
    limit: $this->osmium->getStashedParam(key: 'show', default: ShopListControls::configuredLimit($shopConfig->categoryListLimit ?? null)),
    sort: $this->osmium->getStashedParam(key: 'sort', default: ShopListControls::DEFAULT_SORT),
    stock: $this->osmium->getStashedParam(key: 'stock', default: []),
    page: $this->osmium->getStashedParam(key: 'page', default: 1),
);
$this->osmium->clearStashedQuerystring();

$range = $this->shop->model->product->rangeByBrand(
    brandID: (int) $brand['id'],
    sortColumn: $controls['sortColumn'],
    stock: $controls['stock'],
);
$allIDs = ($range && $range['products']) ? \array_map('intval', \explode(',', $range['products'])) : [];
$total = \count($allIDs);

// Grouped pages show every product (the groups are how a visitor finds one); small brands paginate like a category
$groupThreshold = (int) ($shopConfig->brandGroupThreshold ?? 24);
$isGrouped = $groupThreshold > 0 && $total > $groupThreshold;
$productIDs = $allIDs;
$page = 1;
if (!$isGrouped) [$productIDs, $page] = ShopListControls::paginate($allIDs, $controls['page'], $controls['limit']);

$this->data['brand']['productCount'] = $total;
$this->data['brand']['grouped'] = $isGrouped;
$this->data['brand']['products'] = [];
$this->data['brand']['groups'] = [];

$this->data['shop']['listLimit'] = $controls['limit'];
$this->data['shop']['listLimitOptions'] = ShopListControls::LIMIT_OPTIONS;
$this->data['shop']['listSort'] = $controls['sort'];
$this->data['shop']['listSortOptions'] = ShopListControls::SORT_OPTIONS;
$this->data['shop']['listStock'] = $controls['stock'];
$this->data['shop']['listTotal'] = $total;
$this->data['shop']['listPage'] = $page;

if ($total === 0) return;

$productsData = $this->shop->model->product->loadMultipleByIDs($productIDs);
$productDiscounts = ShopProductDiscounts::forProducts($this->osmium, $productsData, (float) $shopConfig->taxRatePercent, \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium)->currencySign());

foreach ($productIDs as $productID) {
    $productData = $productsData[$productID] ?? null;
    if (!$productData) continue;

    $card = ShopProductCard::fromRow(
        productData: $productData,
        productId: $productID,
        taxRatePercent: (float) $shopConfig->taxRatePercent,
    );
    $imageBasePath = ShopProductCard::imageBasePath(brandPath: $card['brandPath'], modelPath: $card['modelPath']);

    $hasRrp = (float) ($productData['rrp_exc_tax'] ?? 0) > 0;
    if (!$hasRrp) unset($card['savingsOnRRP']); // Nothing to save against

    $this->data['brand']['products'][$productID] = $card + [
        'activeDiscount' => $productDiscounts[$productID] ?? null,
        'image' => $this->osmium->imagePath($imageBasePath),
        'image2x' => $this->osmium->imagePath($imageBasePath . '@2x'),
    ];
}

if (!$isGrouped) return;

// Group the cards under the first category each product is assigned to, in the brand's own product order
$categories = $this->shop->model->product->firstCategories($productIDs);
$groups = [];
foreach ($this->data['brand']['products'] as $productID => $card) {
    $category = $categories[$productID] ?? ['id' => 0, 'label' => 'Other'];
    $groups[$category['id']]['label'] = $category['label'];
    $groups[$category['id']]['products'][$productID] = $card;
}
\uasort($groups, static fn (array $a, array $b): int => \strcasecmp($a['label'], $b['label']));

foreach ($groups as $categoryID => $group) {
    $this->data['brand']['groups'][] = [
        'id' => $categoryID,
        'label' => $group['label'],
        'anchor' => 'category-' . ($categoryID ?: 'other'),
        'products' => $group['products'],
    ];
}
