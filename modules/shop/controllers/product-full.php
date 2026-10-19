<?php

use Osmium\Core\Osmium;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Shop\Core\OsmiumShop;
use Osmium\Modules\Shop\Services\ShopProductPageData;
use Osmium\Modules\Shop\Services\ShopKitAvailability;
use Osmium\Modules\Shop\Services\ShopProductService;

require_once __DIR__ . '/../services/ShopProductPageData.php';
require_once __DIR__ . '/../services/ShopProductService.php';
require_once __DIR__ . '/../services/ShopKitAvailability.php';

$product = $this->shop->model->product;
$productOption = $this->shop->model->productOption;
$shopConfig = $this->osmium->config->shop;

$productService = new ShopProductService(
    dataSource: $this->osmium->dataSource,
    taxRatePercent: (float)$shopConfig->taxRatePercent,
); // Initialize service

$this->data['site']['pageProductID'] = $this->shop->productID;

$this->data['shop']['product']['showSummary'] = $shopConfig->productShowSummary;
$this->data['shop']['product']['showPriceExcTax'] = $shopConfig->productShowPriceExcTax;
$this->data['shop']['product']['showDeliveryMessage'] = $shopConfig->productShowDeliveryMessage;

$product->loadByID($this->shop->productID);

// ─────────────────────────────────────────────────────────────────────────────
// Basic product data
// ─────────────────────────────────────────────────────────────────────────────

$this->data['product']['ID'] = $product->id;
$this->data['product']['id'] = $product->id;
$this->data['product']['CategoryID'] = $product->categoryID;
$this->data['product']['kitLink'] = $product->kitLink;
$this->data['product']['kitId'] = $product->kitId;

$this->data['product']['kitProduct'] = $productService->loadKitProduct(
    kitId: $product->kitId,
    loadProductFn: fn($ids) => $this->shop->model->product->loadMultipleByIDs($ids),
); // Load related "See also" product if kit_id is set

$this->data['product']['reference'] = $product->reference;
$this->data['product']['brandName'] = $product->brandName;
$this->data['product']['barcode'] = $product->barcode;
$this->data['product']['title'] = $product->title;
$this->data['product']['isAvailable'] = $product->available;
$this->data['product']['restockDate'] = $product->restockDate;
$this->data['product']['isNew'] = $product->isNew();
$this->data['product']['customOffer'] = $product->offer;
$this->data['product']['statusID'] = $product->statusID;
$this->data['product']['statusName'] = $product->statusName;
$this->data['product']['schemaRef'] = $product->schemaRef;
$this->data['product']['packedWeight'] = $product->packedWeight;
$this->data['product']['addedDate'] = $product->addedDate;

/**
 * List all product thumbnails with retina variants.
 * A closure, not a named function, so this script can run more than once in a process.
 */
$listThumbnails = static function (Osmium $osmium, OsmiumShop $shop): array {
    $list = [];
    $firstImage = true;
    $imageSuffixes = ['', 'extra1', 'extra2', 'extra3', 'extra4', 'extra5', 'warranty'];

    foreach ($imageSuffixes as $suffix) {
        $thumbPath = $osmium->imagePath(
            $shop->model->product->imagePath('thumb', $suffix)
        );

        $thumbMissing = !$osmium->fileSystem->fileExists(\ltrim($thumbPath, '/\\'));
        if ($thumbMissing) {
            $firstImage = false;
            continue;
        }

        $largeType = $firstImage ? 'large' : 'extra';
        $slot = $suffix === '' ? 'main' : $suffix;

        $list[] = [
            'slot' => $slot,
            'thumb' => $thumbPath,
            'retinaThumb' => $osmium->imagePath(
                $shop->model->product->imagePath('thumb', $suffix, '@2x')
            ),
            'large' => $osmium->imagePath(
                $shop->model->product->imagePath($largeType, $suffix)
            ),
            'retinaLarge' => $osmium->imagePath(
                $shop->model->product->imagePath($largeType, $suffix, '@2x')
            ),
        ];

        $firstImage = false;
    }

    return $list;
};

