<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Core\Library\Input;
use Osmium\Modules\Admin\Core\AdminController;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Brands controller - handles all brand-related routes.
 *
 * Routes:
 *   - index()  → /admin/shop/brands/           (list view)
 *   - edit()   → /admin/shop/brands/edit/      (create/edit form)
 *   - action() → /admin/shop/brands/action/    (AJAX operations)
 */
class BrandsController extends AdminController
{
    private const RECORD_TYPE = 'brand';

    // =========================================================================
    // Public Actions (called from entry point files)
    // =========================================================================

    /**
     * List all brands (active and deleted).
     */
    public function index(): void
    {
        $lists = $this->loadListDefinitions('brands', __DIR__ . '/../lists');
        $this->data['admin']['lists'] = $lists;
        $this->data['admin']['recentBrands'] = $this->loadListRows($lists['recent'], $this->admin->model->brand);
        $this->data['admin']['brands'] = $this->loadListRows($lists['active'], $this->admin->model->brand);
        $this->data['admin']['deletedBrands'] = $this->loadListRows($lists['deleted'], $this->admin->model->brand);
        $this->data['admin']['activeEditors'] = $this->loadActiveEditors();
        $this->data['admin']['isAdmin'] = $this->isAdmin();

        $this->setView('brands/index.phtml');
    }

    /**
     * Edit or create a brand.
     */
    public function edit(): void
    {
        $brandId = (int) $this->osmium->getStashedParam(key: 'id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $isPost = $this->isPost();
        if ($isPost) {
            $error = $this->handleFormSubmit();
            if ($error) $this->data['admin']['error'] = $error;
        }

        $brand = $this->loadBrandForEdit($brandId);

        $this->data['admin']['brand'] = $brand ?? $this->getEmptyBrand();
        $this->data['admin']['brandId'] = $brandId;
        $this->data['admin']['isNew'] = !$brandId;
        $this->data['admin']['showSavedMessage'] = $this->consumeSavedFlag();
        $this->data['admin']['error'] = $this->data['admin']['error'] ?? null;
        $this->data['admin']['pageTitle'] = $brandId
            ? \htmlspecialchars($brand['name'] ?? '')
            : 'Create Brand';

        $this->setView('brands/edit.phtml');
    }

    /**
     * Handle AJAX actions (delete, restore, permanently delete, presence).
     */
    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $this->isPost();
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int) ($input['id'] ?? 0);

