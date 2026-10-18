<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class Brand extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** Rows for a list definition's 'query' file in this model's SQL folder (see lists/brands.php). */
    public function fetch(string $sqlFile): array
    {
        return $this->fetchAll($sqlFile);
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        return $this->fetchOne('brand-get-by-id.sql', [':id' => $id]);
    }

    /**
     * @return int ID of the new brand
     */
    public function insert(string $name, ?string $pageUrl, ?string $infoExternal): int
    {
        $this->run('brand-insert.sql', [
            ':name' => $name,
            ':page_url' => $pageUrl,
            ':info_external' => $infoExternal,
        ]);

        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, string $name, ?string $pageUrl, ?string $infoExternal): void
    {
        $this->run('brand-update.sql', [
            ':id' => $id,
            ':name' => $name,
            ':page_url' => $pageUrl,
            ':info_external' => $infoExternal,
        ]);
    }

    public function softDelete(int $id): void
    {
        $this->run('brand-soft-delete.sql', [':id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->run('brand-restore.sql', [':id' => $id]);
    }

    public function hardDelete(int $id): void
    {
        $this->run('brand-hard-delete.sql', [':id' => $id]);
    }

    /** Products not deleted that use this brand. */
    public function countActiveProducts(int $id): int
    {
        $row = $this->fetchOne('brand-count-active-products.sql', [':id' => $id]);

        return (int)($row['count'] ?? 0);
    }

    /** Products, including deleted ones, that use this brand. */
    public function countAllProducts(int $id): int
    {
        $row = $this->fetchOne('brand-count-all-products.sql', [':id' => $id]);

        return (int)($row['count'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function getActive(): array
    {
        return $this->fetchAll('brand-list-active.sql');
    }

    /** @return array<int, array<string, mixed>> live brands with how many live products use each, by name */
    public function listWithCounts(): array
    {
        return $this->fetchAll('brand-list-with-counts.sql');
    }

    /** @return array<int, array<string, mixed>> brands in the bin, newest deletion first */
    public function listDeleted(): array
    {
        return $this->fetchAll('brand-list-deleted.sql');
    }

    /** True when another live brand already has this name, ignoring case. Pass 0 as the id for a new brand. */
    public function nameTaken(string $name, int $exceptId): bool
    {
        return $this->fetchOne('brand-name-exists.sql', [':name' => $name, ':id' => $exceptId]) !== null;
    }

    public function getName(int $id): string
    {
        $row = $this->fetchOne('brand-get-name.sql', [':id' => $id]);
        return $row['name'] ?? '';
    }
}
