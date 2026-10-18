<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class ProductPriceHistory extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** @return array<int, array<string, mixed>> */
    public function getImportBatches(int $productId): array
    {
        return $this->fetchAll('price-history-import-batches.sql', [':product_id' => $productId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getImportDetails(int $productId): array
    {
        return $this->fetchAll('price-history-import-details.sql', [':product_id' => $productId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getManualChanges(int $productId): array
    {
        return $this->fetchAll('price-history-manual-changes.sql', [':product_id' => $productId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getBatch(string $timestamp): array
    {
        return $this->fetchAll('price-history-get-batch.sql', [':timestamp' => $timestamp]);
    }

    /**
     * Returns rows for product IDs that have changes newer than the given timestamp.
     * Uses positional (?) params because the IN clause is built dynamically.
     *
     * @param array<int, int|string> $productIds
     * @return array<int, array<string, mixed>>
     */
    public function checkConflicts(array $productIds, string $timestamp): array
    {
        $placeholders = \implode(',', \array_fill(0, \count($productIds), '?'));
        $sql = $this->loadSqlFile('price-history-check-conflicts.sql', ['PLACEHOLDERS' => $placeholders]);
        $this->database->query($sql);
        foreach ($productIds as $i => $id) {
            $this->database->bind(param: $i + 1, value: $id);
        }
        $this->database->bind(param: \count($productIds) + 1, value: $timestamp);
        return $this->database->resultset();
    }

    public function markBatchAsUndone(string $timestamp): void
    {
        $this->run('price-history-mark-batch-undone.sql', [':timestamp' => $timestamp]);
    }

    /** @return array<string, mixed>|null */
    public function getManualRecord(int $id): ?array
    {
        return $this->fetchOne('price-history-get-manual.sql', [':id' => $id]);
    }

    public function hasNewerChanges(int $productId, string $changedAt): bool
    {
        $rows = $this->fetchAll('price-history-check-newer.sql', [
            ':product_id' => $productId,
            ':changed_at' => $changedAt,
        ]);
        return !empty($rows);
    }

    public function markRecordAsUndone(int $id): void
    {
        $this->run('price-history-mark-undone.sql', [':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getByProduct(int $productId): array
    {
        return $this->fetchAll('price-history-get-by-product.sql', [':product_id' => $productId]);
    }

    public function insertManual(int $productId, float $oldPrice, float $newPrice, mixed $oldRrp, mixed $newRrp, int $userId): void
    {
        $this->run('price-history-insert.sql', [
            ':product_id' => $productId,
            ':old_price' => $oldPrice,
            ':new_price' => $newPrice,
            ':old_rrp' => $oldRrp,
            ':new_rrp' => $newRrp,
            ':user_id' => $userId,
        ]);
    }

    public function insertImport(int $productId, float $oldPrice, float $newPrice, mixed $oldRrp, mixed $newRrp, int $userId): void
    {
        $this->run('price-history-insert-import.sql', [
            ':product_id' => $productId,
            ':old_price' => $oldPrice,
            ':new_price' => $newPrice,
            ':old_rrp' => $oldRrp,
            ':new_rrp' => $newRrp,
            ':user_id' => $userId,
        ]);
    }
}
