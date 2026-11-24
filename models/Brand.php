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

    /** @return array<int, array<string, mixed>> products of this brand (binned too) whose description images mention the brand folder */
    public function descrImagesMentioning(int $id, string $brandPath): array
    {
        return $this->fetchAll('brand-descr-images.sql', [':id' => $id, ':like' => '%/shop/' . $brandPath . '/%']);
    }

    /** @param array{descr_image: ?string, descr2_image: ?string, descr3_image: ?string} $values */
    public function setDescrImages(int $productId, array $values): void
    {
        $this->run('brand-set-descr-images.sql', [
            ':id' => $productId,
            ':descr_image' => $values['descr_image'],
            ':descr2_image' => $values['descr2_image'],
            ':descr3_image' => $values['descr3_image'],
        ]);
    }

    public function setLogo(int $id, ?string $logo): void
    {
        $this->run('brand-set-logo.sql', [
            ':id' => $id,
            ':logo' => $logo,
        ]);
    }

    /** Link the brand to its own page (a pages row), or clear the link */
    public function setPageId(int $id, ?int $pageId): void
    {
        $this->run('brand-set-page.sql', [':id' => $id, ':page_id' => $pageId]);
    }

    /** @return array<int, array{id: int, name: string, page_id: int, deleted_at: ?string}> every brand that has a page, binned ones too */
    public function withPages(): array
    {
        return $this->fetchAll('brand-pages.sql');
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
