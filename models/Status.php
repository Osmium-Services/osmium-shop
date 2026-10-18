<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class Status extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** Rows for a list definition's 'query' file in this model's SQL folder (see lists/statuses.php). */
    public function fetch(string $sqlFile): array
    {
        return $this->fetchAll($sqlFile);
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        return $this->fetchOne('status-get-by-id.sql', [':id' => $id]);
    }

    /**
     * @return int ID of the new status
     */
    public function insert(
        string $descrShort,
        string $descrLong,
        int $productAvailable,
        int $restocking,
        string $schemaRef,
        int $list,
        int $enabled,
    ): int {
        $this->run('status-insert.sql', [
            ':descr_short' => $descrShort,
            ':descr_long' => $descrLong,
            ':product_available' => $productAvailable,
            ':restocking' => $restocking,
            ':schema_ref' => $schemaRef,
            ':list' => $list,
            ':enabled' => $enabled,
        ]);

        return (int)$this->database->lastInsertId();
    }

    public function update(
        int $id,
        string $descrShort,
        string $descrLong,
        int $productAvailable,
        int $restocking,
        string $schemaRef,
        int $list,
        int $enabled,
    ): void {
        $this->run('status-update.sql', [
            ':id' => $id,
            ':descr_short' => $descrShort,
            ':descr_long' => $descrLong,
            ':product_available' => $productAvailable,
            ':restocking' => $restocking,
            ':schema_ref' => $schemaRef,
            ':list' => $list,
            ':enabled' => $enabled,
        ]);
    }

    public function softDelete(int $id): void
    {
        $this->run('status-soft-delete.sql', [':id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->run('status-restore.sql', [':id' => $id]);
    }

    public function hardDelete(int $id): void
    {
        $this->run('status-hard-delete.sql', [':id' => $id]);
    }

    /** Products not deleted that use this status. */
    public function countActiveProducts(int $id): int
    {
        $row = $this->fetchOne('status-count-active-products.sql', [':id' => $id]);

        return (int)($row['count'] ?? 0);
    }

    /** Products, including deleted ones, that use this status. */
    public function countAllProducts(int $id): int
    {
        $row = $this->fetchOne('status-count-all-products.sql', [':id' => $id]);

        return (int)($row['count'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAll(): array
    {
        return $this->fetchAll('status-list-all.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getForAssistant(): array
    {
        return $this->fetchAll('status-list-for-assistant.sql');
    }
}