        $presenceHandled = $this->admin->presence->handleAction(
            action: $action,
            input: $input,
            recordType: self::RECORD_TYPE,
        );
        if ($presenceHandled) exit; // Handle presence tracking

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) {
            $this->admin->jsonError('Invalid request token. Please refresh and try again.'); // Validate CSRF
        }

        $missingParams = !$action || !$id;
        if ($missingParams) $this->admin->jsonError('Missing parameters'); // Validate parameters

        $recordName = $this->getRecordName($id);

        try {
            $result = match ($action) {
                'delete' => $this->softDelete($id),
                'restore' => $this->restore($id),
                'permanently_delete' => $this->hardDelete($id),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            $this->logAction(action: $action, id: $id, name: $recordName);
            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Index Helpers
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveEditors(): array
    {
        return $this->admin->presence->list(
            currentUserId: $this->getCurrentUserId(),
            recordType: self::RECORD_TYPE,
        );
    }

    // =========================================================================
    // Edit Helpers
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function getEmptyBrand(): array
    {
        return [
            'id' => 0,
            'name' => '',
            'page_url' => '',
            'info_external' => '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadBrandForEdit(int $brandId): ?array
    {
        if (!$brandId) return null;

        $brand = $this->admin->model->brand->getById($brandId);

        if (!$brand) $this->redirect('shop/brands/');

        $this->admin->presence->registerCurrentUser(
            recordType: self::RECORD_TYPE,
            recordId: $brandId,
        );

        return $brand;
    }

    private function consumeSavedFlag(): bool
    {
        $notSaved = !isset($_SESSION['brand_saved']);
        if ($notSaved) return false;

        unset($_SESSION['brand_saved']);
        return true;
    }

    private function handleFormSubmit(): ?string
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) return 'Invalid form submission. Please try again.';

        $input = Input::post();
        $brandId = $input->nullableInt(key: 'id');
        $name = $input->string(key: 'name');
        $pageUrl = $input->nullableString(key: 'page_url');
        $infoExternal = $input->nullableString(key: 'info_external');

        $nameEmpty = empty($name);
        if ($nameEmpty) return 'Brand name is required';

        $tooLong = \mb_strlen($name) > 50 || \mb_strlen((string) $pageUrl) > 100 || \mb_strlen((string) $infoExternal) > 255; // The columns' sizes; the form's maxlength is only a courtesy
        if ($tooLong) return 'The name can be up to 50 characters, the page URL up to 100 and the external info up to 255';

        $isWebLink = $this->isWebLink($infoExternal);
        if (!$isWebLink) return 'The external info link must be a web address starting with http:// or https://';

        if ($brandId) {
            $this->updateBrand(
                brandId: $brandId,
                name: $name,
                pageUrl: $pageUrl,
                infoExternal: $infoExternal,
            );
        } else {
            $this->insertBrand(
                name: $name,
                pageUrl: $pageUrl,
                infoExternal: $infoExternal,
            );
        }

        return null; // Never reached due to redirects
    }

    private function isWebLink(?string $link): bool
    {
        $isBlank = $link === null || $link === '';
        if ($isBlank) return true;

        $hasWebScheme = \preg_match('#^https?://[^\s]+$#i', $link) === 1;
        $isValidUrl = \filter_var($link, FILTER_VALIDATE_URL) !== false;

        return $hasWebScheme && $isValidUrl;
    }

    private function updateBrand(
        int $brandId,
        string $name,
        ?string $pageUrl,
        ?string $infoExternal,
    ) {
        $this->admin->model->brand->update($brandId, $name, $pageUrl, $infoExternal);

        $this->admin->model->changelog->log(
            description: "Updated brand: {$name}",
            recordType: self::RECORD_TYPE,
            recordId: $brandId,
        );

        $_SESSION['brand_saved'] = true;
        $this->redirect("shop/brands/edit/?id={$brandId}");
    }

    private function insertBrand(
        string $name,
        ?string $pageUrl,
        ?string $infoExternal,
    ) {
        $newBrandId = $this->admin->model->brand->insert($name, $pageUrl, $infoExternal);

        $this->admin->model->changelog->log(
            description: "Created brand: {$name}",
            recordType: self::RECORD_TYPE,
            recordId: $newBrandId,
        );

        $_SESSION['brand_saved'] = true;
        $this->redirect("shop/brands/edit/?id={$newBrandId}");
    }

    // =========================================================================
    // Action Helpers
    // =========================================================================

    private function getRecordName(int $id): string
    {
        $brand = $this->admin->model->brand->getById($id);
        return $brand['name'] ?? "Brand #{$id}";
    }

    /**
     * @return array{success: true, action: string, id: int}
     */
    private function softDelete(int $id): array
    {
        $this->ensureNoActiveProducts($id);
        $this->admin->model->brand->softDelete($id);
        return ['success' => true, 'action' => 'delete', 'id' => $id];
    }

    /**
     * @return array{success: true, action: string, id: int}
     */
    private function restore(int $id): array
    {
        $this->admin->model->brand->restore($id);
        return ['success' => true, 'action' => 'restore', 'id' => $id];
    }

    /**
     * @return array{success: true, action: string, id: int}
     */
    private function hardDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \RuntimeException('Permission denied');

        $this->ensureNoLinkedProducts($id);
        $this->admin->model->brand->hardDelete($id);
        return ['success' => true, 'action' => 'permanently_delete', 'id' => $id];
    }

    private function ensureNoActiveProducts(int $id): void
    {
        $count = $this->admin->model->brand->countActiveProducts($id);

        $hasActiveProducts = $count > 0;
        if ($hasActiveProducts) {
            throw new \RuntimeException("Cannot delete: {$count} product(s) are using this brand");
        }
    }

    private function ensureNoLinkedProducts(int $id): void
    {
        $count = $this->admin->model->brand->countAllProducts($id);

        $hasLinkedProducts = $count > 0;
        if ($hasLinkedProducts) {
            throw new \RuntimeException("Cannot delete: {$count} product(s) are linked to this brand");
        }
    }

    private function logAction(string $action, int $id, string $name): void
    {
        $labels = [
            'delete' => 'Deleted',
            'restore' => 'Restored',
            'permanently_delete' => 'Permanently deleted',
        ];

        $label = $labels[$action] ?? \ucfirst($action);

        $this->admin->model->changelog->log(
            description: "{$label} brand: {$name}",
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }

    // =========================================================================
    // Shared Utilities
    // =========================================================================

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
