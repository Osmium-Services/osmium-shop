<?php

use Osmium\Modules\Checkout\Services\BasketCouponCheck;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\CheckoutCsrf;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutDiscount;
use Osmium\Modules\Checkout\Services\ShopDiscountService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Osmium\Core\Library\PaymentProviders;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Osmium\Modules\Shop\Models\ShopProduct;

require_once __DIR__ . '/../models/ShopOrderException.php';
require_once __DIR__ . '/../models/BasketCouponCheck.php';
require_once __DIR__ . '/../models/CheckoutBasketService.php';
require_once __DIR__ . '/../models/CheckoutCsrf.php';
require_once __DIR__ . '/../models/CheckoutModel.php';
require_once __DIR__ . '/../models/CheckoutBasket.php';
require_once __DIR__ . '/../models/CheckoutDiscount.php';
require_once __DIR__ . '/../models/ShopDiscountService.php';
require_once __DIR__ . '/../models/ShopTotalsService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app/modules/shop/models/ShopModel.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app/modules/shop/models/ShopProduct.php';

$input = $this->osmium->getJsonInput();
$action = $input['action'] ?? null;
$sessionId = \session_id();

if (!$sessionId) $this->osmium->jsonError('Your session has expired. Please reload the page and try again.');

$tokenValid = CheckoutCsrf::isValid(submitted: $input['csrf_token'] ?? '', session: $_SESSION['token'] ?? '');

$isReadOnlyAction = $action === 'get';
if (!$tokenValid && !$isReadOnlyAction) $this->osmium->jsonError('Your session has expired. Please reload the page and try again.');

$baskets = new CheckoutBasketService(new CheckoutBasket($this->osmium->dataSource, 'checkout_baskets'));

$paymentProvider = PaymentProviders::configured($this->osmium->config->checkout, PaymentProviders::external($this->osmium));
$discountsSupported = PaymentProviders::supportsDiscounts($paymentProvider);
$discountService = $discountsSupported
    ? new ShopDiscountService(new CheckoutDiscount($this->osmium->dataSource, 'discounts'))
    : null;

$totalsService = ShopTotalsService::fromConfig($this->osmium->config->checkout, $this->osmium->finance(), discounts: $discountService);

/**
 * Price the basket and attach a thumbnail to every line. Shared by every
 * action below so the basket page and the checkout order summary — both of
 * which fetch through this same 'get' response — always read identical
 * figures, per ShopTotalsService's single-source-of-truth design.
 */
$priceAndDecorate = function (array $lines) use ($totalsService, $discountsSupported): array {
    $couponCode = $_SESSION['applied_coupon_code'] ?? null;
    $product = new ShopProduct(database: $this->osmium->dataSource, tableName: 'products');
    $imagesByProductId = [];

    foreach ($lines as &$line) {
        $productId = (int)$line['product_id'];

        $imageNotCached = !\array_key_exists($productId, $imagesByProductId);
        if ($imageNotCached) {
            $loaded = $product->loadByID($productId);
            $imagesByProductId[$productId] = $loaded
                ? [
                    'thumbImage' => $this->osmium->imagePath($product->imagePath('medium')),
                    'thumbImageRetina' => $this->osmium->imagePath($product->imagePath('medium', '', '@2x')),
                ]
                : ['thumbImage' => null, 'thumbImageRetina' => null];
        }

        $line['thumbImage'] = $imagesByProductId[$productId]['thumbImage'];
        $line['thumbImageRetina'] = $imagesByProductId[$productId]['thumbImageRetina'];
    }
    unset($line);

    return ['discounts_enabled' => $discountsSupported, 'currency' => $this->osmium->finance()->currencyCode()] + $totalsService->calculate(lines: $lines, couponCode: $couponCode);
};

try {
    switch ($action) {
        case 'get':
            $basketId = $baskets->getOrCreateBasket($sessionId);
            $this->osmium->jsonSuccess(['success' => true, 'basket_id' => $basketId] + $priceAndDecorate($baskets->getLines($basketId)['lines']));

        case 'add':
            $productId = (int)($input['product_id'] ?? 0);
            $quantity = \max(1, (int)($input['quantity'] ?? 1));
            $productOptions = $input['product_options'] ?? null;
            $optionValueIds = \array_map('intval', (array)($input['option_value_ids'] ?? []));

            $basketId = $baskets->getOrCreateBasket($sessionId);
            $baskets->addLine(basketId: $basketId, productId: $productId, quantity: $quantity, productOptions: $productOptions, optionValueIds: $optionValueIds);

            $this->osmium->jsonSuccess(['success' => true, 'basket_id' => $basketId] + $priceAndDecorate($baskets->getLines($basketId)['lines']));

        case 'update_quantity':
            $basketId = $baskets->getOrCreateBasket($sessionId);
            $itemId = (int)($input['item_id'] ?? 0);
            $quantity = (int)($input['quantity'] ?? 0);

            $baskets->updateQuantity(basketId: $basketId, itemId: $itemId, quantity: $quantity);

            $this->osmium->jsonSuccess(['success' => true] + $priceAndDecorate($baskets->getLines($basketId)['lines']));

        case 'remove':
            $basketId = $baskets->getOrCreateBasket($sessionId);
            $itemId = (int)($input['item_id'] ?? 0);

            $baskets->removeLine(basketId: $basketId, itemId: $itemId);

            $this->osmium->jsonSuccess(['success' => true] + $priceAndDecorate($baskets->getLines($basketId)['lines']));

        case 'apply_coupon':
            $code = \trim((string)($input['code'] ?? ''));
            $lookup = $discountService === null ? null : fn(string $c) => $discountService->activeCouponByCode($c);
            $couponError = BasketCouponCheck::error(code: $code, lookup: $lookup);
            if ($couponError) $this->osmium->jsonError($couponError);

            $basketId = $baskets->getOrCreateBasket($sessionId);

            $preview = $totalsService->calculate(lines: $baskets->getLines($basketId)['lines'], couponCode: $code);
            $belowMinimum = $preview['coupon_notice'] !== null;
            if ($belowMinimum) $this->osmium->jsonError($preview['coupon_notice']['message']); // Not remembered: the customer adds more, then enters it again

            $_SESSION['applied_coupon_code'] = $code;

            $this->osmium->jsonSuccess(['success' => true] + $priceAndDecorate($baskets->getLines($basketId)['lines']));

        case 'remove_coupon':
            unset($_SESSION['applied_coupon_code']);

            $basketId = $baskets->getOrCreateBasket($sessionId);
            $this->osmium->jsonSuccess(['success' => true] + $priceAndDecorate($baskets->getLines($basketId)['lines']));

        default:
            $this->osmium->jsonError('Unknown action');
    }
} catch (ShopOrderException $e) {
    $this->osmium->jsonError($e->getMessage());
} catch (\Exception $e) {
    \error_log('Checkout basket action failed: ' . $e->getMessage());
    $this->osmium->jsonError('Sorry, something went wrong. Please try again.', 500);
}
