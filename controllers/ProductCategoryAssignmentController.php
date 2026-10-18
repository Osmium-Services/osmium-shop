<?php

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Product Category Assignment Controller
 *
 * Handles assigning/removing products to/from categories via AJAX
 * Routes:
 * - action: JSON API for add/remove operations
 */
class ProductCategoryAssignmentController extends AdminController
{
    public function action(): void
    {
        \header('Content-Type: application/json');
        $this->ensurePostMethod();

        $input = $this->admin->getJsonInput();
        $action = $input['action'] ?? '';

        $this->validateCsrf($input);

        try {
            match ($action) {
                'add' => $this->actionAdd($input),
                'remove' => $this->actionRemove($input),
                default => throw new \Exception('Invalid action'),
            };
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =====================================================================
    // ACTION HANDLERS
    // =====================================================================

    private function actionAdd(array $input): void
    {
        $productId = (int)($input['product_id'] ?? 0);
        $categoryId = (int)($input['category_id'] ?? 0);

        if (!$productId || !$categoryId) throw new \Exception('Missing product_id or category_id');

        $existing = $this->checkExists(productId: $productId, categoryId: $categoryId);

        if (!$existing) $this->insertAssignment(productId: $productId, categoryId: $categoryId);

        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionRemove(array $input): void
    {
        $productId = (int)($input['product_id'] ?? 0);
        $categoryId = (int)($input['category_id'] ?? 0);

        if (!$productId || !$categoryId) throw new \Exception('Missing product_id or category_id');

        $this->deleteAssignment(productId: $productId, categoryId: $categoryId);
        $this->admin->jsonSuccess(['success' => true]);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function checkExists(int $productId, int $categoryId): bool
    {
        return $this->admin->model->productCategoryAssignment->exists(productId: $productId, categoryId: $categoryId);
    }

    private function insertAssignment(int $productId, int $categoryId): void
    {
        $this->admin->model->productCategoryAssignment->insert(productId: $productId, categoryId: $categoryId);
    }

    private function deleteAssignment(int $productId, int $categoryId): void
    {
        $this->admin->model->productCategoryAssignment->delete(productId: $productId, categoryId: $categoryId);
    }

    private function ensurePostMethod(): void
    {
        $notPost = $_SERVER['REQUEST_METHOD'] !== 'POST';
        if ($notPost) $this->admin->jsonError('Method not allowed');
    }

    private function validateCsrf(array $input): void
    {
        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }
    }
}
