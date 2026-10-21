<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class Discount extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** Rows for a list definition's 'query' file in this model's SQL folder (see lists/discounts.php). */
    public function fetch(string $sqlFile): array
    {
        return $this->fetchAll($sqlFile);
    }

    /** Columns written by insert() and update(). */
    private const FIELDS = [
        'name', 'type', 'code', 'value_type', 'value',
        'starts_at', 'ends_at', 'usage_limit', 'usage_limit_per_customer', 'min_spend_inc_tax', 'active',
    ];

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        return $this->fetchOne('discount-get-by-id.sql', [':id' => $id]);
    }

    /** Whether any discount, in the bin or not, already holds this coupon code. The database keeps codes unique across both. */
    public function codeExistsAnywhere(string $code): bool
    {
        return $this->fetchOne('discount-get-by-code-any.sql', [':code' => $code]) !== null;
    }

    /** @return array<int, array<string, mixed>> live discounts with their product counts, newest first */
    public function listWithCounts(): array
    {
        return $this->fetchAll('discount-list-with-counts.sql');
    }

    /** @return array<int, array<string, mixed>> discounts in the bin, newest deletion first */
    public function listDeleted(): array
    {
        return $this->fetchAll('discount-list-deleted.sql');
    }

    /** ID of the live discount using this coupon code, or null. */
    public function findIdByCode(string $code): ?int
    {
        $row = $this->fetchOne('discount-get-by-code.sql', [':code' => $code]);

        return $row === null ? null : (int)$row['id'];
    }

    /**
     * @param array<string, mixed> $fields Values keyed by column name (see FIELDS)
     * @return int ID of the new discount
     */
    public function insert(array $fields): int
    {
        $this->run('discount-insert.sql', $this->bindingsFor($fields));

        return (int)$this->database->lastInsertId();
    }

    /** @param array<string, mixed> $fields Values keyed by column name (see FIELDS) */
    public function update(int $id, array $fields): void
    {
        $this->run('discount-update.sql', [':id' => $id] + $this->bindingsFor($fields));
    }

    public function softDelete(int $id): void
    {
        $this->run('discount-soft-delete.sql', [':id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->run('discount-restore.sql', [':id' => $id]);
    }

    /** Removes the discount and its product, category and brand links. */
    public function hardDelete(int $id): void
    {
        $this->run('discount-products-delete-for-discount.sql', [':discount_id' => $id]);
        $this->run('discount-categories-delete-for-discount.sql', [':discount_id' => $id]);
        $this->run('discount-brands-delete-for-discount.sql', [':discount_id' => $id]);
        $this->run('discount-hard-delete.sql', [':id' => $id]);
    }

    /** @return array<int, int> */
    public function getProductIds(int $discountId): array
    {
        $rows = $this->fetchAll('discount-products-get-for-discount.sql', [':discount_id' => $discountId]);

        return \array_map(static fn (array $row): int => (int)$row['product_id'], $rows);
    }

    /** @param array<int, int|string> $productIds */
    public function replaceProducts(int $discountId, array $productIds): void
    {
        $this->run('discount-products-delete-for-discount.sql', [':discount_id' => $discountId]);

        foreach ($productIds as $productId) {
            $this->run('discount-products-insert.sql', [
                ':discount_id' => $discountId,
                ':product_id' => $productId,
            ]);
        }
    }

    /** @return array<int, int> */
    public function getCategoryIds(int $discountId): array
    {
        $rows = $this->fetchAll('discount-categories-get-for-discount.sql', [':discount_id' => $discountId]);

        return \array_map(static fn (array $row): int => (int)$row['category_id'], $rows);
    }

    /** @param array<int, int|string> $categoryIds */
    public function replaceCategories(int $discountId, array $categoryIds): void
    {
        $this->run('discount-categories-delete-for-discount.sql', [':discount_id' => $discountId]);

        foreach ($categoryIds as $categoryId) {
            $this->run('discount-categories-insert.sql', [':discount_id' => $discountId, ':category_id' => $categoryId]);
        }
    }

    /** @return array<int, int> */
    public function getBrandIds(int $discountId): array
    {
        $rows = $this->fetchAll('discount-brands-get-for-discount.sql', [':discount_id' => $discountId]);

        return \array_map(static fn (array $row): int => (int)$row['brand_id'], $rows);
    }

    /** @param array<int, int|string> $brandIds */
    public function replaceBrands(int $discountId, array $brandIds): void
    {
        $this->run('discount-brands-delete-for-discount.sql', [':discount_id' => $discountId]);

        foreach ($brandIds as $brandId) {
            $this->run('discount-brands-insert.sql', [':discount_id' => $discountId, ':brand_id' => $brandId]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function bindingsFor(array $fields): array
    {
        $bindings = [];
        foreach (self::FIELDS as $field) {
            $bindings[':' . $field] = $fields[$field] ?? null;
        }

        return $bindings;
    }
}