// ─────────────────────────────────────────────────────────────────────────────
// Images
// ─────────────────────────────────────────────────────────────────────────────

$this->data['product']['largeImage'] = $this->osmium->imagePath($product->imagePath('large'));
$this->data['product']['largeImageRetina'] = $this->osmium->imagePath($product->imagePath('large', '', '@2x'));

$brandPath = $product->brandPath;
$modelPath = $product->modelPath;
$imagesPath = $this->osmium->config->site->imagesPath;
$schemaImagePath = "{$imagesPath}/shop/{$brandPath}/{$modelPath}/large/{$brandPath}-{$modelPath}.jpg";
$this->data['product']['schemaPhoto'] = $schemaImagePath;
$this->data['product']['checkoutThumb'] = "{$brandPath}/{$modelPath}/thumb/{$brandPath}-{$modelPath}.jpg";
$this->data['product']['thumbnails'] = $listThumbnails($this->osmium, $this->shop);

// ─────────────────────────────────────────────────────────────────────────────
// Pricing
// ─────────────────────────────────────────────────────────────────────────────

$rrpExcTax = $product->rrpExcTax;
$priceExcTax = $product->priceExcTax;

$this->data['product']['RRPIncTax'] = $productService->formatPriceIncTax($rrpExcTax);
$this->data['product']['priceIncTax'] = $productService->formatPriceIncTax($priceExcTax);
$this->data['product']['schemaPriceIncTax'] = $productService->schemaPriceIncTax($priceExcTax);
$this->data['product']['RRPExcTax'] = $productService->formatPriceExcTax($rrpExcTax);
$this->data['product']['priceExcTax'] = $productService->formatPriceExcTax($priceExcTax);

$this->data['product']['shopEvent'] = [
    'product_id' => (int)$product->id,
    'title' => $product->title,
    'value' => (float)$productService->schemaPriceIncTax($priceExcTax),
    'currency' => \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium)->currencyCode(),
]; // For the osmium:shop-event view_item tag (THEME-CONTRACT.md section 7)

$savings = $productService->calculateSavings(
    priceExcTax: $priceExcTax,
    rrpExcTax: $rrpExcTax,
);
$this->data['product']['discount'] = $savings;
$this->data['product']['getSavingsOnRRP'] = $savings;
$this->data['product']['quantityDiscounts'] = $product->discounts;

$checkoutConfig = $this->osmium->config->checkout;
$deliveryChargeExcTax = (float) ($checkoutConfig->deliveryCharge ?? 0);
$this->data['shop']['schemaDeliveryChargeIncTax'] = $productService->schemaPriceIncTax($deliveryChargeExcTax);

// ─────────────────────────────────────────────────────────────────────────────
// Product options (dynamic multi-level support)
// ─────────────────────────────────────────────────────────────────────────────

$rawOptions = $productOption->getAllOptionsForProduct($this->shop->productID);
$currencySign = $this->data['shop']['currencySign'];

$optionTypes = $productService->buildOptionTypes(
    rawOptions: $rawOptions,
    currencySign: $currencySign,
);

foreach ($optionTypes as &$typeData) {
    $hasLinkedProduct = !empty($typeData['linkedProductId']);
    if ($hasLinkedProduct) {
        $brand = $typeData['linkedProductBrand'];
        $model = $typeData['linkedProductModel'];
        $imagePath = "/shop/{$brand}/{$model}/thumb/{$brand}-{$model}.jpg";
        $typeData['linkedProductImage'] = $this->osmium->imagePath($imagePath); // Add linked product images (requires framework imagePath)
    }
}
unset($typeData);

$kitAvailability = new ShopKitAvailability();
$this->data['product'] = $kitAvailability->applyTo(
    productData: $this->data['product'],
    kit: $kitAvailability->evaluate($optionTypes, \date('Y-m-d')),
); // A kit with no choice left in one of its option types is out of stock as a whole

$this->data['product']['optionTypes'] = $optionTypes;
$this->data['product']['optionTypesJSON'] = $productService->buildOptionTypesJson($optionTypes);

