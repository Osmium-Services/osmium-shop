<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Core\Library\Input;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\AdminPages;
use Osmium\Modules\Admin\Services\ProductEditView;
use Osmium\Modules\Admin\Services\ProductFormData;
use Osmium\Modules\Admin\Services\ProductListView;
use Osmium\Modules\Admin\Services\AdminShop;
use Osmium\Modules\Admin\Services\ShopPaths;
use Osmium\Modules\Admin\Services\ShopAdminServices;
use Osmium\Modules\Admin\Services\ProductPriceCsv;
use Osmium\Core\Models\Model;
use Osmium\Core\Library\TechspecParser;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Shop\Services\ShopProductFaq;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');
require_once __DIR__ . '/../models/ProductEditView.php';
require_once __DIR__ . '/../models/ProductFormData.php';
require_once __DIR__ . '/../models/ProductListView.php';
require_once __DIR__ . '/../models/ProductPriceCsv.php';
require_once __DIR__ . '/../modules/shop/services/ShopProductFaq.php';

/**
 * Products controller - manages product catalog (CRUD, import/export).
 *
 * Routes:
 *   - index()  → /admin/shop/products/
 *   - add()    → /admin/shop/products/add/
 *   - edit()   → /admin/shop/products/edit/
 *   - action() → /admin/shop/products/action/ (JSON)
 *   - export() → /admin/shop/products/export/ (CSV download)
 *   - import() → /admin/shop/products/import/
 */
class ProductsController extends AdminController
{
    private function shopAdmin(): AdminShop
    {
        return ShopAdminServices::shop($this->admin, (bool)($this->osmium->config->site->urlTrailingSlash ?? false));
    }

    private \OsmiumShop $shop;
    private ProductFormData $formData;

    public function __construct(Osmium $osmium, OsmiumAdmin $admin, array &$data)
    {
        parent::__construct($osmium, $admin, $data);
        $this->shop = new \OsmiumShop($this->osmium);
        $this->formData = new ProductFormData(osmium: $this->osmium, admin: $this->admin);
    }

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $categories = $this->fetchCategoriesForFilter();
        $selectedCategory = $this->getSelectedCategoryFilter();
        $totalProductCount = $this->shop->model->product->count();
        $pagesById = Model::pagesById();

        $products = $this->fetchActiveProducts(selectedCategory: $selectedCategory, pagesById: $pagesById);
        $recentProducts = $this->fetchRecentProducts($pagesById);
        $deletedProducts = $this->fetchDeletedProducts();
        $activeEditors = $this->admin->presence->list(
            currentUserId: $this->admin->auth->getCurrentUserId() ?? 0,
            recordType: 'product',
        );

        $taxRate = $this->data['shop']['taxRate'] ?? 20;
        $formattedProducts = [];
        foreach ($products as $product) {
            $formattedProducts[] = ProductListView::product(product: $product, taxRate: $taxRate);
        }

        $formattedRecentProducts = [];
        foreach ($recentProducts as $product) {
            $formattedRecentProducts[] = ProductListView::product(product: $product, taxRate: $taxRate);
        }

        $formattedDeletedProducts = [];
        foreach ($deletedProducts as $product) {
            $formattedDeletedProducts[] = ProductListView::deletedProduct(product: $product);
        }

