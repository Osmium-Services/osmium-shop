<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class ProductOptionValue extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /** @return array<int, array<string, mixed>> */
    public function getForExport(): array
    {
        return $this->fetchAll('option-values-for-export.sql');
    }

    /** @return array<string, mixed>|null */
    public function getForImport(int $id): ?array
    {
        return $this->fetchOne('option-value-get-for-import.sql', [':id' => $id]);
    }

    public function updatePrice(int $id, mixed $premium, mixed $rrp, mixed $weight): void
    {
        $this->run('option-value-update-price.sql', [
            ':id' => $id,
            ':premium' => $premium,
            ':rrp' => $rrp,
            ':weight' => $weight,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listByProduct(int $productId): array
    {
        return $this->fetchAll('option-value-list-by-product.sql', [':product_id' => $productId]);
    }

    public function getNextSort(int $typeId): int
    {
        $row = $this->fetchOne('option-value-get-next-sort.sql', [':type_id' => $typeId]);
        return (int)($row['next_sort'] ?? 0);
    }

    public function insert(array $data): int
    {
        $sql = $this->loadSqlFile('option-value-insert.sql');
        $this->database->query($sql);
        $this->database->bind(':type_id', $data['type_id']);
        $this->database->bind(':descr', $data['descr']);
        $this->database->bind(':premium', $data['premium']);
        $this->database->bind(':rrp', $data['rrp']);
        $this->database->bind(':weight', $data['weight']);
        $this->database->bind(':reference', $data['reference']);
        $this->database->bind(':linked_id', $data['linked_id']);
        $this->database->bind(':linked_image_slot', $data['linked_image_slot']);
        $this->database->bind(':sort', $data['sort']);
        $this->database->execute();
        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, array $updates, array $bindings): void
    {
        $updateStr = \implode(', ', $updates);
        $prefix = $this->database->tablePrefix();
        $query = "UPDATE {$prefix}productoptionvalues SET {$updateStr} WHERE id = :id";
        $this->database->query($query);
        foreach ($bindings as $key => $value) {
            $this->database->bind(param: $key, value: $value);
        }
        $this->database->execute();
    }

    public function softDelete(int $id): void
    {
        $this->run('option-value-soft-delete.sql', [':id' => $id]);
    }

    public function softDeleteByType(int $typeId): void
    {
        $this->run('option-value-soft-delete-by-type.sql', [':type_id' => $typeId]);
    }

    public function hardDeleteByProduct(int $productId): void
    {
        $this->run('option-values-hard-delete-by-product.sql', [':product_id' => $productId]);
    }

    public function reorder(int $id, int $sort): void
    {
        $this->run('option-value-reorder.sql', [':id' => $id, ':sort' => $sort]);
    }
}
