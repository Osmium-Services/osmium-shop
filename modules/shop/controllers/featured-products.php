<?php
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Shop\Services\ShopProductCard;

require_once __DIR__ . '/../services/ShopProductCard.php';

// The featured list is curated by a service (Osmium Featured Products) and arrives through the featured.products hook
$hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
$featured = $hooks->collect(event: 'featured.products', payload: ['dataSource' => $this->osmium->dataSource]);
$productIDs = \array_values(\array_unique(\array_map('intval', $featured[0]['productIds'] ?? []))); // The first service to answer wins

$productsById = $this->shop->model->product->loadMultipleByIDs(productIDs: $productIDs); // Load all products in a single query

$data['products'] = [];
foreach ($productIDs as $productID) {
    $productData = $productsById[$productID] ?? null;
    if (!$productData) continue; // Build product data array

    $card = ShopProductCard::fromRow(
        productData: $productData,
        productId: (int)$productID,
        taxRatePercent: (float)$this->osmium->config->shop->taxRatePercent,
    );
    $imageBasePath = ShopProductCard::imageBasePath(brandPath: $card['brandPath'], modelPath: $card['modelPath']);

    $data['products'][$productID] = $card + [
        'image' => $this->osmium->imagePath($imageBasePath),
        'image2x' => $this->osmium->imagePath($imageBasePath . '@2x'),
    ];
}

$this->data['featuredProducts'] = $data['products'] ?? null;
$hasProducts = isset($data['products']);
if ($hasProducts) {
    unset($data['products']);
}