        $this->data['admin']['products'] = $formattedProducts;
        $this->data['admin']['recentProducts'] = $formattedRecentProducts;
        $this->data['admin']['deletedProducts'] = $formattedDeletedProducts;
        $this->data['admin']['categories'] = $categories;
        $this->data['admin']['selectedCategory'] = $selectedCategory;
        $this->data['admin']['totalProductCount'] = $totalProductCount;
        $this->data['admin']['activeEditors'] = $activeEditors;
        $this->data['admin']['lists'] = $this->loadListDefinitions('products', __DIR__ . '/../lists');
        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->setView('products/index.phtml');
    }

    public function add(): void
    {
        unset($_SESSION['qs']);
        $isAddPost = $this->isPost();
        if ($isAddPost) $this->handleAddSubmit();

        $this->prepareAddFormData();
        $this->data['admin']['isAddMode'] = true;
        $this->data['admin']['productError'] = $_SESSION['product_error'] ?? ''; // Flash set by handleAddSubmit()
        unset($_SESSION['product_error']);
        $this->data['admin']['addConfig'] = ['taxRate' => $this->data['shop']['taxRate']]; // window.productAddConfig
        $this->setView('products/add.phtml');
    }

    public function edit(): void
    {
        $productId = (int)$this->osmium->getStashedParam(key: 'id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $isEditPost = $this->isPost();
        if ($isEditPost) $this->handleEditSubmit($productId);
        $noProductId = !$productId;
        if ($noProductId) $this->redirect('shop/products/');

        $productData = $this->shop->model->product->loadByID($productId);
        $productNotFound = !$productData;
        if ($productNotFound) $this->redirect('shop/products/');

        $this->admin->presence->registerCurrentUser(recordType: 'product', recordId: $productId);
        $this->prepareEditFormData(productId: $productId, productData: $productData);
        $this->setView('products/edit.phtml');
    }

    public function action()
    {
        \ini_set(option: 'display_errors', value: '0');
        \header('Content-Type: application/json');
        $notPost = !$this->isPost();
        if ($notPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $hasFiles = !empty($_FILES);
        $hasPostAction = Input::post()->string(key: 'action') !== '';
        $isFormData = $hasFiles || $hasPostAction;
        if ($isFormData) $input = $_POST;

        $productId = (int)($input['id'] ?? 0);
        $action = $input['action'] ?? '';

        $presenceHandled = $this->admin->presence->handleAction(action: $action, input: $input, recordType: 'product');
        if ($presenceHandled) exit;

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        $csrfFailed = !$csrfValid;
        if ($csrfFailed) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }

        $noProductIdActions = ['pdf_upload', 'pdf_delete', 'pdf_get', 'get_technical'];
        $isNoProductIdAction = \in_array(needle: $action, haystack: $noProductIdActions);
        if ($isNoProductIdAction) {
            $this->handleNoProductIdAction(action: $action, input: $input);
        }

        $missingParams = !$productId || !$action;
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        try {
            $this->handleProductAction(action: $action, productId: $productId);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    public function export()
    {
        \header('Content-Type: text/csv; charset=utf-8');
        \header('Content-Disposition: attachment; filename="products-export-' . \date('Y-m-d') . '.csv"');

        $output = \fopen(filename: 'php://output', mode: 'w');
        (new ProductPriceCsv($this->admin))->export($output);
        \fclose($output);
        exit;
    }

    public function import(): void
    {
        $notAdmin = !$this->admin->auth->isAdmin();
        if ($notAdmin) $this->redirect('shop/products/');

        $results = null;
        $isImportPost = $this->isPost();
        $hasCsvFile = isset($_FILES['csv_file']);
        $shouldProcessImport = $isImportPost && $hasCsvFile;
        if ($shouldProcessImport) {
            $results = (new ProductPriceCsv($this->admin))->import($_FILES['csv_file']);
        }

        $this->data['admin']['importResults'] = $results;
        $this->setView('products/import.phtml');
    }

    // =========================================================================
    // Index - Data Fetching
    // =========================================================================

    private function fetchCategoriesForFilter(): array
    {
        return $this->admin->model->productCategory->getForFilter();
    }

    private function getSelectedCategoryFilter(): ?string
    {
        $selectedCategory = Input::get()->nullableString(key: 'category');
        $noCategory = !$selectedCategory;
        $hasQs = isset($_SESSION['qs']);
        $noCategoryWithQs = $noCategory && $hasQs;
        if ($noCategoryWithQs) {
            \parse_str(string: $_SESSION['qs'], result: $qsParams);
            $selectedCategory = $qsParams['category'] ?? null;
            unset($_SESSION['qs']);
        }
        return $selectedCategory;
    }

    private function fetchActiveProducts(?string $selectedCategory, array $pagesById): array
    {
        $productsRaw = $selectedCategory
            ? $this->admin->model->product->getActiveByCategory((int)$selectedCategory)
            : $this->admin->model->product->getActive();

        $products = [];
        foreach ($productsRaw as $row) {
            $entry = $this->buildProductEntry(row: $row, pagesById: $pagesById);
            if ($entry) $products[] = $entry;
        }
        return $products;
    }

    private function buildProductEntry(array $row, array $pagesById): ?array
    {
        $page = $pagesById[$row['page_id']] ?? null;
        $noPage = !$page;
        $pageDeleted = $page && $page['deleted_at'] !== null;
        $skipEntry = $noPage || $pageDeleted;
        if ($skipEntry) return null;

        $brandPath = ShopPaths::brand($row['brand_name']);
        $modelPath = ShopPaths::model($row['model_name']);
        $basePath = '/shop/' . $brandPath . '/' . $modelPath . '/';
        $fileName = $brandPath . '-' . $modelPath;

        $thumbnailPath = $this->osmium->imagePath($basePath . 'thumb/' . $fileName);
        $thumbnailError = \str_contains(haystack: $thumbnailPath, needle: 'data-error');
        if ($thumbnailError) {
            $thumbnailPath = $this->osmium->imagePath($basePath . 'medium/' . $fileName);
        }
        $stillMissing = \str_contains(haystack: $thumbnailPath, needle: 'data-error');
        if ($stillMissing) $thumbnailPath = '';

        return [
            'id' => $row['id'],
            'reference' => $row['reference'],
            'title' => $row['brand_name'] . ' ' . $row['model_name'],
            'brand' => $row['brand_name'],
            'model' => $row['model_name'],
            'price' => $row['price_exc_tax'],
            'rrp' => $row['rrp_exc_tax'],
            'status' => $row['status_name'],
            'available' => $row['available'],
            'has_category' => $row['has_category'] ?? 0,
            'url' => $page['url'],
            'robots' => $page['robots'] ?? 'index,follow',
            'created' => $row['created_at'],
            'thumbnail' => $thumbnailPath,
        ];
    }

    private function fetchRecentProducts(array $pagesById): array
    {
        $recentProducts = $this->admin->model->product->getRecent();

        foreach ($recentProducts as &$product) {
            $page = $pagesById[$product['page_id']] ?? null;
            $product['url'] = $page['url'] ?? null;
            $product['robots'] = $page['robots'] ?? 'index,follow';
        }
        unset($product);
        return $recentProducts;
    }

    private function fetchDeletedProducts(): array
    {
        return $this->admin->model->product->getDeleted();
    }

    // =========================================================================
    // Add Form
    // =========================================================================

    /** The pages service; a method so a test can stand in for the routes file it rewrites. */
    protected function pagesService(): AdminPages
    {
        return new AdminPages(page: $this->admin->model->page, urlTrailingSlash: $this->osmium->config->site->urlTrailingSlash ?? false);
    }

    private function handleAddSubmit()
    {
        try {
            $newProductId = $this->insertProduct();
            $_SESSION['product_saved'] = true;
            $this->redirect('shop/products/edit/?id=' . $newProductId);
        } catch (\Exception $e) {
            $_SESSION['product_error'] = $e->getMessage();
            $this->redirect('shop/products/add/');
        }
    }

    private function insertProduct(): int
    {
        require_once 'app/core/admin/services/AdminPages.php';

        $brandId = Input::post()->int(key: 'brand_id');
        $modelName = Input::post()->string(key: 'model_name');
        $statusId = Input::post()->int(key: 'status_id');
        $categoryId = Input::post()->int(key: 'category_id');
        $manualParentPageId = Input::post()->int(key: 'parent_page_id');

        $brandName = $this->getBrandName($brandId);
        $urlSlug = $this->generateUrlSlug(brandName: $brandName, modelName: $modelName);
        $parentPageId = $this->resolveParentPageId(categoryId: $categoryId, manualParentPageId: $manualParentPageId);

        $pagesService = $this->pagesService();
        $newPageId = $pagesService->create([
            'url' => $urlSlug,
            'title' => $modelName,
            'description' => '',
            'robots' => 'index,follow',
            'parent_page_id' => $parentPageId,
        ]);

        $price = Input::post()->float(key: 'price_exc_tax');
        $newProductId = $this->insertProductRecord(brandId: $brandId, modelName: $modelName, statusId: $statusId, price: $price);
        $this->linkProductToPage(productId: $newProductId, pageId: $newPageId);
        if ($categoryId) $this->assignCategory(productId: $newProductId, categoryId: $categoryId);

        $this->admin->model->changelog->log(description: "Created product: {$modelName}", recordType: 'product', recordId: $newProductId);
        $this->dispatchProductEvent($newProductId);
        return $newProductId;
    }

    private function getBrandName(int $brandId): string
    {
        return $this->admin->model->brand->getName($brandId);
    }

    private function generateUrlSlug(string $brandName, string $modelName): string
    {
        $isHouseBrand = \strtolower($brandName) === \strtolower($this->osmium->config->company->brandName);
        $slugBase = $isHouseBrand ? $modelName : $brandName . '-' . $modelName;
        $urlSlug = \strtolower($slugBase);
        $urlSlug = \preg_replace(pattern: '/[^a-z0-9]+/', replacement: '-', subject: $urlSlug);
        return \trim(string: $urlSlug, characters: '-');
    }

    private function resolveParentPageId(int $categoryId, int $manualParentPageId): ?int
    {
        $hasManualParent = $manualParentPageId > 0;
        if ($hasManualParent) return $manualParentPageId;
        $hasCategory = $categoryId > 0;
        if ($hasCategory) return $this->admin->model->productCategory->getPageId($categoryId);
        return null;
    }

    private function insertProductRecord(int $brandId, string $modelName, int $statusId, float $price): int
    {
        return $this->admin->model->product->insert([
            'brand_id' => $brandId,
            'model_name' => $modelName,
            'reference' => '',
            'barcode' => '',
            'status_id' => $statusId,
            'price_exc_tax' => $price,
            'rrp_exc_tax' => 0,
            'descr' => '',
            'descr2' => '',
            'descr3' => '',
        ]);
    }

    private function linkProductToPage(int $productId, int $pageId): void
    {
        $this->admin->model->product->linkToPage($productId, $pageId);
    }

    private function assignCategory(int $productId, int $categoryId): void
    {
        $this->admin->model->productCategoryAssignment->insert($productId, $categoryId);
    }

    private function prepareAddFormData(): void
    {
        require_once 'app/core/admin/services/AdminPages.php';
        $this->data['admin']['brands'] = $this->formData->fetchBrands();
        $this->data['admin']['statuses'] = $this->formData->fetchStatuses();
        $this->data['admin']['allCategories'] = $this->formData->fetchCategoriesWithPaths();
        $this->data['admin']['parentPages'] = $this->formData->fetchParentPages();
    }

    // =========================================================================
    // Edit Form
    // =========================================================================

    private function handleEditSubmit(int $productId)
    {
        $csrfPassed = $this->admin->auth->validateCsrf();
        $csrfInvalid = !$csrfPassed;
        if ($csrfInvalid) {
            $this->data['admin']['error'] = 'Invalid form submission. Please try again.';
            $this->redirect('shop/products/edit/?id=' . $productId);
        }

        $productId = $this->updateProduct();
        $editSucceeded = (bool)$productId;
        if ($editSucceeded) {
            $_SESSION['product_saved'] = true;
            $this->redirect('shop/products/edit/?id=' . $productId);
        }
        $this->redirect('shop/products/');
    }

    private function updateProduct(): int
    {
        $productId = Input::post()->int(key: 'id');
        $noUpdateId = !$productId;
        if ($noUpdateId) return 0;

        $this->logPriceChange($productId);
        $this->handleModelNameChange($productId); // Before the update: it compares the stored name with the posted one
        $this->updateProductRecord($productId);
        $this->updateProductPage($productId);

        $productName = Input::post()->stringOr(key: 'model_name', fallback: "Product #{$productId}");
        $this->admin->model->changelog->log(description: "Updated product: {$productName}", recordType: 'product', recordId: $productId);
        $this->dispatchProductEvent($productId);

        return $productId;
    }

    private function logPriceChange(int $productId): void
    {
        $currentPrices = $this->admin->model->product->getPrices($productId);

        $oldPrice = $currentPrices ? (float)$currentPrices['price_exc_tax'] : 0;
        $oldRrp = $currentPrices ? (float)$currentPrices['rrp_exc_tax'] : 0.0;
        $newPrice = Input::post()->float(key: 'price_exc_tax');
        $newRrp = Input::post()->float(key: 'rrp_exc_tax'); // Blank means no RRP, which is stored as 0 so a blank field is not a change

        $priceChanged = \abs($oldPrice - $newPrice) > 0.001;
        $rrpChanged = $oldRrp !== $newRrp;

        $priceOrRrpChanged = $priceChanged || $rrpChanged;
        if ($priceOrRrpChanged) {
            $this->admin->model->productPriceHistory->insertManual(
                productId: $productId,
                oldPrice: $oldPrice,
                newPrice: $newPrice,
                oldRrp: $oldRrp,
                newRrp: $newRrp,
                userId: $this->admin->auth->getCurrentUser()['id'],
            );
        }
    }

    private function updateProductRecord(int $productId): void
    {
        $techspec = Input::post()->string(key: 'techspec');
        $this->admin->model->product->update([
            'id' => $productId,
            'brand_id' => Input::post()->int(key: 'brand_id'),
            'model_name' => Input::post()->string(key: 'model_name'),
            'reference' => Input::post()->string(key: 'reference'),
            'barcode' => Input::post()->string(key: 'barcode'),
            'status_id' => Input::post()->int(key: 'status_id'),
            'restock_at' => Input::post()->nullableString(key: 'restock_at'),
            'price_exc_tax' => Input::post()->float(key: 'price_exc_tax'),
            'rrp_exc_tax' => Input::post()->float(key: 'rrp_exc_tax'), // The column is NOT NULL: no RRP is 0
            'custom_offer' => Input::post()->string(key: 'custom_offer'),
            'descr' => Input::post()->string(key: 'descr'),
            'descr2' => Input::post()->string(key: 'descr2'),
            'descr3' => Input::post()->string(key: 'descr3'),
            'video_url' => Input::post()->string(key: 'video_url'),
            'techspec' => $techspec,
            'techspec_json' => $this->techspecJson($techspec),
            'faq' => Input::post()->string(key: 'faq'),
            'faq_list' => Input::post()->nullableString(key: 'faq_list'),
            'packed_weight' => Input::post()->float(key: 'packed_weight'),
            'option_label' => Input::post()->string(key: 'option_label'),
            'option_default' => Input::post()->string(key: 'option_default'),
            'kit_id' => Input::post()->nullableInt(key: 'kit_id'),
        ]);
    }

    /**
     * Derived machine-readable copy of the techspec table for techspec_json.
     *
     * techspec stays authoritative and stays the editing format - this is only
     * ever regenerated from it, never edited by hand. NULL when the editor holds
     * no parseable table (placeholder copy, an empty <p><br></p>), which is what
     * the front end falls back on.
     */
    private function techspecJson(string $techspec): ?string
    {
        $parser = new TechspecParser();
        $sections = $parser->parse($techspec);

        $hasSections = $sections !== [];
        if (!$hasSections) return null;

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return \json_encode($sections, $flags) ?: null;
    }

    private function handleModelNameChange(int $productId): void
    {
        $currentNames = $this->admin->model->product->getBrandModel($productId);

        $oldModelName = $currentNames['model_name'] ?? '';
        $oldBrandName = $currentNames['brand_name'] ?? '';
        $newModelName = Input::post()->string(key: 'model_name');

        $modelNameChanged = $oldModelName !== '' && $newModelName !== '' && $oldModelName !== $newModelName;
        if ($modelNameChanged) {
            $renameResult = ShopAdminServices::images($this->admin)->renameProductImages(
                brandName: $oldBrandName,
                oldModelName: $oldModelName,
                newModelName: $newModelName,
            );
            $renameFailed = !$renameResult['success'];
            if ($renameFailed) {
                \error_log("Image rename failed for product {$productId}: " . ($renameResult['error'] ?? 'Unknown'));
            }
        }
    }

    private function updateProductPage(int $productId): void
    {
        $pageId = Input::post()->int(key: 'page_id');
        $hasPageFields = Input::post()->has(key: 'page_url') || Input::post()->has(key: 'page_title');

        $canUpdatePage = $pageId && $hasPageFields;
        if ($canUpdatePage) {
            try {
                require_once 'app/core/admin/services/AdminPages.php';
                $adminPages = $this->pagesService();
                $adminPages->update(id: $pageId, input: [
                    'url' => Input::post()->string(key: 'page_url'),
                    'title' => Input::post()->string(key: 'page_title'),
                    'description' => Input::post()->string(key: 'page_description'),
                    'robots' => Input::post()->string(key: 'page_robots', default: 'index,follow'),
                    'parent_page_id' => Input::post()->nullableInt(key: 'parent_page_id'),
                ]);
            } catch (\Exception $e) {
                \error_log("Page update failed for product {$productId}: " . $e->getMessage());
            }
        }
    }

    private function prepareEditFormData(int $productId, array $productData): void
    {
        require_once __DIR__ . '/../models/ImageProcessor.php';

        $product = $this->shop->model->product;
        $brands = $this->formData->fetchBrands();
        $statuses = $this->formData->fetchStatuses();
        $allProducts = $this->formData->fetchAllProducts();
        $productCategories = $this->formData->fetchProductCategories($productId);
        $allCategories = $this->formData->fetchCategoriesWithPaths();
        $productPages = $this->formData->fetchProductPages($productId);
        $parentPages = $this->formData->fetchParentPages();

        $this->data['admin']['product'] = $productData;
        $this->data['admin']['productModel'] = $product;
        $this->data['admin']['productImage'] = $this->admin->cacheBustedImagePath(imagePath: $this->osmium->imagePath($product->imagePath('large')));
        $this->data['admin']['brands'] = $brands;
        $this->data['admin']['currentBrand'] = $this->formData->findById(items: $brands, targetId: (int)$productData['brand_id']);
        $this->data['admin']['statuses'] = $statuses;
        $this->data['admin']['currentStatus'] = $this->formData->findById(items: $statuses, targetId: (int)$productData['status_id']);
        $this->data['admin']['productSaved'] = $_SESSION['product_saved'] ?? false;
        unset($_SESSION['product_saved']);
        $this->data['admin']['brandPath'] = $product->brandPath;
        $this->data['admin']['modelPath'] = $product->modelPath;
        $this->data['admin']['canDeleteCompletely'] = \strtotime($productData['created_at']) > (\time() - AdminShop::PRODUCT_DELETE_GRACE_PERIOD);
        $this->data['admin']['allProducts'] = $allProducts;
        $this->data['admin']['kitId'] = (int)($productData['kit_id'] ?? 0);
        $this->data['admin']['currentKitProduct'] = $this->formData->findById(items: $allProducts, targetId: (int)($productData['kit_id'] ?? 0));
        $this->data['admin']['productCategories'] = $productCategories;
        $this->data['admin']['allCategories'] = $allCategories;
        $this->data['admin']['productPages'] = $productPages;
        $this->data['admin']['productPage'] = $this->formData->findPrimaryProductPage($productPages);
        $this->data['admin']['productId'] = $productId;
        $this->data['admin']['editConfig'] = [
            'productId' => $productId,
            'brandPath' => $product->brandPath ?? '',
            'modelPath' => $product->modelPath ?? '',
            'taxRate' => $this->data['shop']['taxRate'],
            'parentPageId' => $this->data['admin']['productPage']['parent_page_id'] ?? '',
        ]; // Read by the product-edit-*.js scripts as window.productEditConfig
        $this->data['admin']['parentPages'] = $parentPages;
        $this->data['admin']['productsWithOptions'] = ShopAdminServices::options($this->admin)->getProductsWithOptions();
        $this->data['admin']['productsWithTechspec'] = $this->formData->fetchProductsWithTechspec();
        $this->data['admin']['productsWithFaq'] = $this->formData->fetchProductsWithFaq();
        $this->data['admin']['faqListChoices'] = ShopProductFaq::choices($this->osmium->dataSource);
        $this->data['admin']['pdfPaths'] = $this->formData->buildPdfPaths($product);
        $this->data['admin']['imageUrls'] = $this->formData->buildImageUrls($product);
        $this->data['admin']['availableImageSlots'] = $this->formData->buildAvailableImageSlots($product);
        $this->data['admin']['imageDerivations'] = $this->formData->checkImageDerivations($product);
        $this->data['admin']['showRegenerate'] = $this->formData->shouldShowRegenerateButton($this->data['admin']['imageDerivations']);
        $this->data['admin']['edit'] = ProductEditView::build(
            admin: $this->data['admin'],
            imagesRoot: $_SERVER['DOCUMENT_ROOT'] . '/assets/images',
        );
    }

    // =========================================================================
    // Action Handlers
    // =========================================================================

    private function handleNoProductIdAction(string $action, array $input)
    {
        $notAuthenticated = !$this->admin->auth->getCurrentUser();
        if ($notAuthenticated) $this->admin->jsonError('Not authenticated');

        try {
            match ($action) {
                'pdf_upload' => $this->handlePdfUpload(),
                'pdf_delete' => $this->handlePdfDelete($input),
                'pdf_get' => $this->handlePdfGet($input),
                'get_technical' => $this->handleGetTechnical($input),
                default => $this->admin->jsonError('Unknown action'),
            };
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    private function handleProductAction(string $action, int $productId)
    {
        $productName = $this->getProductName($productId);

        match ($action) {
            'delete' => $this->deleteProduct(productId: $productId, productName: $productName),
            'restore' => $this->restoreProduct(productId: $productId, productName: $productName),
            'discontinue' => $this->discontinueProduct(productId: $productId, productName: $productName),
            'duplicate' => $this->duplicateProduct(productId: $productId, productName: $productName),
            'delete_completely' => $this->deleteProductCompletely(productId: $productId, productName: $productName),
            default => $this->admin->jsonError('Unknown action'),
        };
    }

    private function getProductName(int $productId): string
    {
        return $this->admin->model->product->getName($productId);
    }

    private function deleteProduct(int $productId, string $productName)
    {
        $this->shopAdmin()->softDelete($productId);
        $this->admin->model->changelog->log(description: "Deleted product: {$productName}", recordType: 'product', recordId: $productId);
        $this->dispatchProductEvent($productId, remove: true);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function restoreProduct(int $productId, string $productName)
    {
        $this->shopAdmin()->restore($productId);
        $this->admin->model->changelog->log(description: "Restored product: {$productName}", recordType: 'product', recordId: $productId);
        $this->dispatchProductEvent($productId);
        $this->admin->jsonSuccess(['success' => true, 'message' => 'Product restored. Remember to check the product status.']);
    }

    private function discontinueProduct(int $productId, string $productName)
    {
        $notAdminUser = !$this->admin->auth->isAdmin();
        if ($notAdminUser) $this->admin->jsonError('Permission denied');
        $this->shopAdmin()->discontinue($productId);
        $this->admin->model->changelog->log(description: "Discontinued product: {$productName}", recordType: 'product', recordId: $productId);
        $this->dispatchProductEvent($productId);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function duplicateProduct(int $productId, string $productName)
    {
        $newProductId = $this->shopAdmin()->duplicate($productId);
        $this->admin->model->changelog->log(description: "Duplicated product: {$productName} (new ID: {$newProductId})", recordType: 'product', recordId: $newProductId);
        $this->dispatchProductEvent($newProductId);
        $this->admin->jsonSuccess(['success' => true, 'newProductId' => $newProductId]);
    }

    private function deleteProductCompletely(int $productId, string $productName)
    {
        $this->shopAdmin()->deleteCompletely(productId: $productId, generateBrandPath: ShopPaths::brand(...), generateModelPath: ShopPaths::model(...));
        $this->admin->model->changelog->log(description: "Permanently deleted product: {$productName}", recordType: 'product', recordId: $productId);
        $this->dispatchProductEvent($productId, remove: true);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function handlePdfUpload()
    {
        $result = $input = Input::post();
        $result = $this->shopAdmin()->uploadPdf(
            file: $_FILES['pdf'] ?? [],
            pdfType: $input->string(key: 'pdf_type'),
            brandPath: $input->string(key: 'brand_path'),
            modelPath: $input->string(key: 'model_path'),
        );
        $this->admin->jsonSuccess(['success' => true] + $result);
    }

    private function handlePdfDelete(array $input)
    {
        $this->shopAdmin()->deletePdf(pdfType: $input['pdf_type'] ?? '', brandPath: $input['brand_path'] ?? '', modelPath: $input['model_path'] ?? '');
        $this->admin->jsonSuccess(['success' => true, 'pdfType' => $input['pdf_type'] ?? '']);
    }

    private function handlePdfGet(array $input)
    {
        $brandPath = $input['brand_path'] ?? '';
        $modelPath = $input['model_path'] ?? '';
        $brandEmpty = empty($brandPath);
        $modelEmpty = empty($modelPath);
        $missingPaths = $brandEmpty || $modelEmpty;
        if ($missingPaths) $this->admin->jsonError('Brand and model paths are required');
        $pdfPaths = $this->shopAdmin()->getPdfPaths(brandPath: $brandPath, modelPath: $modelPath);
        $this->admin->jsonSuccess(['success' => true, 'pdfPaths' => $pdfPaths]);
    }

    private function handleGetTechnical(array $input)
    {
        $sourceId = (int)($input['source_product_id'] ?? 0);
        $noSourceId = !$sourceId;
        if ($noSourceId) $this->admin->jsonError('Source product ID required');
        $data = $this->admin->model->product->getTechspec($sourceId) ?? ['techspec' => '', 'faq' => ''];
        $this->admin->jsonSuccess(['success' => true] + $data);
    }

    // =========================================================================
    // Shared Helpers
    // =========================================================================

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    // =========================================================================
    // Service Hooks
    // =========================================================================

    /**
     * Tells any installed service listening for product changes (e.g. Google
     * Merchant Center) that a product was saved or removed. Best-effort -
     * ServiceHooks logs and swallows handler failures so they never block or
     * fail the admin action.
     */
    private function dispatchProductEvent(int $productId, bool $remove = false): void
    {
        $event = $remove ? 'product.removed' : 'product.saved';
        $loader = new ServiceLoader(new ServiceModel($this->osmium->dataSource));

        (new ServiceHooks($loader))->dispatch(
            event: $event,
            payload: ['productId' => $productId, 'osmium' => $this->osmium, 'shop' => $this->shop],
        );
    }
}
