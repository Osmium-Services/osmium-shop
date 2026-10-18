<?php

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Product Images Controller
 *
 * Handles product image management via AJAX API
 * Routes:
 * - index: Redirects to products list (no direct access)
 * - action: JSON API for upload, delete, regenerate, and fetch operations
 */
class ProductImagesController extends AdminController
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

        \ob_start();
        try {
            $result = match ($action) {
                'upload' => $this->actionUpload(productId: $productId, input: $input),
                'delete' => $this->actionDelete(productId: $productId, input: $input),
                'update_descr_image' => $this->actionUpdateDescrImage(
                    productId: $productId,
                    input: $input,
                ),
                'get_images' => $this->actionGetImages(productId: $productId, input: $input),
                'regenerate' => $this->actionRegenerate(productId: $productId, input: $input),
                default => null,
            };
            \ob_end_clean();

            $unknownAction = $result === null;
            if ($unknownAction) $this->admin->jsonError('Unknown action');

            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            \ob_end_clean();
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =====================================================================
    // ACTION HANDLERS
    // =====================================================================

    private function actionUpload(int $productId, array $input): array
    {
        $file = $_FILES['image'] ?? []; // No file at all (e.g. over post_max_size) reaches upload() as an empty array, which says so
        $result = $this->admin->shopImages->upload(
            productId: $productId,
            file: $file,
            input: $input,
        );
        return ['success' => true] + $result;
    }

    private function actionDelete(int $productId, array $input): array
    {
        $result = $this->admin->shopImages->delete(productId: $productId, input: $input);
        return ['success' => true] + $result;
    }

    private function actionUpdateDescrImage(int $productId, array $input): array
    {
        $result = $this->admin->shopImages->updateDescrImage(
            productId: $productId,
            input: $input,
        );
        return ['success' => true] + $result;
    }

    private function actionGetImages(int $productId, array $input): array
    {
        $brandPath = $input['brand_path'] ?? '';
        $modelPath = $input['model_path'] ?? '';
        $images = $this->admin->shopImages->getAll(
            productId: $productId,
            brandPath: $brandPath,
            modelPath: $modelPath,
        );
        return ['success' => true, 'images' => $images];
    }

    private function actionRegenerate(int $productId, array $input): array
    {
        $brandPath = $input['brand_path'] ?? '';
        $modelPath = $input['model_path'] ?? '';
        return $this->admin->shopImages->regenerate(
            productId: $productId,
            brandPath: $brandPath,
            modelPath: $modelPath,
        );
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
        $readOnlyActions = ['get_images'];
        $isReadOnly = \in_array(needle: $action, haystack: $readOnlyActions);

        $sessionToken = $_SESSION['token'] ?? '';
        $csrfToken = $input['csrf_token'] ?? '';
        $postToken = $input['token'] ?? '';

        $jsonMatches = \hash_equals(known_string: $sessionToken, user_string: $csrfToken);
        $postMatches = \hash_equals(known_string: $sessionToken, user_string: $postToken);
        $csrfFromJson = !empty($csrfToken) && $jsonMatches;
        $csrfFromPost = !empty($postToken) && $postMatches;
        $csrfValid = $isReadOnly || $csrfFromJson || $csrfFromPost;

        if (!$csrfValid) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }
    }
}
