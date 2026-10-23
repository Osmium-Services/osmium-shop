<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\Strings;
use Osmium\Core\Models\Model;
use Osmium\Modules\Admin\Models\ProductCategory;

/**
 * AdminShopCategories - Product category management with locking and backup functionality
 */
class AdminShopCategories
{
    public function __construct(
        private ProductCategory $category,
        private AdminPages $pages,
        private Strings $strings,
    ) {}

    /**
     * Clean up expired category locks (30 minutes)
     */
    public function cleanupExpiredLocks(): void
    {
        $expiredLocks = $this->category->getExpiredLocks();

        $noExpiredLocks = empty($expiredLocks);
        if ($noExpiredLocks) return;

        $this->category->deleteExpiredLocks();
        $this->category->truncateSnapshot();
        $this->category->truncateAssocSnapshot();
    }

    /**
     * Get all categories with product counts
     */
    public function getAll(): array
    {
        return $this->category->getAll();
    }

    /**
     * Check if categories are locked by another user
     * @return array|false Lock info if locked by other, false if not
     */
    public function getLockByOther(int $currentUserId)
    {
        $lock = $this->category->getCurrentLock();
        $lockedByOther = $lock && $lock['user_id'] != $currentUserId;
        if ($lockedByOther) return $lock;
        return false;
    }

    /**
     * Check if current user has a category backup
     */
    public function hasOwnBackup(int $userId): bool
    {
        return $this->category->hasLockByUser($userId);
    }

    /**
     * Ensure category backup exists for user (create if not, refresh timeout if exists)
     */
    public function ensureBackup(int $userId, string $userName): void
    {
        $hasOwnBackup = $this->hasOwnBackup($userId);
        if ($hasOwnBackup) {
            $this->category->refreshLock($userId);
            return;
        }

        $this->category->insertLock($userId, $userName);

        $this->category->truncateSnapshot();
        $this->category->copySnapshot();

        $this->category->truncateAssocSnapshot();
        $this->category->copyAssocSnapshot();
    }

    /**
     * Check category lock status
     * @return array ['locked' => bool, 'user_name' => ?string, 'has_backup' => ?bool]
     */
    public function checkLock(int $currentUserId): array
    {
        $lock = $this->getLockByOther($currentUserId);
        if ($lock) {
            return [
                'locked' => true,
                'user_name' => $lock['user_name'],
                'created_at' => $lock['created_at'],
            ];
        }
        return [
            'locked' => false,
            'has_backup' => $this->hasOwnBackup($currentUserId),
        ];
    }

    /**
     * Acquire category lock
     * @return array ['locked' => bool, 'user_name' => ?string, 'backup_created' => ?bool]
     */
    public function acquireLock(int $userId, string $userName): array
    {
        $lock = $this->getLockByOther($userId);
        if ($lock) return ['locked' => true, 'user_name' => $lock['user_name']];

        $hadBackup = $this->hasOwnBackup($userId);
        $this->ensureBackup($userId, $userName);

        return ['locked' => false, 'backup_created' => !$hadBackup];
    }

    /**
     * Release category lock and clear snapshots
     */
    public function releaseLock(int $userId): void
    {
        $this->category->deleteLockByUser($userId);
        $this->category->truncateSnapshot();
        $this->category->truncateAssocSnapshot();
    }

    /**
     * Restore categories from backup snapshot
     * @throws \Exception on failure
     */
    public function restoreBackup(int $userId): void
    {
        $noBackup = !$this->hasOwnBackup($userId);
        if ($noBackup) throw new \Exception('No backup found');

        $snapshotCount = $this->category->countSnapshot();
        $snapshotEmpty = $snapshotCount === 0;
        if ($snapshotEmpty) throw new \Exception('Snapshot is empty - cannot restore');

        $this->category->database->beginTransaction();

        try {
            $this->category->disableForeignKeys();

            $this->category->deleteAll();
            $this->category->restoreFromSnapshot();

            $this->category->deleteAllAssoc();
            $this->category->restoreAssocFromSnapshot();

            $this->category->enableForeignKeys();

            $this->category->database->endTransaction();

            $this->releaseLock($userId);

        } catch (\Exception $e) {
            $this->category->database->cancelTransaction();
            $this->category->enableForeignKeys();
            throw new \Exception('Restore failed: ' . $e->getMessage());
        }
    }

    /**
     * Add a new category
     * @return int New category ID
     */
    public function add(int $userId, string $userName, array $input): int
    {
        $this->requireLock($userId, $userName);

        $label = \trim($input['label'] ?? '');
        $title = \trim($input['title'] ?? '');
        $subtitle = \trim($input['subtitle'] ?? '');
        $description = \trim($input['description'] ?? '');
        $parentId = $input['parent_id'] ?? null;
        $image = \trim($input['image'] ?? '');
        $socialImage = \trim($input['social_image'] ?? '');

        if (!$label) throw new \Exception('Label is required');

        $nextSort = $parentId
            ? $this->category->getNextSortChild((int)$parentId)
            : $this->category->getNextSortRoot();

        $parentPageId = null;
        $urlPrefix = '';
        if ($parentId) {
            $parentResult = $this->category->getPageByCategory((int)$parentId);
            $parentHasPage = $parentResult && $parentResult['page_id'];
            if ($parentHasPage) {
                $parentPageId = (int)$parentResult['page_id'];
                $parentPage = Model::pagesById()[$parentPageId] ?? null;
                if ($parentPage) $urlPrefix = $parentPage['url'] . '/';
            }
        }

        $url = $urlPrefix . $this->strings->stringToPath($label);
        $pageId = $this->pages->create(input: [
            'url' => $url,
            'parent_page_id' => $parentPageId,
            'title' => $title ?: $label,
            'description' => $subtitle ?: $description,
            'robots' => 'noindex,follow',
        ]);

        $categoryId = $this->category->insert([
            'parent_id' => $parentId ?: null,
            'page_id' => $pageId,
            'label' => $label,
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'image' => $image,
            'social_image' => $socialImage,
            'sort' => $nextSort,
        ]);

        $this->category->insertPageLink($categoryId, $pageId);

        return $categoryId;
    }

