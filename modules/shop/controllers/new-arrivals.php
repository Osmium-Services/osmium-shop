<?php
use Osmium\Modules\Shop\Services\ShopProductCard;

require_once __DIR__ . '/../services/ShopProductCard.php';

$newArrivalItems = $this->shop->model->product->newArrivals(limit: 3);
$productIDs = \array_column(array: $newArrivalItems, column_key: 'id'); // Get new arrival product IDs

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

$this->data['newArrivals'] = $data['products'] ?? null;
$hasProducts = isset($data['products']);
if ($hasProducts) {
    unset($data['products']);
}
