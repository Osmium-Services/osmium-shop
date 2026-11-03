<?php
use Osmium\Modules\Shop\Services\ShopListControls;
use Osmium\Modules\Shop\Services\ShopProductCard;

require_once __DIR__ . '/../services/ShopListControls.php';
require_once __DIR__ . '/../services/ShopProductCard.php';

$shopConfig = $this->osmium->config->shop;

// Sidebar category tree: no category is active on the landing page itself
$this->data['shop']['categoryTree'] = $this->shop->model->productCategory->tree();

// ?show=&sort=&stock[]=&page= are stashed in the session, since the querystring is stripped before a controller runs
$controls = ShopListControls::resolve(
    limit: $this->osmium->getStashedParam(key: 'show', default: ShopListControls::DEFAULT_LIMIT),
    sort: $this->osmium->getStashedParam(key: 'sort', default: ShopListControls::DEFAULT_SORT),
    stock: $this->osmium->getStashedParam(key: 'stock', default: []),
    page: $this->osmium->getStashedParam(key: 'page', default: 1),
);
$this->osmium->clearStashedQuerystring();

$productIDs = \array_column(
    array: $this->shop->model->product->all(sortColumn: $controls['sortColumn'], stock: $controls['stock']),
    column_key: 'id',
);
$total = \count($productIDs);
[$productIDs, $page] = ShopListControls::paginate($productIDs, $controls['page'], $controls['limit']);

$this->data['shop']['listLimit'] = $controls['limit'];
$this->data['shop']['listLimitOptions'] = ShopListControls::LIMIT_OPTIONS;
$this->data['shop']['listSort'] = $controls['sort'];
$this->data['shop']['listSortOptions'] = ShopListControls::SORT_OPTIONS;
$this->data['shop']['listStock'] = $controls['stock'];
$this->data['shop']['listTotal'] = $total;
$this->data['shop']['listPage'] = $page;

$productsById = $this->shop->model->product->loadMultipleByIDs(productIDs: $productIDs);

$this->data['shop']['products'] = [];
foreach ($productIDs as $productID) {
    $productData = $productsById[$productID] ?? null;
    if (!$productData) continue;

    $card = ShopProductCard::fromRow(
        productData: $productData,
        productId: (int) $productID,
        taxRatePercent: (float) $shopConfig->taxRatePercent,
    );
    $imageBasePath = ShopProductCard::imageBasePath(brandPath: $card['brandPath'], modelPath: $card['modelPath']);

    $hasRrp = (float) ($productData['rrp_exc_tax'] ?? 0) > 0;
    if (!$hasRrp) unset($card['savingsOnRRP']);

    $this->data['shop']['products'][] = $card + [
        'image' => $this->osmium->imagePath($imageBasePath),
        'image2x' => $this->osmium->imagePath($imageBasePath . '@2x'),
    ];
}