    /**
     * Update a category
     */
    public function update(int $userId, string $userName, array $input): void
    {
        $this->requireLock($userId, $userName);

        $id = (int)($input['id'] ?? 0);
        $label = \trim($input['label'] ?? '');
        $title = \trim($input['title'] ?? '');
        $subtitle = \trim($input['subtitle'] ?? '');
        $description = \trim($input['description'] ?? '');
        $parentId = $input['parent_id'] ?? null;
        $image = \trim($input['image'] ?? '');
        $socialImage = \trim($input['social_image'] ?? '');

        if (!$id) throw new \Exception('Category ID is required');
        if (!$label) throw new \Exception('Label is required');

        $existing = $this->category->getById($id);
        if (!$existing) throw new \Exception('Category not found');

        if ($parentId) {
            $parentId = (int)$parentId;
            $selfParent = $parentId === $id;
            if ($selfParent) throw new \Exception('Category cannot be its own parent');

            $isDescendant = $this->category->isDescendant($id, $parentId);
            if ($isDescendant) {
                throw new \Exception('Cannot set parent to a descendant category');
            }
        }

        $pageResult = $this->category->getPageByCategory($id);
        $pageId = $pageResult ? (int)$pageResult['page_id'] : null;

        if ($pageResult) {
            $parentPageId = null;
            $urlPrefix = '';
            if ($parentId) {
                $parentCategoryPage = $this->category->getPageByCategory($parentId);
                $parentCategoryHasPage = $parentCategoryPage && $parentCategoryPage['page_id'];
                if ($parentCategoryHasPage) {
                    $parentPageId = (int)$parentCategoryPage['page_id'];
                    $parentPage = Model::pagesById()[$parentPageId] ?? null;
                    if ($parentPage) $urlPrefix = $parentPage['url'] . '/';
                }
            }

            $this->pages->update(id: $pageId, input: [
                'url' => $urlPrefix . $this->strings->stringToPath($label),
                'parent_page_id' => $parentPageId,
                'title' => $title ?: $label,
                'description' => $subtitle ?: $description,
                'robots' => 'noindex,follow',
            ]);
        }

        $this->category->update([
            'id' => $id,
            'parent_id' => $parentId ?: null,
            'page_id' => $pageId,
            'label' => $label,
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'image' => $image,
            'social_image' => $socialImage,
        ]);
    }

    /**
     * Soft delete a category
     */
    public function delete(int $userId, string $userName, int $categoryId): void
    {
        $this->requireLock($userId, $userName);

        if (!$categoryId) throw new \Exception('Category ID is required');

        $childCount = $this->category->countChildren($categoryId);
        $hasChildren = $childCount > 0;
        if ($hasChildren) {
            $suffix = $childCount === 1 ? 'y' : 'ies';
            throw new \Exception("Cannot delete: {$childCount} subcategor{$suffix} in this category");
        }

        $productCount = $this->category->countProducts($categoryId);
        $hasProducts = $productCount > 0;
        if ($hasProducts) {
            throw new \Exception("Cannot delete: {$productCount} product(s) assigned to this category");
        }

        $this->category->softDelete($categoryId);
    }

    /**
     * Restore a soft-deleted category
     */
    public function restore(int $userId, string $userName, int $categoryId): void
    {
        $this->requireLock($userId, $userName);

        if (!$categoryId) throw new \Exception('Category ID is required');

        $this->category->restore($categoryId);
    }

    /**
     * Permanently delete a category (admin only)
     */
    public function permanentDelete(int $userId, string $userName, int $categoryId): void
    {
        $this->requireLock($userId, $userName);

        if (!$categoryId) throw new \Exception('Category ID is required');

        $this->category->deleteAssocByCategory($categoryId);
        $this->category->hardDelete($categoryId);
    }

    /**
     * Reorder categories
     */
    public function reorder(int $userId, string $userName, array $items): void
    {
        $this->requireLock($userId, $userName);

        $itemsEmpty = empty($items);
        if ($itemsEmpty) throw new \Exception('No items to reorder');

        foreach ($items as $item) {
            $itemParentId = $item['parent_id'] ? (int)$item['parent_id'] : null;
            $this->category->reorderItem((int)$item['id'], (int)$item['sort'], $itemParentId);
        }
    }

    /**
     * Helper: Require category lock or throw
     */
    private function requireLock(int $userId, string $userName): void
    {
        $lock = $this->getLockByOther($userId);
        if ($lock) {
            throw new \Exception("Categories are being edited by {$lock['user_name']}. Please try again later.");
        }

        $this->ensureBackup($userId, $userName);
    }
}
