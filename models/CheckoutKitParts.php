<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Models\CheckoutBasket;

/**
 * CheckoutKitParts
 *
 * Which other products a kit line takes stock from. Each option type
 * contributes the linked product of the choice the customer made, or the
 * type's default product when they kept the default.
 */
class CheckoutKitParts
{
    public function __construct(private CheckoutBasket $basket) {}

    /**
     * @param array<int> $optionValueIds Selected productoptionvalues.id rows
     * @return array<int, array{product_id: int, option_value_id: ?int, quantity: int}> One entry per product, in option type order
     */
    public function resolve(int $productId, array $optionValueIds): array
    {
        $chosenValues = $this->loadChosenValues($optionValueIds);
        $chosenIds = \array_map(fn(array $v): int => (int)$v['id'], $chosenValues);
        $dependenciesByType = $this->dependenciesByType($productId);

        $parts = [];
        foreach ($this->basket->getOptionTypesForProduct($productId) as $type) {
            $typeId = (int)$type['id'];

            $typeIsHidden = !$this->typeApplies($dependenciesByType[$typeId] ?? [], $chosenIds);
            if ($typeIsHidden) continue; // The customer was never offered this type, so it takes no stock

            $chosenForType = \array_filter($chosenValues, fn(array $v): bool => (int)$v['option_type_id'] === $typeId);
            if ($chosenForType) {
                foreach ($chosenForType as $value) {
                    $isLinked = !empty($value['linked_product_id']);
                    if ($isLinked) $parts[] = $this->part((int)$value['linked_product_id'], (int)$value['id']);
                }
                continue;
            }

            $defaultIsLinked = !empty($type['default_linked_product_id']);
            if ($defaultIsLinked) $parts[] = $this->part((int)$type['default_linked_product_id'], null);
        }

        return $parts;
    }

    /**
     * @param array<int> $optionValueIds
     * @return array<int, array<string, mixed>> Known, undeleted values only, lowest id first
     */
    private function loadChosenValues(array $optionValueIds): array
    {
        $values = \array_filter(\array_map(
            fn(int $id): ?array => $this->basket->getOptionValue($id),
            \array_unique(\array_map('intval', $optionValueIds)),
        ));
        \usort($values, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

        return $values;
    }

    /** @return array<int, array<int>> Trigger value ids by option type id */
    private function dependenciesByType(int $productId): array
    {
        $grouped = [];
        foreach ($this->basket->getOptionTypeDependencies($productId) as $row) {
            $grouped[(int)$row['option_type_id']][] = (int)$row['depends_on_value_id'];
        }

        return $grouped;
    }

    /** @param array<int> $triggerValueIds @param array<int> $chosenIds */
    private function typeApplies(array $triggerValueIds, array $chosenIds): bool
    {
        $alwaysShown = $triggerValueIds === [];

        return $alwaysShown || (bool)\array_intersect($triggerValueIds, $chosenIds);
    }

    /** @return array{product_id: int, option_value_id: ?int, quantity: int} */
    private function part(int $productId, ?int $optionValueId): array
    {
        return ['product_id' => $productId, 'option_value_id' => $optionValueId, 'quantity' => 1];
    }
}
