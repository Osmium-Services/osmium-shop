<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Models;

class CheckoutBasket extends CheckoutModel
{
    public function findActive(string $sessionId): ?array
    {
        return $this->fetchOne('basket-find-active.sql', [':session_id' => $sessionId]);
    }

    public function create(string $sessionId): int
    {
        $this->run('basket-create.sql', [':session_id' => $sessionId]);

        return (int)$this->database->lastInsertId();
    }

    public function touch(int $basketId): void
    {
        $this->run('basket-touch.sql', [':id' => $basketId]);
    }

    public function countItems(string $sessionId): int
    {
        $row = $this->fetchOne('basket-count-items.sql', [':session_id' => $sessionId]);

        return (int)($row['item_count'] ?? 0);
    }

    public function markConverted(string $sessionId): bool
    {
        $this->run('basket-mark-converted.sql', [':session_id' => $sessionId]);

        return $this->database->rowCount() > 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(int $basketId): array
    {
        return $this->fetchAll('basket-items-get.sql', [':basket_id' => $basketId]);
    }

    public function findExistingItem(int $basketId, int $productId, ?string $productOptions, ?string $optionValueIds): ?array
    {
        return $this->fetchOne('basket-item-find-existing.sql', [
            ':basket_id' => $basketId,
            ':product_id' => $productId,
            ':product_options' => $productOptions,
            ':option_value_ids' => $optionValueIds,
        ]);
    }

    /** @param array<string, mixed> $bindings every named parameter basket-item-insert.sql expects */
    public function insertItem(array $bindings): int
    {
        $this->run('basket-item-insert.sql', $bindings);

        return (int)$this->database->lastInsertId();
    }

    public function incrementItemQuantity(int $itemId, int $quantity): void
    {
        $this->run('basket-item-increment-qty.sql', [':id' => $itemId, ':quantity' => $quantity]);
    }

    public function updateItemQuantity(int $basketId, int $itemId, int $quantity): bool
    {
        $this->run('basket-item-update-qty.sql', [
            ':id' => $itemId,
            ':basket_id' => $basketId,
            ':quantity' => $quantity,
        ]);

        return $this->database->rowCount() > 0;
    }

    public function deleteItem(int $basketId, int $itemId): bool
    {
        $this->run('basket-item-delete.sql', [':id' => $itemId, ':basket_id' => $basketId]);

        return $this->database->rowCount() > 0;
    }

    public function getProductForBasket(int $productId): ?array
    {
        return $this->fetchOne('product-get-for-basket.sql', [':id' => $productId]);
    }

    /** An option value that belongs to this product, through a live option type. Null for any other product's value. */
    public function getOptionValueForProduct(int $valueId, int $productId): ?array
    {
        return $this->fetchOne('option-value-get-for-product.sql', [':id' => $valueId, ':product_id' => $productId]);
    }

    public function getOptionValue(int $valueId): ?array
    {
        return $this->fetchOne('option-value-get-by-id.sql', [':id' => $valueId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getOptionTypesForProduct(int $productId): array
    {
        return $this->fetchAll('option-types-for-product.sql', [':product_id' => $productId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getOptionTypeDependencies(int $productId): array
    {
        return $this->fetchAll('option-type-dependencies-for-product.sql', [':product_id' => $productId]);
    }
}
