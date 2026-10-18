<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class ProductCategoryAssignment extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    public function exists(int $productId, int $categoryId): bool
    {
        return $this->fetchOne('product-category-check-exists.sql', [
            ':product_id' => $productId,
            ':category_id' => $categoryId,
        ]) !== null;
    }

    public function insert(int $productId, int $categoryId): void
    {
        $this->run('product-category-insert.sql', [
            ':product_id' => $productId,
            ':category_id' => $categoryId,
        ]);
    }

    public function delete(int $productId, int $categoryId): void
    {
        $this->run('product-category-delete.sql', [
            ':product_id' => $productId,
            ':category_id' => $categoryId,
        ]);
    }
}
