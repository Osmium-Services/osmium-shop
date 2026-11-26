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

require_once __DIR__ . '/../services/ShopProductDiscounts.php';
$this->data['shop']['freeDeliveryOffer'] = \Osmium\Modules\Shop\Services\ShopProductDiscounts::freeDeliveryOffer($this->osmium, $finance->currencySign()); // {minSpendIncTax, label} or null; themes can advertise it

$hasCategoryId = $this->shop->categoryID;
if ($hasCategoryId) {
    $this->data['shop']['categoryID'] = $this->shop->categoryID;
    $this->shop->model->productCategory->loadByID($this->shop->categoryID);

    require $this->findModuleFile('shop', 'controllers/category.php'); // The site's own copy first, else the service's

    $this->data['template']['pageContent'] =
        $this->data['site']['modulePath'] . '/shop/views/category.phtml';

    return;
}

$isBrandPage = $this->shop->brand !== null;
if ($isBrandPage) {
    $this->data['shop']['brandID'] = $this->shop->brand['id'];

    require $this->findModuleFile('shop', 'controllers/brand.php'); // The site's own copy first, else the service's

    $this->data['template']['pageContent'] =
        $this->data['site']['modulePath'] . '/shop/views/brand.phtml';

    return;
}

$hasProductId = $this->shop->productID;
if ($hasProductId) {
    $this->data['shop']['productID'] = $this->shop->productID;

    $this->data['shop']['productQR'] = str_ends_with($this->osmium->validRequest, '/qr/');

    $loaded = $this->shop->model->product->loadByID($this->shop->productID);

    $isDraft = \is_array($loaded) && (int) ($loaded['is_published'] ?? 1) === 0;
    $adminSignedIn = \session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin_user_id']) && !empty($_SESSION['admin_login_time']) && empty($_SESSION['admin_locked']);
    if ($isDraft && !$adminSignedIn) { // A Draft is a 404 to everyone but a signed-in admin, who may preview it
        $this->osmium->header->set('HTTP/1.1 404 Not Found');
        $this->data['template']['pageContent'] = $this->data['site']['modulePath'] . '/error/views/index.phtml';

        return;
    }

    require $this->findModuleFile('shop', 'controllers/product-full.php');

    $this->data['template']['pageContent'] =
        $this->data['site']['modulePath'] . '/shop/views/product.phtml';

    return; // QR product page fix required...
}
