<?php

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\AdminShopCategories;

use const Osmium\Config\PAGES;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Product Categories Controller
 *
 * Handles product category management with lock-based concurrent editing protection
 * Routes:
 * - index: Category tree view with lock status
 * - action: JSON API for CRUD operations, lock management, and product ordering
 */
class ProductCategoriesController extends AdminController
{
    private ?AdminShopCategories $categories = null;

    private function categories(): AdminShopCategories
    {
        return $this->categories ??= new AdminShopCategories(
            $this->admin->model->productCategory,
            $this->admin->pages,
            $this->osmium->strings,
        );
    }

    public function index(): void
    {
        $categories = $this->loadCategoryTree();
        $allCategories = $this->buildFlatCategoryList($categories);
        $deletedCategories = $this->loadDeletedCategories();
        $categoryUrls = $this->buildCategoryUrlMap();
        $lockStatus = $this->getLockStatus();

        $this->data['admin']['categoryTree'] = $categories;
        $this->data['admin']['allCategories'] = $allCategories;
        $this->data['admin']['deletedCategories'] = $deletedCategories;
        $this->data['admin']['categoryUrls'] = $categoryUrls;
        $this->data['admin']['currentUserId'] = $this->admin->auth->getCurrentUserId();
        $this->data['admin']['currentUserName'] = $this->admin->auth->getCurrentUser()['username'] ?? 'Unknown';
        $this->data['admin']['lockedByOther'] = $lockStatus['lockedByOther'];
        $this->data['admin']['hasOwnBackup'] = $lockStatus['hasOwnBackup'];
        $this->data['admin']['lockInfo'] = $lockStatus['lockInfo'];

        $this->setView('product-categories/index.phtml');
    }

