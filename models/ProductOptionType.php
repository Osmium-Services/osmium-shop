<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class ProductOptionType extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** @return array<int, array<string, mixed>> */
    public function listByProduct(int $productId): array
    {
        return $this->fetchAll('option-type-list-by-product.sql', [':product_id' => $productId]);
    }

    public function getNextSort(int $productId): int
    {
        $row = $this->fetchOne('option-type-get-next-sort.sql', [':product_id' => $productId]);
        return (int)($row['next_sort'] ?? 0);
    }

    public function insert(array $data): int
    {
        $sql = $this->loadSqlFile('option-type-insert.sql');
        $this->database->query($sql);
        $this->database->bind(':product_id', $data['product_id']);
        $this->database->bind(':type_label', $data['type_label']);
        $this->database->bind(':type_slug', $data['type_slug']);
        $this->database->bind(':default_descr', $data['default_descr']);
        $this->database->bind(':linked_id', $data['linked_id']);
        $this->database->bind(':default_linked_id', $data['default_linked_id']);
        $this->database->bind(':linked_descr', $data['linked_descr']);
        $this->database->bind(':linked_image_slot', $data['linked_image_slot']);
        $this->database->bind(':sort', $data['sort']);
        $this->database->execute();
        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, array $updates, array $bindings): void
    {
        $updateStr = \implode(', ', $updates);
        $prefix = $this->database->tablePrefix();
        $query = "UPDATE {$prefix}productoptiontypes SET {$updateStr} WHERE id = :id";
        $this->database->query($query);
        foreach ($bindings as $key => $value) {
            $this->database->bind(param: $key, value: $value);
        }
        $this->database->execute();
    }

    public function softDelete(int $id): void
    {
        $this->run('option-type-soft-delete.sql', [':id' => $id]);
    }

    public function hardDeleteByProduct(int $productId): void
    {
        $this->run('option-types-hard-delete-by-product.sql', [':product_id' => $productId]);
    }

    public function reorder(int $id, int $sort): void
    {
        $this->run('option-type-reorder.sql', [':id' => $id, ':sort' => $sort]);
    }

    /** Products that have at least one option type (for copy dropdown). */
    public function productsWithOptions(): array
    {
        return $this->fetchAll('products-with-options.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function listDependenciesByProduct(int $productId): array
    {
        return $this->fetchAll('option-dependency-list-by-product.sql', [':product_id' => $productId]);
    }

    public function insertDependency(int $typeId, int $valueId): void
    {
        $this->run('option-dependency-insert.sql', [':type_id' => $typeId, ':value_id' => $valueId]);
    }

    public function deleteDependenciesByType(int $typeId): void
    {
        $this->run('option-dependency-delete-by-type.sql', [':type_id' => $typeId]);
    }

    public function deleteDependenciesByTypeValues(int $typeId): void
    {
        $this->run('option-dependency-delete-by-type-values.sql', [':type_id' => $typeId]);
    }

    public function deleteDependenciesByValue(int $valueId): void
    {
        $this->run('option-dependency-delete-by-value.sql', [':value_id' => $valueId]);
    }

    public function deleteDependenciesByProduct(int $productId): void
    {
        $this->run('option-dependency-delete-by-product.sql', [':product_id' => $productId]);
    }
}