$legacy = $productService->buildLegacyOptions($optionTypes);
$this->data['product']['options'] = $legacy['options'];
$this->data['product']['optionLabel'] = $legacy['optionLabel'];
$this->data['product']['optionDefault'] = $legacy['optionDefault'];
$this->data['product']['optionWeights'] = $legacy['optionWeights'];
$this->data['product']['optionPremiums'] = $legacy['optionPremiums'];
$this->data['product']['optionRRPs'] = $legacy['optionRRPs'];
$this->data['product']['suboptions'] = $legacy['suboptions'];
$this->data['product']['suboptionLabel'] = $legacy['suboptionLabel'];
$this->data['product']['suboptionWeights'] = $legacy['suboptionWeights'];
$this->data['product']['suboptionPremiums'] = $legacy['suboptionPremiums'];
$this->data['product']['suboptionRRPs'] = $legacy['suboptionRRPs']; // Legacy compatibility (old template format)

// ─────────────────────────────────────────────────────────────────────────────
// Additional content
// ─────────────────────────────────────────────────────────────────────────────

$this->data['product']['videoURL'] = $product->videoURL;
$this->data['product']['FAQ'] = $product->faq;
$this->data['product']['faqPairs'] = ShopProductPageData::faqPairs($product->faq); // For FAQPage schema
$this->data['product']['techSpec'] = $product->techspec;
$this->data['product']['specProperties'] = ShopProductPageData::specProperties($product->techspecJson); // For Product schema additionalProperty

$basePath = '/shop/' . $brandPath . '/' . $modelPath;
$baseName = $brandPath . '-' . $modelPath; // PDF documents

$brochurePath = $this->osmium->imagePath("{$basePath}/brochure/{$baseName}-brochure.pdf");
$this->data['product']['brochurePath'] = ShopProductPageData::pdfPathOrNull($brochurePath);

$datasheetPath = $this->osmium->imagePath("{$basePath}/datasheet/{$baseName}-datasheet.pdf");
$this->data['product']['datasheetPath'] = ShopProductPageData::pdfPathOrNull($datasheetPath);

$instructionsPath = $this->osmium->imagePath("{$basePath}/instructions/{$baseName}-instructions.pdf");
$this->data['product']['instructionsPath'] = ShopProductPageData::pdfPathOrNull($instructionsPath);

$informationPath = $this->osmium->imagePath("{$basePath}/information/{$baseName}-information.pdf");
$this->data['product']['informationPath'] = ShopProductPageData::pdfPathOrNull($informationPath);

$this->data['product']['descriptionText1'] = $product->descr;
$this->data['product']['descriptionText2'] = $product->descr2;
$this->data['product']['descriptionText3'] = $product->descr3;
$this->data['product']['descriptionImage'] = $product->descrImage;
$this->data['product']['descriptionImage2'] = $product->descrImage2;
$this->data['product']['descriptionImage3'] = $product->descrImage3;

// ─────────────────────────────────────────────────────────────────────────────
// Meta description fallback (for SEO / structured data)
// ─────────────────────────────────────────────────────────────────────────────

$hasMetaDescription = !empty($this->data['template']['metaDescription']);
if (!$hasMetaDescription) {
    $this->data['template']['metaDescription'] = ShopProductPageData::metaDescription($product->descr);
}

// ─────────────────────────────────────────────────────────────────────────────
// Data from services (reviews, ratings...) arrives through the product.page.data hook
// ─────────────────────────────────────────────────────────────────────────────

$hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
$serviceData = $hooks->collect(
    event: 'product.page.data',
    payload: ['productId' => $this->shop->productID, 'dataSource' => $this->osmium->dataSource],
);
$this->data['product'] = ShopProductPageData::withServiceData(product: $this->data['product'], results: $serviceData);

$this->data['site']['socialImage'] = $this->osmium->config->site->FQDN
    . $this->osmium->imagePath($product->imagePath('social', '', '.jpg')); // Social image for Open Graph
