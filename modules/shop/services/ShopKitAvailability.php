<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

/**
 * ShopKitAvailability
 *
 * Whether a kit can be bought as a whole, and when it is back, worked out from
 * the stock status of the products behind each option type's choices.
 *
 * A kit is unavailable when an option type that is always shown has no choice
 * left: its default and every value are held back by an out-of-stock product.
 * A type that only appears after another choice never blocks the kit, because
 * the customer can pick a way round it.
 */
class ShopKitAvailability
{
    /**
     * @param array<int, array<string, mixed>> $optionTypes Processed types from ShopProductService::buildOptionTypes()
     * @param string $today Y-m-d; restock dates before it are overdue and are not promised
     * @return array{available: bool, restockDate: ?string, blockingTypeIds: array<int>}
     */
    public function evaluate(array $optionTypes, string $today): array
    {
        $blockingTypeIds = [];
        $typeDates = [];

        foreach ($optionTypes as $type) {
            $alwaysShown = empty($type['dependsOnValueIds']);
            if (!$alwaysShown) continue;

            $choices = $this->choiceOutcomes($type, $today);
            $hasAvailableChoice = \in_array(true, \array_column($choices, 'available'), true);
            if ($hasAvailableChoice) continue;

            $blockingTypeIds[] = $type['id'];
            $typeDates[] = $this->earliest(\array_column($choices, 'date'));
        }

        return [
            'available' => $blockingTypeIds === [],
            'restockDate' => $this->kitRestockDate($typeDates),
            'blockingTypeIds' => $blockingTypeIds,
        ];
    }

    /**
     * Fold a kit's availability into the product page data. A kit with no
     * choice left shows as out of stock like any other product, unless the
     * product is already out of stock itself, in which case its own status
     * and date win.
     *
     * @param array<string, mixed> $productData Needs isAvailable, restockDate and schemaRef
     * @param array{available: bool, restockDate: ?string, blockingTypeIds: array<int>} $kit Result of evaluate()
     * @return array<string, mixed> The product data with kitAvailable and kitRestockDate added
     */
    public function applyTo(array $productData, array $kit): array
    {
        $productData['kitAvailable'] = $kit['available'];
        $productData['kitRestockDate'] = $kit['restockDate'];

        $kitIsFine = $kit['available'];
        $productAlreadyOut = !$productData['isAvailable'];
        if ($kitIsFine || $productAlreadyOut) return $productData;

        $productData['isAvailable'] = 0;
        $productData['restockDate'] = $kit['restockDate'];
        $productData['schemaRef'] = 'OutOfStock';

        return $productData;
    }

    /**
     * One entry per choice of the type: whether it can be chosen, and when it can if not.
     *
     * @return array<int, array{available: bool, date: ?string}>
     */
    private function choiceOutcomes(array $type, string $today): array
    {
        $typeLinkHeldBack = $this->heldBack($type['linkedProductId'] ?? null, $type['linkedProductAvailable'] ?? true, $type['linkedProductRestockDate'] ?? null, $today);

        $choices = [
            $this->outcome([$this->heldBack($type['defaultLinkedProductId'] ?? null, $type['defaultAvailable'] ?? true, $type['defaultRestockDate'] ?? null, $today)]),
        ]; // The default is not held back by the type's own link: that link only disables the other choices

        foreach ($type['values'] ?? [] as $value) {
            $choices[] = $this->outcome([
                $typeLinkHeldBack,
                $this->heldBack($value['linkedProductId'] ?? null, $value['linkedProductAvailable'] ?? true, $value['linkedProductRestockDate'] ?? null, $today),
            ]);
        }

        return $choices;
    }

    /**
     * @param array<int, ?array{date: ?string}> $holdBacks Each product holding the choice back, or null if it is not held back
     * @return array{available: bool, date: ?string}
     */
    private function outcome(array $holdBacks): array
    {
        $holdBacks = \array_values(\array_filter($holdBacks));
        if ($holdBacks === []) return ['available' => true, 'date' => null];

        $dates = \array_column($holdBacks, 'date');
        $oneIsUndated = \in_array(null, $dates, true);

        return ['available' => false, 'date' => $oneIsUndated ? null : \max($dates)]; // Back only when the last product holding it back is
    }

    /** @return array{date: ?string}|null null when the product is not holding the choice back */
    private function heldBack(mixed $linkedProductId, mixed $available, ?string $restockDate, string $today): ?array
    {
        $noProductBehindIt = empty($linkedProductId);
        if ($noProductBehindIt) return null;

        if ($available) return null;

        $hasUsableDate = $restockDate !== null && \substr($restockDate, 0, 10) >= $today;

        return ['date' => $hasUsableDate ? \substr($restockDate, 0, 10) : null];
    }

    /** @param array<int, ?string> $dates */
    private function earliest(array $dates): ?string
    {
        $dated = \array_filter($dates, fn(?string $date): bool => $date !== null);

        return $dated === [] ? null : \min($dated);
    }

    /** @param array<int, ?string> $typeDates One per blocking type */
    private function kitRestockDate(array $typeDates): ?string
    {
        $nothingBlocking = $typeDates === [];
        $oneTypeUndated = \in_array(null, $typeDates, true);
        if ($nothingBlocking || $oneTypeUndated) return null;

        return \max($typeDates); // Every blocking type has to be back
    }
}
