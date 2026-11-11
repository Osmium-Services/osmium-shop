<?php

use Osmium\Modules\Shop\Core\OsmiumShop;

require_once __DIR__ . '/OsmiumShopClass.php';

$this->shop = new OsmiumShop($this->osmium);

$this->data['shop']['blocksPath'] = 'app/modules/shop/views/blocks/';

$config = $this->osmium->config->shop;
$finance = \Osmium\Core\Library\StoreFinance::forOsmium($this->osmium); // Currency, tax and country: one source for every view
$this->data['shop']['finance']         = $finance; // $shop['finance']->format(12.5) gives "£12.50"
$this->data['shop']['currency']        = $finance->currencyCode();
$this->data['shop']['currencySign']    = $finance->currencySign();
$this->data['shop']['taxName']         = $finance->taxName();
$this->data['shop']['taxRate']         = $finance->taxRatePercent();
$this->data['shop']['country']         = $finance->country();
$this->data['shop']['deliveryMessage'] = $config->deliveryMessage;
$this->data['shop']['searchEnabled']   = $config->searchEnabled ?? false;

$paymentExternal = \Osmium\Core\Library\PaymentProviders::external($this->osmium);
$paymentProvider = \Osmium\Core\Library\PaymentProviders::configured($this->osmium->config->checkout, $paymentExternal);
$this->data['shop']['paymentMethods']  = \Osmium\Core\Library\PaymentProviders::methods($paymentProvider, $paymentExternal); // Payment types the selected provider accepts, or null (none chosen / not declared); themes show badges from it

$hasCategoryId = $this->shop->categoryID;
if ($hasCategoryId) {
    $this->data['shop']['categoryID'] = $this->shop->categoryID;
    $this->shop->model->productCategory->loadByID($this->shop->categoryID);

    require $this->findModuleFile('shop', 'controllers/category.php'); // The site's own copy first, else the service's

    $this->data['template']['pageContent'] =
        $this->data['site']['modulePath'] . '/shop/views/category.phtml';

    return;
}

$hasProductId = $this->shop->productID;
if ($hasProductId) {
    $this->data['shop']['productID'] = $this->shop->productID;

    $this->data['shop']['productQR'] = str_ends_with($this->osmium->validRequest, '/qr/');

    $this->shop->model->product->loadByID($this->shop->productID);

    require $this->findModuleFile('shop', 'controllers/product-full.php');

    $this->data['template']['pageContent'] =
        $this->data['site']['modulePath'] . '/shop/views/product.phtml';

    return; // QR product page fix required...
}
