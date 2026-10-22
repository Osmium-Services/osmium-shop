<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class ProductCategory extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    public function getAll(): array
    {
        return $this->fetchAll('category-list-with-counts.sql');
    }

    public function getRecent(): array
    {
        return $this->fetchAll('category-list-recent.sql');
    }

    public function getDeleted(): array
    {
        return $this->fetchAll('category-list-deleted.sql');
    }

    public function getPageLinks(): array
    {
        return $this->fetchAll('category-pages-list.sql');
    }

    public function getLatestLock(): ?array
    {
        return $this->fetchOne('category-lock-get-latest.sql');
    }

    public function getExpiredLocks(): array
    {
        return $this->fetchAll('category-lock-select-expired.sql');
    }

    public function deleteExpiredLocks(): void
    {
        $this->run('category-lock-delete-expired.sql');
    }

    public function truncateSnapshot(): void
    {
        $this->run('category-snapshot-truncate.sql');
    }

    public function truncateAssocSnapshot(): void
    {
        $this->run('category-assoc-snapshot-truncate.sql');
    }

    public function getProductsForOrdering(int $categoryId): array
    {
        return $this->fetchAll('category-products-for-ordering.sql', [':category_id' => $categoryId]);
    }

    public function updateProductSort(int $sort, int $productId, int $categoryId): void
    {
        $this->run('category-product-update-sort.sql', [
            ':sort' => $sort,
            ':product_id' => $productId,
            ':category_id' => $categoryId,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getForFilter(): array
    {
        return $this->fetchAll('category-list-for-filter.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getSimpleList(): array
    {
        return $this->fetchAll('category-list-simple.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getByProduct(int $productId): array
    {
        return $this->fetchAll('category-list-by-product.sql', [':product_id' => $productId]);
    }

    public function getPageId(int $id): ?int
    {
        $row = $this->fetchOne('category-get-page-id.sql', [':id' => $id]);
        return $row ? ((int)$row['page_id'] ?: null) : null;
    }

    public function getCurrentLock(): ?array
    {
        return $this->fetchOne('category-lock-get-current.sql');
    }

    public function hasLockByUser(int $userId): bool
    {
        return (bool)$this->fetchOne('category-lock-check-own.sql', [':user_id' => $userId]);
    }

    public function insertLock(int $userId, string $userName): void
    {
        $this->run('category-lock-insert.sql', [':user_id' => $userId, ':user_name' => $userName]);
    }

    public function refreshLock(int $userId): void
    {
        $this->run('category-lock-refresh.sql', [':user_id' => $userId]);
    }

    public function deleteLockByUser(int $userId): void
    {
        $this->run('category-lock-delete-by-user.sql', [':user_id' => $userId]);
    }

    public function copySnapshot(): void
    {
        $this->run('category-snapshot-copy.sql');
    }

    public function copyAssocSnapshot(): void
    {
        $this->run('category-assoc-snapshot-copy.sql');
    }

    public function countSnapshot(): int
    {
        $row = $this->fetchOne('category-snapshot-count.sql');
        return (int)($row['cnt'] ?? 0);
    }

    public function disableForeignKeys(): void
    {
        $this->run('system-fk-disable.sql');
    }

    public function enableForeignKeys(): void
    {
        $this->run('system-fk-enable.sql');
    }

    public function getById(int $id): ?array
    {
        return $this->fetchOne('category-get-by-id.sql', [':id' => $id]);
    }

    public function getNextSortRoot(): int
    {
        $row = $this->fetchOne('category-get-next-sort-root.sql');
        return (int)($row['next_sort'] ?? 0);
    }

    public function getNextSortChild(int $parentId): int
    {
        $row = $this->fetchOne('category-get-next-sort-child.sql', [':parent_id' => $parentId]);
        return (int)($row['next_sort'] ?? 0);
    }

    public function getPageByCategory(int $categoryId): ?array
    {
        return $this->fetchOne('category-page-get-by-category.sql', [':category_id' => $categoryId]);
    }

    public function insert(array $data): int
    {
        $sql = $this->loadSqlFile('category-insert.sql');
        $this->database->query($sql);
        $this->database->bind(':parent_id', $data['parent_id']);
        $this->database->bind(':page_id', $data['page_id']);
        $this->database->bind(':label', $data['label']);
        $this->database->bind(':title', $data['title']);
        $this->database->bind(':subtitle', $data['subtitle']);
        $this->database->bind(':description', $data['description']);
        $this->database->bind(':image', $data['image']);
        $this->database->bind(':social_image', $data['social_image']);
        $this->database->bind(':sort', $data['sort']);
        $this->database->execute();
        return (int)$this->database->lastInsertId();
    }

    public function insertPageLink(int $categoryId, int $pageId): void
    {
        $this->run('category-page-insert.sql', [':category_id' => $categoryId, ':page_id' => $pageId]);
    }

    public function isDescendant(int $id, int $parentId): bool
    {
        return (bool)$this->fetchOne('category-check-descendant.sql', [':id' => $id, ':parent_id' => $parentId]);
    }

    public function update(array $data): void
    {
        $this->run('category-update.sql', [
            ':id' => $data['id'],
            ':parent_id' => $data['parent_id'],
            ':page_id' => $data['page_id'],
            ':label' => $data['label'],
            ':title' => $data['title'],
            ':subtitle' => $data['subtitle'],
            ':description' => $data['description'],
            ':image' => $data['image'],
            ':social_image' => $data['social_image'],
        ]);
    }

    public function countChildren(int $id): int
    {
        $row = $this->fetchOne('category-count-children.sql', [':id' => $id]);
        return (int)($row['child_count'] ?? 0);
    }

    public function countProducts(int $id): int
    {
        $row = $this->fetchOne('category-count-products.sql', [':id' => $id]);
        return (int)($row['product_count'] ?? 0);
    }

    public function softDelete(int $id): void
    {
        $this->run('category-soft-delete.sql', [':id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->run('category-restore.sql', [':id' => $id]);
    }

    public function deleteAll(): void
    {
        $this->run('category-delete-all.sql');
    }

    public function restoreFromSnapshot(): void
    {
        $this->run('category-restore-from-snapshot.sql');
    }

    public function deleteAllAssoc(): void
    {
        $this->run('category-assoc-delete-all.sql');
    }

    public function restoreAssocFromSnapshot(): void
    {
        $this->run('category-assoc-restore-from-snapshot.sql');
    }

    public function deleteAssocByCategory(int $id): void
    {
        $this->run('category-assoc-delete-by-category.sql', [':id' => $id]);
    }

    public function hardDelete(int $id): void
    {
        $this->run('category-hard-delete.sql', [':id' => $id]);
    }

    public function reorderItem(int $id, int $sort, ?int $parentId): void
    {
        $this->run('category-reorder-item.sql', [':id' => $id, ':sort' => $sort, ':parent_id' => $parentId]);
    }
}
