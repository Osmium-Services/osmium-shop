<?php

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Product Price History Controller
 *
 * Handles price change tracking and batch undo functionality
 * Routes:
 * - index: List view showing import batches and manual changes
 * - action: JSON API for undo operations and history queries
 */
class ProductPriceHistoryController extends AdminController
{
    public function index(): void
    {
        $productId = (int)$this->osmium->getStashedParam(key: 'product_id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $importBatches = $this->loadImportBatches($productId);
        $importDetails = $this->loadImportDetails($productId);
        $manualChanges = $this->loadManualChanges($productId);

        $this->data['admin']['importBatches'] = [];
        foreach ($importBatches as $index => $batch) {
            $this->data['admin']['importBatches'][] = $this->formatBatch(
                batch: $batch,
                canUndo: $index === 0 && !$productId, // Only the latest import, and not from a single product's view
            );
        }

        $this->data['admin']['importDetails'] = [];
        foreach ($importDetails as $changedAt => $details) {
            $this->data['admin']['importDetails'][$changedAt] = \array_map($this->formatDetail(...), $details);
        }

        $this->data['admin']['manualChanges'] = [];
        foreach ($manualChanges as $index => $change) {
            $this->data['admin']['manualChanges'][] = $this->formatManualChange(change: $change, canUndo: $index === 0);
        }

        $this->data['admin']['hasHistory'] = !empty($importBatches) || !empty($manualChanges);
        $this->data['admin']['filterProductId'] = $productId;
        $this->data['admin']['filterProductName'] = $productId
            ? $this->loadProductName($productId)
            : null;

        $this->setView('products/price-history.phtml');
    }

    public function action(): void
    {
        \header('Content-Type: application/json');

        $input = $this->admin->getJsonInput();
        $action = $input['action'] ?? null;

        if (!$action) $this->admin->jsonError('Missing action');

        $this->validateCsrf($input);

        try {
            match ($action) {
                'undo_batch' => $this->actionUndoBatch($input),
                'get_product_history' => $this->actionGetProductHistory($input),
                'undo_manual' => $this->actionUndoManual($input),
                default => $this->admin->jsonError('Unknown action'),
            };
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =====================================================================
    // DISPLAY FORMATTING
    // =====================================================================

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function formatBatch(array $batch, bool $canUndo): array
    {
        $batch['changedAtFormatted'] = \date('j M Y, H:i', \strtotime($batch['changed_at']));
        $batch['productCount'] = (int) $batch['product_count'];
        $batch['canUndo'] = $canUndo;

        return $batch;
    }

    /**
     * One product's price change inside an import batch.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function formatDetail(array $detail): array
    {
        $detail['productLabelHtml'] = $this->productLabelHtml($detail);
        $detail['priceOldHtml'] = $this->poundsHtml($detail['price_exc_tax_old']);
        $detail['priceNewHtml'] = $this->poundsHtml($detail['price_exc_tax_new']);
        $detail['rrpOldDisplay'] = $detail['rrp_exc_tax_old'] ? $this->poundsHtml($detail['rrp_exc_tax_old']) : '-';
        $detail['rrpNewDisplay'] = $detail['rrp_exc_tax_new'] ? $this->poundsHtml($detail['rrp_exc_tax_new']) : '-';

        return $detail;
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function formatManualChange(array $change, bool $canUndo): array
    {
        $change['productLabelHtml'] = $this->productLabelHtml($change);
        $change['priceOldHtml'] = $this->poundsHtml($change['price_exc_tax_old']);
        $change['priceNewHtml'] = $this->poundsHtml($change['price_exc_tax_new']);
        $change['hasRrp'] = (bool) ($change['rrp_exc_tax_old'] || $change['rrp_exc_tax_new']);
        $change['rrpOldHtml'] = $this->poundsHtml($change['rrp_exc_tax_old'] ?? 0);
        $change['rrpNewHtml'] = $this->poundsHtml($change['rrp_exc_tax_new'] ?? 0);
        $change['changedAtFormatted'] = \date('j M Y, H:i', \strtotime($change['changed_at']));
        $change['canUndo'] = $canUndo;

        return $change;
    }

    /**
     * "Brand Model" (escaped), or "Product #id" when the product no longer exists.
     *
     * @param array<string, mixed> $row
     */
    private function productLabelHtml(array $row): string
    {
        return \htmlspecialchars($row['brand_name'] ?? '') . ' '
            . \htmlspecialchars($row['model_name'] ?? 'Product #' . $row['product_id']);
    }

    /**
     * An ex-VAT price as HTML (&pound; entity + two decimals).
     */
    private function poundsHtml(mixed $amount): string
    {
        return '&pound;' . \number_format((float) $amount, 2);
    }

    // =====================================================================
    // INDEX ROUTE HELPERS
    // =====================================================================

    private function loadImportBatches(int $productId): array
    {
        return $this->admin->model->productPriceHistory->getImportBatches($productId);
    }

    private function loadImportDetails(int $productId): array
    {
        $importDetails = [];
        foreach ($this->admin->model->productPriceHistory->getImportDetails($productId) as $detail) {
            $importDetails[$detail['changed_at']][] = $detail;
        }
        return $importDetails;
    }

    private function loadManualChanges(int $productId): array
    {
        return $this->admin->model->productPriceHistory->getManualChanges($productId);
    }

    private function loadProductName(int $productId): ?string
    {
        $product = $this->admin->model->product->getBrandModel($productId);
        if (!$product) return null;
        return \trim(($product['brand_name'] ?? '') . ' ' . ($product['model_name'] ?? ''));
    }

    // =====================================================================
    // ACTION HANDLERS
    // =====================================================================

    private function actionUndoBatch(array $input): void
    {
        $timestamp = $input['timestamp'] ?? null;
        if (!$timestamp) $this->admin->jsonError('Missing timestamp');

        $records = $this->getBatchRecords($timestamp);

        $noRecords = empty($records);
        if ($noRecords) $this->admin->jsonError('No records found for this batch');

        $conflicts = $this->checkForNewerChanges($records, $timestamp);

        $hasConflicts = !empty($conflicts);
        if ($hasConflicts) {
            $productNames = \array_map(
                callback: fn($c) => $this->buildProductName($c),
                array: $conflicts,
            );
            echo \json_encode([
                'success' => false,
                'error' => 'Cannot undo: ' . \count($conflicts) . ' product(s) have been modified',
                'products' => $productNames,
            ]);
            exit;
        }

        $revertedCount = 0;
        foreach ($records as $record) {
            $this->revertProductPrice($record);
            $revertedCount++;
        }

        $this->markBatchAsUndone($timestamp);
        $this->admin->jsonSuccess(['success' => true, 'reverted' => $revertedCount]);
    }

    private function actionGetProductHistory(array $input): void
    {
        $productId = $input['product_id'] ?? null;
        if (!$productId) $this->admin->jsonError('Missing product ID');

        $history = $this->admin->model->productPriceHistory->getByProduct((int)$productId);
        $this->admin->jsonSuccess(['success' => true, 'history' => $history]);
    }

    private function actionUndoManual(array $input): void
    {
        $id = (int)($input['id'] ?? 0);
        if (!$id) $this->admin->jsonError('Missing ID');

        $record = $this->getHistoryRecord($id);

        if (!$record) $this->admin->jsonError('Record not found');

        $hasNewerChanges = $this->checkProductHasNewerChanges(
            productId: $record['product_id'],
            changedAt: $record['changed_at'],
        );

        if ($hasNewerChanges) $this->admin->jsonError('Cannot undo: this product has newer price changes');

        $this->revertProductPrice($record);
        $this->markRecordAsUndone($id);

        $this->admin->jsonSuccess(['success' => true]);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function getBatchRecords(string $timestamp): array
    {
        return $this->admin->model->productPriceHistory->getBatch($timestamp);
    }

    private function checkForNewerChanges(array $records, string $timestamp): array
    {
        $productIds = \array_column(array: $records, column_key: 'product_id');
        return $this->admin->model->productPriceHistory->checkConflicts($productIds, $timestamp);
    }

    private function markBatchAsUndone(string $timestamp): void
    {
        $this->admin->model->productPriceHistory->markBatchAsUndone($timestamp);
    }

    private function getHistoryRecord(int $id): ?array
    {
        return $this->admin->model->productPriceHistory->getManualRecord($id);
    }

    private function checkProductHasNewerChanges(int $productId, string $changedAt): bool
    {
        return $this->admin->model->productPriceHistory->hasNewerChanges($productId, $changedAt);
    }

    private function markRecordAsUndone(int $id): void
    {
        $this->admin->model->productPriceHistory->markRecordAsUndone($id);
    }

    private function revertProductPrice(array $record): void
    {
        $this->admin->model->product->revertPrice(
            id: (int)$record['product_id'],
            price: $record['price_exc_tax_old'],
            rrp: $record['rrp_exc_tax_old'],
        );
    }

    private function buildProductName(array $c): string
    {
        $brand = $c['brand_name'] ?? '';
        $model = $c['model_name'] ?? 'Product #' . $c['product_id'];
        return \trim($brand . ' ' . $model);
    }

    private function validateCsrf(array $input): void
    {
        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }
    }
}
