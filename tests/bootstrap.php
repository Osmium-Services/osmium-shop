<?php

declare(strict_types=1);

// Bootstrap for the Osmium Shop service's tests: core's bootstrap, then the shop classes the tests use.
// Run with: php vendor/phpunit.phar --bootstrap app/services/osmium-shop/tests/bootstrap.php app/services/osmium-shop/tests

require_once \dirname(__DIR__, 3) . '/core/tests/bootstrap.php';
require_once __DIR__ . '/../models/StoreFinance.php';

require_once __DIR__ . '/../models/ShopPageLinks.php';
require_once __DIR__ . '/../models/ShopDashboardLinks.php';
require_once __DIR__ . '/../models/TechspecParser.php';
require_once __DIR__ . '/../models/ShopSpecRebuild.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../modules/shop/models/ShopModel.php';
require_once __DIR__ . '/../models/CheckoutModel.php';
require_once __DIR__ . '/../models/CheckoutDiscount.php';
require_once __DIR__ . '/../models/CheckoutOrder.php';
require_once __DIR__ . '/../models/CheckoutBasket.php';
require_once __DIR__ . '/../models/Discount.php';
require_once __DIR__ . '/../modules/shop/models/ShopSearch.php';
require_once __DIR__ . '/../modules/shop/models/ShopProduct.php';
require_once __DIR__ . '/../modules/shop/models/ShopProductCategory.php';
require_once __DIR__ . '/../modules/shop/models/ShopProductOption.php';
require_once __DIR__ . '/../modules/shop/services/ShopProductService.php';
require_once __DIR__ . '/../modules/shop/services/ShopKitAvailability.php';
require_once __DIR__ . '/../modules/shop/services/ShopSearchService.php';
require_once __DIR__ . '/../models/CustomerNotifications.php';
require_once __DIR__ . '/../models/ShopPaymentException.php';
require_once __DIR__ . '/../models/ShopDiscountService.php';
require_once __DIR__ . '/../models/ShopTotalsService.php';
require_once __DIR__ . '/../models/ShopCheckoutValidator.php';
require_once __DIR__ . '/../models/ShopOrderMailer.php';
require_once __DIR__ . '/../models/ShopOrderException.php';
require_once __DIR__ . '/../models/CheckoutBasketService.php';
require_once __DIR__ . '/../models/CheckoutKitParts.php';
require_once __DIR__ . '/../models/ShopOrderService.php';
require_once __DIR__ . '/../models/ProductMcp.php';
require_once __DIR__ . '/../models/DiscountRules.php';
require_once __DIR__ . '/../models/DiscountMcp.php';
require_once __DIR__ . '/../models/ImageProcessor.php';
require_once __DIR__ . '/../models/AdminShopOptions.php';
require_once __DIR__ . '/../models/AdminShopImages.php';
require_once __DIR__ . '/../models/Brand.php'; // The shop service's brand model
require_once __DIR__ . '/../models/Basket.php';
require_once __DIR__ . '/../models/ProductCategory.php';
require_once __DIR__ . '/../models/ProductCategoryAssignment.php';
require_once __DIR__ . '/../models/ProductOptionType.php';
require_once __DIR__ . '/../models/ProductOptionValue.php';
require_once __DIR__ . '/../models/ProductPriceHistory.php';
require_once __DIR__ . '/../models/AdminShop.php';
require_once __DIR__ . '/../models/ShopAdminServices.php';