    public function action(): void
    {
        \header('Content-Type: application/json');

        $currentUserId = $this->admin->auth->getCurrentUserId();
        $currentUserName = $this->admin->auth->getCurrentUser()['username'] ?? 'Unknown';

        $input = $this->admin->getJsonInput();
        $action = $input['action'] ?? '';

        $this->validateCsrfForAction($action, $input);
        $this->categories()->cleanupExpiredLocks();

        try {
            match ($action) {
                'get_categories' => $this->actionGetCategories(),
                'check_lock' => $this->actionCheckLock($currentUserId),
                'acquire_lock' => $this->actionAcquireLock(
                    userId: $currentUserId,
                    userName: $currentUserName,
                ),
                'release_lock' => $this->actionReleaseLock($currentUserId),
                'restore_backup' => $this->actionRestoreBackup($currentUserId),
                'add' => $this->actionAdd(
                    userId: $currentUserId,
                    userName: $currentUserName,
                    input: $input,
                ),
                'update' => $this->actionUpdate(
                    userId: $currentUserId,
                    userName: $currentUserName,
                    input: $input,
                ),
                'delete' => $this->actionDelete(
                    userId: $currentUserId,
                    userName: $currentUserName,
                    input: $input,
                ),
                'restore' => $this->actionRestore(
                    userId: $currentUserId,
                    userName: $currentUserName,
                    input: $input,
                ),
                'reorder' => $this->actionReorder(
                    userId: $currentUserId,
                    userName: $currentUserName,
                    input: $input,
                ),
                'permanent_delete' => $this->actionPermanentDelete(
                    userId: $currentUserId,
                    userName: $currentUserName,
                    input: $input,
                ),
                'get_category_products' => $this->actionGetCategoryProducts(
                    categoryId: (int)($input['category_id'] ?? 0),
                ),
                'reorder_products' => $this->actionReorderProducts($input),
                default => throw new \Exception('Invalid action'),
            };
        } catch (\Exception $e) {
            \http_response_code(400);
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =====================================================================
    // INDEX ROUTE HELPERS
    // =====================================================================

    private function loadCategoryTree(): array
    {
        $categoriesRaw = $this->admin->model->productCategory->getAll();

        $categoriesByParent = [];
        foreach ($categoriesRaw as $category) {
            $parentId = $category['parent_id'] ?? 'root';
            $categoriesByParent[$parentId][] = $category;
        }

        return $this->buildCategoryTreeRecursive(
            categoriesByParent: $categoriesByParent,
            parentId: 'root',
        );
    }

    private function buildCategoryTreeRecursive(
        array $categoriesByParent,
        string|int $parentId,
    ): array {
        $tree = [];
        foreach ($categoriesByParent[$parentId] ?? [] as $category) {
            $category['children'] = $this->buildCategoryTreeRecursive(
                categoriesByParent: $categoriesByParent,
                parentId: $category['id'],
            );
            $tree[] = $category;
        }
        return $tree;
    }

    private function buildFlatCategoryList(array $categoriesTree): array
    {
        $categoriesRaw = $this->admin->model->productCategory->getAll();

        $allCategories = [];
        foreach ($categoriesRaw as $cat) {
            $allCategories[] = [
                'id' => $cat['id'],
                'label' => $cat['label'],
                'parent_id' => $cat['parent_id']
            ];
        }
        return $allCategories;
    }

    private function loadDeletedCategories(): array
    {
        $deletedCategories = $this->admin->model->productCategory->getDeleted();

        $formatted = [];
        foreach ($deletedCategories as $cat) {
            $formatted[] = $this->formatDeletedCategory($cat);
        }
        return $formatted;
    }

    private function buildCategoryUrlMap(): array
    {
        $categoryPagesRaw = $this->admin->model->productCategory->getPageLinks();

        $pageIdToUrl = [];
        foreach (PAGES as $url => $page) {
            $pageIdToUrl[$page['id']] = '/' . $page['url'];
        }

        $categoryUrls = [];
        foreach ($categoryPagesRaw as $cp) {
            $hasUrl = isset($pageIdToUrl[$cp['page_id']]);
            if ($hasUrl) $categoryUrls[$cp['productcategory_id']] = $pageIdToUrl[$cp['page_id']];
        }

        return $categoryUrls;
    }

    private function getLockStatus(): array
    {
        $this->cleanupExpiredLocks();

        $activeBackup = $this->admin->model->productCategory->getLatestLock();

        $currentUserId = $this->admin->auth->getCurrentUserId();
        $hasOwnBackup = $activeBackup && $activeBackup['user_id'] == $currentUserId;
        $lockedByOther = $activeBackup && $activeBackup['user_id'] != $currentUserId;
        $lockInfo = null;

        if ($lockedByOther) {
            $lockedUser = $this->admin->model->user->getAvatar($activeBackup['user_id']);

            $lockInfo = $this->formatLockInfo(
                userName: $activeBackup['user_name'],
                avatar: $lockedUser['avatar'] ?? null,
                createdAt: $activeBackup['created_at'],
            );
        }

        return [
            'hasOwnBackup' => $hasOwnBackup,
            'lockedByOther' => $lockedByOther,
            'lockInfo' => $lockInfo,
        ];
    }

    // =====================================================================
    // FORMATTING HELPERS
    // =====================================================================

    private function formatLockInfo(string $userName, ?string $avatar, string $createdAt): array
    {
        $avatarPath = $this->data['admin']['avatarPath'] . ($avatar ?? '');
        $avatarExists = $avatar && \file_exists($avatarPath);

        $initials = null;
        if (!$avatarExists) {
            $parts = \explode(separator: ' ', string: $userName);
            $firstInitial = \strtoupper($parts[0][0] ?? '');
            $secondInitial = \strtoupper($parts[1][0] ?? '');
            $initials = $firstInitial . $secondInitial;
        }

        $timeFormatted = \date(format: 'g:ia', timestamp: \strtotime($createdAt));

        return [
            'user_name' => $userName,
            'avatar' => $avatar,
            'avatar_exists' => $avatarExists,
            'initials' => $initials,
            'created_at' => $createdAt,
            'time_formatted' => $timeFormatted,
        ];
    }

    private function formatDeletedCategory(array $cat): array
    {
        $deletedFormatted = \date(
            format: 'j M Y, g:ia',
            timestamp: \strtotime($cat['deleted_at']),
        );

        return [
            'id' => $cat['id'],
            'label' => $cat['label'] ?? '',
            'deleted_at' => $cat['deleted_at'],
            'deleted_formatted' => $deletedFormatted,
        ];
    }

    private function cleanupExpiredLocks(): void
    {
        $expiredLocks = $this->admin->model->productCategory->getExpiredLocks();

        $noExpiredLocks = empty($expiredLocks);
        if ($noExpiredLocks) return;

        $this->admin->model->productCategory->deleteExpiredLocks();
        $this->admin->model->productCategory->truncateSnapshot();
        $this->admin->model->productCategory->truncateAssocSnapshot();
    }

    // =====================================================================
    // ACTION ROUTE HANDLERS
    // =====================================================================

    private function validateCsrfForAction(string $action, array $input): void
    {
        $lockActions = [
            'check_lock',
            'acquire_lock',
            'release_lock',
            'heartbeat',
            'get_categories',
            'get_category_products'
        ];

        $needsCsrf = !\in_array(needle: $action, haystack: $lockActions);
        $csrfInvalid = $needsCsrf && !$this->admin->auth->validateCsrfJson($input);

        if ($csrfInvalid) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }
    }

    private function actionGetCategories(): void
    {
        $categories = $this->categories()->getAll();
        $this->admin->jsonSuccess(['success' => true, 'categories' => $categories]);
    }

    private function actionCheckLock(int $userId): void
    {
        $result = $this->categories()->checkLock($userId);
        $this->admin->jsonSuccess(['success' => true] + $result);
    }

    private function actionAcquireLock(int $userId, string $userName): void
    {
        $result = $this->categories()->acquireLock(
            userId: $userId,
            userName: $userName,
        );
        $this->admin->jsonSuccess(['success' => true] + $result);
    }

    private function actionReleaseLock(int $userId): void
    {
        $this->categories()->releaseLock($userId);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionRestoreBackup(int $userId): void
    {
        $this->categories()->restoreBackup($userId);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionAdd(int $userId, string $userName, array $input): void
    {
        $newId = $this->categories()->add(
            userId: $userId,
            userName: $userName,
            input: $input,
        );
        $catName = \trim($input['label'] ?? '');
        $this->admin->model->changelog->log(
            description: "Created category: {$catName}",
            recordType: 'category',
            recordId: $newId,
        );
        $this->admin->jsonSuccess(['success' => true, 'id' => $newId]);
    }

    private function actionUpdate(int $userId, string $userName, array $input): void
    {
        $this->categories()->update(
            userId: $userId,
            userName: $userName,
            input: $input,
        );
        $catName = \trim($input['label'] ?? '');
        $catId = (int)($input['id'] ?? 0);
        $this->admin->model->changelog->log(
            description: "Updated category: {$catName}",
            recordType: 'category',
            recordId: $catId,
        );
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionDelete(int $userId, string $userName, array $input): void
    {
        $catId = (int)($input['id'] ?? 0);
        $this->categories()->delete(
            userId: $userId,
            userName: $userName,
            categoryId: $catId,
        );
        $this->admin->model->changelog->log(
            description: "Deleted category #{$catId}",
            recordType: 'category',
            recordId: $catId,
        );
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionRestore(int $userId, string $userName, array $input): void
    {
        $catId = (int)($input['id'] ?? 0);
        $this->categories()->restore(
            userId: $userId,
            userName: $userName,
            categoryId: $catId,
        );
        $this->admin->model->changelog->log(
            description: "Restored category #{$catId}",
            recordType: 'category',
            recordId: $catId,
        );
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionReorder(int $userId, string $userName, array $input): void
    {
        $this->categories()->reorder(
            userId: $userId,
            userName: $userName,
            items: $input['items'] ?? [],
        );
        $this->admin->model->changelog->log(
            description: 'Reordered categories',
            recordType: 'category',
        );
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionPermanentDelete(
        int $userId,
        string $userName,
        array $input,
    ): void {
        $isAdmin = $this->admin->auth->isAdmin();
        if (!$isAdmin) {
            throw new \Exception('Only administrators can permanently delete categories');
        }

        $catId = (int)($input['id'] ?? 0);
        $this->admin->model->changelog->log(
            description: "Permanently deleted category #{$catId}",
            recordType: 'category',
            recordId: $catId,
        );
        $this->categories()->permanentDelete(
            userId: $userId,
            userName: $userName,
            categoryId: $catId,
        );
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionGetCategoryProducts(int $categoryId): void
    {
        $invalidId = $categoryId <= 0;
        if ($invalidId) throw new \Exception('Invalid category ID');

        $products = $this->admin->model->productCategory->getProductsForOrdering($categoryId);

        $imagesPath = $this->osmium->config->site->imagesPath;
        foreach ($products as &$product) {
            $brandPath = $this->admin->generateBrandPath($product['brand_name'] ?? '');
            $modelPath = $this->admin->generateModelPath($product['model_name'] ?? '');
            $thumbFile = $brandPath . '-' . $modelPath . '.jpg';
            $thumbPath = '/shop/' . $brandPath . '/' . $modelPath . '/thumb/' . $thumbFile;
            $product['thumbnail'] = $imagesPath . $thumbPath;
            $product['title'] = ($product['brand_name'] ?? '') . ' ' . ($product['model_name'] ?? '');
        }
        unset($product);

        $this->admin->jsonSuccess(['success' => true, 'products' => $products]);
    }

    private function actionReorderProducts(array $input): void
    {
        $categoryId = (int)($input['category_id'] ?? 0);
        $items = $input['items'] ?? [];

        $invalidId = $categoryId <= 0;
        if ($invalidId) throw new \Exception('Invalid category ID');
        $noItems = empty($items);
        if ($noItems) throw new \Exception('No items to reorder');

        foreach ($items as $item) {
            $this->admin->model->productCategory->updateProductSort(
                sort: (int)$item['sort'],
                productId: (int)$item['product_id'],
                categoryId: $categoryId,
            );
        }

        $this->admin->model->changelog->log(
            description: "Reordered products in category #{$categoryId}",
            recordType: 'category',
            recordId: $categoryId,
        );
        $this->admin->jsonSuccess(['success' => true]);
    }
}
