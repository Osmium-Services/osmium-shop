<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Core\Library\Input;
use Osmium\Modules\Admin\Core\AdminController;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Product Statuses controller - handles all status-related routes.
 *
 * Routes:
 *   - index()  → /admin/shop/statuses/           (list view)
 *   - edit()   → /admin/shop/statuses/edit/      (create/edit form)
 *   - action() → /admin/shop/statuses/action/    (AJAX operations)
 */
class StatusesController extends AdminController
{
    private const RECORD_TYPE = 'status';

    // =========================================================================
    // Public Actions
    // =========================================================================

    /**
     * List all statuses (active and deleted).
     */
    public function index(): void
    {
        $lists = $this->loadListDefinitions('statuses', __DIR__ . '/../lists');
        $this->data['admin']['lists'] = $lists;
        $this->data['admin']['recentStatuses'] = $this->loadListRows($lists['recent'], $this->admin->model->status);
        $this->data['admin']['statuses'] = $this->loadListRows($lists['active'], $this->admin->model->status);
        $this->data['admin']['deletedStatuses'] = $this->loadListRows($lists['deleted'], $this->admin->model->status);
        $this->data['admin']['activeEditors'] = $this->loadActiveEditors();
        $this->data['admin']['isAdmin'] = $this->isAdmin();

        $this->setView('statuses/index.phtml');
    }

    /**
     * Edit or create a status.
     */
    public function edit(): void
    {
        $statusId = (int) $this->osmium->getStashedParam(key: 'id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $isPost = $this->isPost();
        if ($isPost) {
            $error = $this->handleFormSubmit();
            if ($error) $this->data['admin']['error'] = $error;
        }

        $status = $this->loadStatusForEdit($statusId);

        $this->data['admin']['status'] = $status;
        $this->data['admin']['statusId'] = $statusId;
        $this->data['admin']['isNew'] = !$statusId;
        $this->data['admin']['showSavedMessage'] = $this->consumeSavedFlag();

        $this->setView('statuses/edit.phtml');
    }

    /**
     * Handle AJAX actions (delete, restore, permanently delete, presence).
     */
    public function action()
    {
        \header('Content-Type: application/json');

        $isNotPost = !$this->isPost();
        if ($isNotPost) $this->admin->jsonError('Method not allowed');

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
        $csrfInvalid = !$csrfValid;
        if ($csrfInvalid) {
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
     * @return array<string, mixed>|null
     */
    private function loadStatusForEdit(int $statusId): ?array
    {
        if (!$statusId) return null;

        $status = $this->admin->model->status->getById($statusId);

        if (!$status) $this->redirect('shop/statuses/');

        $this->admin->presence->registerCurrentUser(
            recordType: self::RECORD_TYPE,
            recordId: $statusId,
        );

        return $status;
    }

    private function consumeSavedFlag(): bool
    {
        $notSaved = !isset($_SESSION['status_saved']);
        if ($notSaved) return false;

        unset($_SESSION['status_saved']);
        return true;
    }

    private function handleFormSubmit(): ?string
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        $csrfInvalid = !$csrfValid;
        if ($csrfInvalid) return 'Invalid form submission. Please try again.';

        $input = Input::post();
        $statusId = $input->nullableInt(key: 'id');
        $descrShort = $input->string(key: 'descr_short');
        $descrLong = $input->string(key: 'descr_long');
        $productAvailable = (int) $input->has(key: 'product_available');
        $restocking = (int) $input->has(key: 'restocking');
        $schemaRef = $input->string(key: 'schema_ref');
        $list = (int) $input->has(key: 'list');
        $enabled = (int) $input->has(key: 'enabled');

        $missingDescr = empty($descrShort) || empty($descrLong);
        if ($missingDescr) return 'Short and long descriptions are required';

        $tooLong = \mb_strlen($descrShort) > 20 || \mb_strlen($schemaRef) > 20 || \mb_strlen($descrLong) > 50; // The columns' sizes; the form's maxlength is only a courtesy
        if ($tooLong) return 'The short description and schema reference can be up to 20 characters, the long description up to 50';

        if ($statusId) {
            $this->updateStatus(
                statusId: $statusId,
                descrShort: $descrShort,
                descrLong: $descrLong,
                productAvailable: $productAvailable,
                restocking: $restocking,
                schemaRef: $schemaRef,
                list: $list,
                enabled: $enabled,
            );
        } else {
            $this->insertStatus(
                descrShort: $descrShort,
                descrLong: $descrLong,
                productAvailable: $productAvailable,
                restocking: $restocking,
                schemaRef: $schemaRef,
                list: $list,
                enabled: $enabled,
            );
        }

        return null; // Never reached due to redirects
    }

    private function updateStatus(
        int $statusId,
        string $descrShort,
        string $descrLong,
        int $productAvailable,
        int $restocking,
        string $schemaRef,
        int $list,
        int $enabled,
    ) {
        $this->admin->model->status->update(
            id: $statusId,
            descrShort: $descrShort,
            descrLong: $descrLong,
            productAvailable: $productAvailable,
            restocking: $restocking,
            schemaRef: $schemaRef,
            list: $list,
            enabled: $enabled,
        );

        $this->admin->model->changelog->log(
            description: "Updated status: {$descrShort}",
            recordType: self::RECORD_TYPE,
            recordId: $statusId,
        );

        $_SESSION['status_saved'] = true;
        $this->redirect("shop/statuses/edit/?id={$statusId}");
    }

    private function insertStatus(
        string $descrShort,
        string $descrLong,
        int $productAvailable,
        int $restocking,
        string $schemaRef,
        int $list,
        int $enabled,
    ) {
        $newStatusId = $this->admin->model->status->insert(
            descrShort: $descrShort,
            descrLong: $descrLong,
            productAvailable: $productAvailable,
            restocking: $restocking,
            schemaRef: $schemaRef,
            list: $list,
            enabled: $enabled,
        );

        $this->admin->model->changelog->log(
            description: "Created status: {$descrShort}",
            recordType: self::RECORD_TYPE,
            recordId: $newStatusId,
        );

        $_SESSION['status_saved'] = true;
        $this->redirect("shop/statuses/edit/?id={$newStatusId}");
    }

    // =========================================================================
    // Action Helpers
    // =========================================================================

    private function getRecordName(int $id): string
    {
        $status = $this->admin->model->status->getById($id);
        return $status['descr_short'] ?? "Status #{$id}";
    }

    /**
     * @return array{success: true, action: string, id: int}
     */
    private function softDelete(int $id): array
    {
        $this->ensureNoActiveProducts($id);
        $this->admin->model->status->softDelete($id);
        return ['success' => true, 'action' => 'delete', 'id' => $id];
    }

    /**
     * @return array{success: true, action: string, id: int}
     */
    private function restore(int $id): array
    {
        $this->admin->model->status->restore($id);
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
        $this->admin->model->status->hardDelete($id);
        return ['success' => true, 'action' => 'permanently_delete', 'id' => $id];
    }

    private function ensureNoActiveProducts(int $id): void
    {
        $count = $this->admin->model->status->countActiveProducts($id);

        $hasLinkedProducts = $count > 0;
        if ($hasLinkedProducts) {
            throw new \RuntimeException("Cannot delete: {$count} product(s) are using this status");
        }
    }

    private function ensureNoLinkedProducts(int $id): void
    {
        $count = $this->admin->model->status->countAllProducts($id);

        $hasLinkedProducts = $count > 0;
        if ($hasLinkedProducts) {
            throw new \RuntimeException("Cannot delete: {$count} product(s) are using this status");
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
            description: "{$label} status: {$name}",
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
