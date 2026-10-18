<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Models\CheckoutBasket;

require_once __DIR__ . '/CheckoutKitParts.php';
require_once __DIR__ . '/CheckoutGuards.php';

/**
 * CheckoutBasketService
 *
 * This module's own basket, resolved against real sg2_products pricing.
 *
 * Phase-1 scope: base product price only. Option premiums (colour, size
 * add-ons, etc.) are not recalculated here — a line stores whatever option
 * label the caller passes as an opaque string.
 */
class CheckoutBasketService
{
    public function __construct(private CheckoutBasket $basket) {}

    public function getOrCreateBasket(string $sessionId): int
    {
        $existing = $this->findActiveBasket($sessionId);
        if ($existing) return (int)$existing['id'];

        return $this->basket->create($sessionId);
    }

    /**
     * @param array<int> $optionValueIds Selected productoptionvalues.id rows —
     *        premiums are looked up and added server-side, never trusted from
     *        the client, so a tampered premium can't undercharge an order.
     * @throws ShopOrderException When the product does not exist or is deleted
     * @return int The basket item id
     */
    public function addLine(int $basketId, int $productId, int $quantity, ?string $productOptions = null, array $optionValueIds = []): int
    {
        $product = $this->loadProduct($productId);
        if ($product === null) throw new ShopOrderException('That product is no longer available.');

        $optionValues = \array_filter(\array_map(
            fn(int $id): ?array => $this->basket->getOptionValueForProduct($id, $productId), // Only this product's own options: another product's discount option cannot be borrowed
            \array_values(\array_unique($optionValueIds)),
        ));

        $typeIds = \array_map(fn(array $v): int => (int)$v['option_type_id'], $optionValues);
        $twoOfOneType = \count($typeIds) !== \count(\array_unique($typeIds));
        if ($twoOfOneType) throw new ShopOrderException('Please choose only one option of each type.');

        \usort($optionValues, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']); // Same options in any pick order must give the same label and the same line

        $premiumTotal = \array_sum(\array_map(fn(array $v): float => (float)$v['option_premium'], $optionValues));
        $weightTotal = \array_sum(\array_map(fn(array $v): float => (float)$v['option_packed_weight'], $optionValues));
        // Reference code included alongside the label — dispatch picks the
        // physical item from this, not the customer-facing description alone.
        $optionDescriptions = \array_map(function (array $v): string {
            $ref = (string)($v['option_reference'] ?? '');
            $refSuffix = $ref !== '' ? " ({$ref})" : '';
            return $v['option_descr'] . $refSuffix;
        }, $optionValues);

        $productOptionsLabel = $optionDescriptions ? \implode(', ', $optionDescriptions) : \trim((string)$productOptions);
        $productOptionsLabel = $productOptionsLabel === '' ? null : $productOptionsLabel; // A blank label and no label are the same plain line

        // The same product with a different option is a different line: merging would price the new unit at the old line's price.
        $chosenIds = \array_map(fn(array $v): int => (int)$v['id'], $optionValues);
        $optionValueIdsKey = $chosenIds ? \implode(',', $chosenIds) : null;

        $existing = $this->findExistingItem(basketId: $basketId, productId: $productId, productOptions: $productOptionsLabel, optionValueIds: $optionValueIdsKey);

        $this->askGuards(action: 'add', basketId: $basketId, productId: $productId, optionValueIds: $optionValueIdsKey, quantity: $quantity + (int)($existing['quantity'] ?? 0), excludeItemId: $existing ? (int)$existing['id'] : null);

        if ($existing) {
            $newQuantity = (int)$existing['quantity'] + $quantity;
            $this->setQuantity(itemId: (int)$existing['id'], quantity: $newQuantity);
            $this->touchBasket($basketId);
            return (int)$existing['id'];
        }

        $itemId = $this->basket->insertItem([
            ':basket_id' => $basketId,
            ':product_id' => $productId,
            ':product_ref' => (string)($product['reference'] ?? ''),
            ':product_title' => (string)$product['model_name'],
            ':product_options' => $productOptionsLabel,
            ':option_value_ids' => $optionValueIdsKey,
            ':quantity' => $quantity,
            ':unit_price_exc_tax' => \max(0.0, (float)$product['price_exc_tax'] + $premiumTotal), // Never below nothing, whatever the premiums add up to
            ':weight' => (float)$product['packed_weight'] + $weightTotal,
        ]);

        $this->touchBasket($basketId);

        return $itemId;
    }

    /** Whether the product can still be bought: it exists, is not deleted and its status is marked available. */
    public function isPurchasable(int $productId): bool
    {
        return $this->loadProduct($productId) !== null;
    }

    public function updateQuantity(int $basketId, int $itemId, int $quantity): bool
    {
        $quantityInvalid = $quantity < 1;
        if ($quantityInvalid) return $this->removeLine(basketId: $basketId, itemId: $itemId);

        $this->guardQuantityIncrease(basketId: $basketId, itemId: $itemId, quantity: $quantity);

        $updated = $this->basket->updateItemQuantity($basketId, $itemId, $quantity);
        if ($updated) $this->touchBasket($basketId);

        return $updated;
    }

    public function removeLine(int $basketId, int $itemId): bool
    {
        $removed = $this->basket->deleteItem($basketId, $itemId);
        if ($removed) $this->touchBasket($basketId);

        return $removed;
    }

    /**
     * @return array{lines: array}
     */
    public function getLines(int $basketId): array
    {
        $rows = $this->basket->getItems($basketId);

        $lines = \array_map(callback: function (array $row): array {
            $row['option_signature'] = (string)($row['product_options'] ?? ''); // No option-premium pricing in this phase, so the signature is just the label
            $row['unit_price_exc_tax'] = (float)$row['unit_price_exc_tax']; // PDO returns DECIMAL columns as strings; downstream consumers require float
            $row['weight'] = (float)$row['weight'];
            return $row;
        }, array: $rows);

        return ['lines' => $lines];
    }

    /**
     * Total item quantity for a session's active basket, without creating
     * one — for read-only display (the header cart icon) on pages that
     * shouldn't spawn an empty basket row just by being visited.
     */
    public function countItems(string $sessionId): int
    {
        return $this->basket->countItems($sessionId);
    }

    /**
     * Every line in a basket with the products it takes stock from, for services
     * that need to total demand across lines.
     *
     * @return array<int, array{id: int, product_id: int, quantity: int, option_value_ids: ?string, parts: array}>
     */
    public function getLinesWithParts(int $basketId): array
    {
        return \array_map(fn(array $line): array => [
            'id' => (int)$line['id'],
            'product_id' => (int)$line['product_id'],
            'quantity' => (int)$line['quantity'],
            'option_value_ids' => $line['option_value_ids'],
            'parts' => $this->partsForLine((int)$line['product_id'], $line['option_value_ids']),
        ], $this->getLines($basketId)['lines']);
    }

    /**
     * The products a basket line takes stock from, resolved from the option
     * IDs kept on the line.
     *
     * @return array<int, array{product_id: int, option_value_id: ?int, quantity: int}>
     */
    public function partsForLine(int $productId, ?string $optionValueIds): array
    {
        $ids = $optionValueIds === null || $optionValueIds === ''
            ? []
            : \array_map('intval', \explode(',', $optionValueIds));

        return (new CheckoutKitParts($this->basket))->resolve($productId, $ids);
    }

    public function markConverted(string $sessionId): bool
    {
        return $this->basket->markConverted($sessionId);
    }

    /**
     * Ask installed services whether this basket change is allowed. They refuse
     * by throwing a ShopOrderException.
     */
    private function askGuards(string $action, int $basketId, int $productId, ?string $optionValueIds, int $quantity, ?int $excludeItemId): void
    {
        $otherLines = \array_values(\array_filter(
            $this->getLinesWithParts($basketId),
            fn(array $line): bool => $line['id'] !== $excludeItemId,
        ));

        (new CheckoutGuards($this->basket->database))->check('basket.line.check', [
            'action' => $action,
            'basketId' => $basketId,
            'productId' => $productId,
            'quantity' => $quantity,
            'optionValueIds' => $optionValueIds === null ? [] : \array_map('intval', \explode(',', $optionValueIds)),
            'parts' => $this->partsForLine($productId, $optionValueIds),
            'otherLines' => $otherLines,
        ]);
    }

    /** Lowering a quantity is always allowed: a customer must be able to shrink an over-stocked basket. */
    private function guardQuantityIncrease(int $basketId, int $itemId, int $quantity): void
    {
        $line = null;
        foreach ($this->getLines($basketId)['lines'] as $candidate) {
            if ((int)$candidate['id'] === $itemId) $line = $candidate;
        }

        $lineMissing = $line === null;
        if ($lineMissing) return;

        $isIncrease = $quantity > (int)$line['quantity'];
        if (!$isIncrease) return;

        $this->askGuards(action: 'update', basketId: $basketId, productId: (int)$line['product_id'], optionValueIds: $line['option_value_ids'], quantity: $quantity, excludeItemId: $itemId);
    }

    private function findActiveBasket(string $sessionId): ?array
    {
        return $this->basket->findActive($sessionId);
    }

    private function findExistingItem(int $basketId, int $productId, ?string $productOptions, ?string $optionValueIds): ?array
    {
        return $this->basket->findExistingItem($basketId, $productId, $productOptions, $optionValueIds);
    }

    private function setQuantity(int $itemId, int $quantity): void
    {
        $this->basket->incrementItemQuantity($itemId, $quantity);
    }

    private function touchBasket(int $basketId): void
    {
        $this->basket->touch($basketId);
    }

    private function loadProduct(int $productId): ?array
    {
        return $this->basket->getProductForBasket($productId);
    }
}
