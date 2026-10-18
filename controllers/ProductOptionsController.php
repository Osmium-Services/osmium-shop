<?php

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\ShopAdminServices;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Product Options Controller
 *
 * Handles product option types and values management via AJAX API
 * Routes:
 * - index: Redirects to products list (no direct access)
 * - action: JSON API for CRUD operations on option types and values
 */
class ProductOptionsController extends AdminController
{
    public function index(): void
    {
        $this->redirect('shop/products/');
    }

    public function action(): void
    {
        \header('Content-Type: application/json');
        $this->ensurePostMethod();
        $this->ensureAuthenticated();

        $input = $this->admin->getJsonInput();
        $action = $input['action'] ?? '';
        $productId = (int)($input['product_id'] ?? 0);

        $this->validateCsrfForAction($action, $input);

        try {
            match ($action) {
                'get_options' => $this->actionGetOptions($productId),
                'add_type' => $this->actionAddType(productId: $productId, input: $input),
                'update_type' => $this->actionUpdateType($input),
                'delete_type' => $this->actionDeleteType($input),
                'add_value' => $this->actionAddValue($input),
                'update_value' => $this->actionUpdateValue($input),
                'delete_value' => $this->actionDeleteValue($input),
                'reorder_types' => $this->actionReorderTypes($input),
                'reorder_values' => $this->actionReorderValues($input),
                'copy_from_product' => $this->actionCopyFromProduct($input),
                default => $this->admin->jsonError('Unknown action'),
            };
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =====================================================================
    // ACTION HANDLERS
    // =====================================================================

    private function actionGetOptions(int $productId): void
    {
        $optionTypes = ShopAdminServices::options($this->admin)->getProductOptions($productId);
        $this->admin->jsonSuccess(['success' => true, 'optionTypes' => $optionTypes]);
    }

    private function actionAddType(int $productId, array $input): void
    {
        $result = ShopAdminServices::options($this->admin)->addOptionType(
            productId: $productId,
            input: $input,
        );
        $this->admin->jsonSuccess(['success' => true] + $result);
    }

    private function actionUpdateType(array $input): void
    {
        $typeId = (int)($input['type_id'] ?? 0);
        ShopAdminServices::options($this->admin)->updateOptionType(typeId: $typeId, input: $input);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionDeleteType(array $input): void
    {
        $typeId = (int)($input['type_id'] ?? 0);
        ShopAdminServices::options($this->admin)->deleteOptionType($typeId);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionAddValue(array $input): void
    {
        $typeId = (int)($input['type_id'] ?? 0);
        $newId = ShopAdminServices::options($this->admin)->addOptionValue(typeId: $typeId, input: $input);
        $this->admin->jsonSuccess(['success' => true, 'id' => $newId]);
    }

    private function actionUpdateValue(array $input): void
    {
        $valueId = (int)($input['value_id'] ?? 0);
        ShopAdminServices::options($this->admin)->updateOptionValue(valueId: $valueId, input: $input);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionDeleteValue(array $input): void
    {
        $valueId = (int)($input['value_id'] ?? 0);
        ShopAdminServices::options($this->admin)->deleteOptionValue($valueId);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionReorderTypes(array $input): void
    {
        ShopAdminServices::options($this->admin)->reorderOptionTypes($input['items'] ?? []);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionReorderValues(array $input): void
    {
        ShopAdminServices::options($this->admin)->reorderOptionValues($input['items'] ?? []);
        $this->admin->jsonSuccess(['success' => true]);
    }

    private function actionCopyFromProduct(array $input): void
    {
        $sourceProductId = (int)($input['source_product_id'] ?? 0);
        $targetProductId = (int)($input['target_product_id'] ?? 0);

        $result = ShopAdminServices::options($this->admin)->copyOptionsFromProduct(
            sourceProductId: $sourceProductId,
            targetProductId: $targetProductId,
        );

        $this->admin->jsonSuccess([
            'success' => true,
            'types_copied' => $result['types_copied'],
            'values_copied' => $result['values_copied'],
        ]);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function ensurePostMethod(): void
    {
        $notPost = $_SERVER['REQUEST_METHOD'] !== 'POST';
        if ($notPost) $this->admin->jsonError('Method not allowed');
    }

    private function ensureAuthenticated(): void
    {
        $userId = $this->admin->auth->getCurrentUserId();
        if (!$userId) $this->admin->jsonError('Not authenticated');
    }

    private function validateCsrfForAction(string $action, array $input): void
    {
        $readOnlyActions = ['get_options', 'get_types'];
        $isReadOnly = \in_array(needle: $action, haystack: $readOnlyActions);
        $needsCsrf = !$isReadOnly && !$this->admin->auth->validateCsrfJson($input);

        if ($needsCsrf) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }
    }
}
