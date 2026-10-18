<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;

require_once 'app/core/admin/models/AdminModel.php';

/**
 * AdminShopOptions - Product option types and values management
 */
class AdminShopOptions
{
    /** Slots an option can swap the main image to: the ones the product image tab offers. */
    private const LINKED_IMAGE_SLOTS = ['main', 'extra1', 'extra2', 'extra3', 'extra4', 'extra5', 'warranty'];

    public function __construct(
        private ProductOptionType $optionType,
        private ProductOptionValue $optionValue,
    ) {}

    /**
     * Get option types and values for a product
     */
    public function getProductOptions(int $productId): array
    {
        if (!$productId) throw new \Exception('Product ID is required');

        $types = $this->optionType->listByProduct($productId);
        $valuesByType = $this->fetchAllValuesGroupedByType($productId);
        $dependenciesByType = $this->fetchDependenciesGroupedByType($productId);

        return $this->assembleTypesWithValues($types, $valuesByType, $dependenciesByType);
    }

    private function fetchAllValuesGroupedByType(int $productId): array
    {
        $allValues = $this->optionValue->listByProduct($productId);

        $grouped = [];
        foreach ($allValues as $value) {
            $typeId = $value['option_type_id'];
            unset($value['option_type_id']);
            $grouped[$typeId][] = $value;
        }

        return $grouped;
    }

    private function fetchDependenciesGroupedByType(int $productId): array
    {
        $rows = $this->optionType->listDependenciesByProduct($productId);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['option_type_id']][] = (int)$row['depends_on_value_id'];
        }

        return $grouped;
    }

    private function assembleTypesWithValues(array $types, array $valuesByType, array $dependenciesByType = []): array
    {
        $result = [];
        foreach ($types as $type) {
            $type['values'] = $valuesByType[$type['id']] ?? [];
            $type['depends_on_value_ids'] = $dependenciesByType[$type['id']] ?? [];
            $result[] = $type;
        }

        return $result;
    }

    /**
     * Add a new option type
     * @return array ['id' => int, 'type_slug' => string]
     */
    public function addOptionType(int $productId, array $input): array
    {
        if (!$productId) throw new \Exception('Product ID is required');

        $typeLabel = \trim($input['type_label'] ?? '');
        $typeLabelEmpty = empty($typeLabel);
        if ($typeLabelEmpty) throw new \Exception('Type label is required');

        $slugified = \preg_replace(
            pattern: '/[^a-zA-Z0-9]+/',
            replacement: '-',
            subject: $typeLabel,
        );
        $typeSlug = \strtolower($slugified);
        $typeSlug = \trim(string: $typeSlug, characters: '-');

        $nextSort = $this->optionType->getNextSort($productId);

        $linkedProductId = !empty($input['linked_product_id'])
            ? (int)$input['linked_product_id']
            : null;
        $defaultLinkedProductId = !empty($input['default_linked_product_id'])
            ? (int)$input['default_linked_product_id']
            : null;
        $linkedProductDescr = \trim($input['linked_product_descr'] ?? '');
        $defaultDescr = \trim($input['default_option_descr'] ?? '');
        $linkedImageSlot = $this->cleanImageSlot(\trim($input['linked_image_slot'] ?? '') ?: null);

        $newTypeId = $this->optionType->insert([
            'product_id' => $productId,
            'type_label' => $typeLabel,
            'type_slug' => $typeSlug,
            'default_descr' => $defaultDescr,
            'linked_id' => $linkedProductId,
            'default_linked_id' => $defaultLinkedProductId,
            'linked_descr' => $linkedProductDescr ?: null,
            'linked_image_slot' => $linkedImageSlot,
            'sort' => $nextSort,
        ]);

        $hasDependencies = !empty($input['depends_on_value_ids']);
        if ($hasDependencies) {
            $this->setOptionTypeDependencies(typeId: $newTypeId, valueIds: $input['depends_on_value_ids']);
        }

        return [
            'id' => $newTypeId,
            'type_slug' => $typeSlug,
        ];
    }

    /**
     * Update an option type
     */
    public function updateOptionType(int $typeId, array $input): void
    {
        if (!$typeId) throw new \Exception('Type ID is required');

        $updates = [];
        $bindings = [':id' => $typeId];

        $hasTypeLabel = isset($input['type_label']);
        if ($hasTypeLabel) {
            $label = \trim($input['type_label']);
            $updates[] = 'type_label = :type_label';
            $bindings[':type_label'] = $label;

            $slugified = \preg_replace(
                pattern: '/[^a-zA-Z0-9]+/',
                replacement: '-',
                subject: $label,
            );
            $typeSlug = \strtolower($slugified);
            $typeSlug = \trim(string: $typeSlug, characters: '-');
            $updates[] = 'type_slug = :type_slug';
            $bindings[':type_slug'] = $typeSlug;
        }

        $fieldDefs = [
            'default_option_descr' => [
                'col' => 'default_option_descr',
                'bind' => ':default_descr',
                'type' => 'string',
            ],
            'sort' => ['col' => 'sort', 'bind' => ':sort', 'type' => 'int'],
        ];

        foreach ($fieldDefs as $inputKey => $def) {
            $fieldNotSet = !isset($input[$inputKey]);
            if ($fieldNotSet) continue;

            $value = match ($def['type']) {
                'string' => \trim($input[$inputKey]),
                'int' => (int)$input[$inputKey],
            };

            $updates[] = "{$def['col']} = {$def['bind']}";
            $bindings[$def['bind']] = $value;
        }

        $nullableDefs = [
            'linked_product_id' => [
                'col' => 'linked_product_id',
                'bind' => ':linked_id',
                'type' => 'int',
            ],
            'default_linked_product_id' => [
                'col' => 'default_linked_product_id',
                'bind' => ':default_linked_id',
                'type' => 'int',
            ],
            'linked_product_descr' => [
                'col' => 'linked_product_descr',
                'bind' => ':linked_descr',
                'type' => 'string',
            ],
            'linked_image_slot' => [
                'col' => 'linked_image_slot',
                'bind' => ':linked_image_slot',
                'type' => 'string',
                'allowed' => self::LINKED_IMAGE_SLOTS,
            ],
        ];

        foreach ($nullableDefs as $inputKey => $def) {
            $this->processNullableField($inputKey, $def, $input, $updates, $bindings);
        }

        $hasDependencies = \array_key_exists(key: 'depends_on_value_ids', array: $input);
        if ($hasDependencies) {
            $this->setOptionTypeDependencies(typeId: $typeId, valueIds: $input['depends_on_value_ids'] ?? []);
        }

        $noUpdates = empty($updates);
        if ($noUpdates && !$hasDependencies) throw new \Exception('No updates provided');
        if ($noUpdates) return;

        $this->optionType->update($typeId, $updates, $bindings);
    }

    /**
     * Replace an option type's trigger-value dependencies wholesale
     */
    private function setOptionTypeDependencies(int $typeId, array $valueIds): void
    {
        $this->optionType->deleteDependenciesByType($typeId);

        foreach ($valueIds as $valueId) {
            $this->optionType->insertDependency($typeId, (int)$valueId);
        }
    }

    /**
     * Soft delete an option type and its values
     */
    public function deleteOptionType(int $typeId): void
    {
        if (!$typeId) throw new \Exception('Type ID is required');

        $this->optionType->softDelete($typeId);
        $this->optionValue->softDeleteByType($typeId);
        $this->optionType->deleteDependenciesByType($typeId);
        $this->optionType->deleteDependenciesByTypeValues($typeId);
    }

    /**
     * Add a new option value
     * @return int New value ID
     */
    public function addOptionValue(int $typeId, array $input): int
    {
        if (!$typeId) throw new \Exception('Type ID is required');

        $optionDescr = \trim($input['option_descr'] ?? '');
        $descrEmpty = empty($optionDescr);
        if ($descrEmpty) throw new \Exception('Option description is required');

        $nextSort = $this->optionValue->getNextSort($typeId);

        $premium = (float)($input['option_premium'] ?? 0);
        $rrp = (float)($input['option_rrp'] ?? 0);
        $weight = (float)($input['option_packed_weight'] ?? 0);
        $reference = \trim($input['option_reference'] ?? '');
        $this->assertPlainText($reference, 'option_reference');
        $linkedProductId = !empty($input['linked_product_id'])
            ? (int)$input['linked_product_id']
            : null;
        $linkedImageSlot = $this->cleanImageSlot(\trim($input['linked_image_slot'] ?? '') ?: null);

        return $this->optionValue->insert([
            'type_id' => $typeId,
            'descr' => $optionDescr,
            'premium' => $premium,
            'rrp' => $rrp,
            'weight' => $weight,
            'reference' => $reference,
            'linked_id' => $linkedProductId,
            'linked_image_slot' => $linkedImageSlot,
            'sort' => $nextSort,
        ]);
    }

    /**
     * Update an option value
     */
    public function updateOptionValue(int $valueId, array $input): void
    {
        if (!$valueId) throw new \Exception('Value ID is required');

        $updates = [];
        $bindings = [':id' => $valueId];

        $fieldDefs = [
            'option_descr' => ['col' => 'option_descr', 'bind' => ':descr', 'type' => 'string'],
            'option_premium' => ['col' => 'option_premium', 'bind' => ':premium', 'type' => 'float'],
            'option_rrp' => ['col' => 'option_rrp', 'bind' => ':rrp', 'type' => 'float'],
            'option_packed_weight' => [
                'col' => 'option_packed_weight',
                'bind' => ':weight',
                'type' => 'float',
            ],
            'option_reference' => [
                'col' => 'option_reference',
                'bind' => ':reference',
                'type' => 'string',
                'plain' => true,
            ],
            'sort' => ['col' => 'sort', 'bind' => ':sort', 'type' => 'int'],
        ];

        foreach ($fieldDefs as $inputKey => $def) {
            $fieldNotSet = !isset($input[$inputKey]);
            if ($fieldNotSet) continue;

            $value = match ($def['type']) {
                'string' => \trim($input[$inputKey]),
                'float' => (float)$input[$inputKey],
                'int' => (int)$input[$inputKey],
            };

            if (!empty($def['plain'])) $this->assertPlainText($value, $inputKey);

            $updates[] = "{$def['col']} = {$def['bind']}";
            $bindings[$def['bind']] = $value;
        }

        $nullableDefs = [
            'linked_product_id' => [
                'col' => 'linked_product_id',
                'bind' => ':linked_id',
                'type' => 'int',
            ],
            'linked_image_slot' => [
                'col' => 'linked_image_slot',
                'bind' => ':linked_image_slot',
                'type' => 'string',
                'allowed' => self::LINKED_IMAGE_SLOTS,
            ],
        ];

        foreach ($nullableDefs as $inputKey => $def) {
            $this->processNullableField($inputKey, $def, $input, $updates, $bindings);
        }

        $noUpdates = empty($updates);
        if ($noUpdates) throw new \Exception('No updates provided');

        $this->optionValue->update($valueId, $updates, $bindings);
    }

    /**
     * Soft delete an option value
     */
    public function deleteOptionValue(int $valueId): void
    {
        if (!$valueId) throw new \Exception('Value ID is required');

        $this->optionValue->softDelete($valueId);
        $this->optionType->deleteDependenciesByValue($valueId);
    }

    /**
     * Reorder option types
     */
    public function reorderOptionTypes(array $items): void
    {
        $itemsEmpty = empty($items);
        if ($itemsEmpty) throw new \Exception('No items provided');

        foreach ($items as $item) {
            $this->optionType->reorder((int)$item['id'], (int)$item['sort']);
        }
    }

    /**
     * Reorder option values
     */
    public function reorderOptionValues(array $items): void
    {
        $itemsEmpty = empty($items);
        if ($itemsEmpty) throw new \Exception('No items provided');

        foreach ($items as $item) {
            $this->optionValue->reorder((int)$item['id'], (int)$item['sort']);
        }
    }

    private function cleanImageSlot(?string $slot): ?string
    {
        $invalid = $slot !== null && !\in_array($slot, self::LINKED_IMAGE_SLOTS, true);
        if ($invalid) throw new \Exception('Invalid linked image slot');

        return $slot;
    }

    /**
     * Codes and references are typed as plain text. Refusing markup characters keeps them harmless wherever
     * they are later printed.
     */
    private function assertPlainText(string $value, string $field): void
    {
        $hasMarkup = \preg_match('/[<>"\'&`]/', $value) === 1;
        if ($hasMarkup) throw new \Exception("{$field} cannot contain < > \" ' & or `");
    }

    private function processNullableField(
        string $inputKey,
        array $def,
        array $input,
        array &$updates,
        array &$bindings
    ): void {
        $fieldMissing = !\array_key_exists(key: $inputKey, array: $input);
        if ($fieldMissing) return;

        $rawValue = $input[$inputKey];
        $isNull = match ($def['type']) {
            'int' => $rawValue === null || $rawValue === '',
            'string' => \trim($rawValue ?? '') === '',
            default => throw new \InvalidArgumentException("Unsupported nullable field type: {$def['type']}"),
        };

        if ($isNull) {
            $updates[] = "{$def['col']} = NULL";
            return;
        }

        $value = match ($def['type']) {
            'int' => (int)$rawValue,
            'string' => \trim($rawValue),
        };

        $notInList = isset($def['allowed']) && !\in_array($value, $def['allowed'], true);
        if ($notInList) throw new \Exception("Invalid value for {$inputKey}");

        if (!empty($def['plain'])) $this->assertPlainText($value, $inputKey);
        $updates[] = "{$def['col']} = {$def['bind']}";
        $bindings[$def['bind']] = $value;
    }

    /**
     * Get all products that have at least one option type (for copy dropdown)
     */
    public function getProductsWithOptions(): array
    {
        return $this->optionType->productsWithOptions();
    }

    /**
     * Copy all options from source product to target (replaces existing)
     */
    public function copyOptionsFromProduct(int $sourceProductId, int $targetProductId): array
    {
        if (!$sourceProductId) throw new \Exception('Source product ID required');
        if (!$targetProductId) throw new \Exception('Target product ID required');
        $sameProduct = $sourceProductId === $targetProductId;
        if ($sameProduct) throw new \Exception('Cannot copy to same product');

        $this->deleteOptionsByProduct($targetProductId);

        $sourceOptions = $this->getProductOptions($sourceProductId);
        $noSourceOptions = empty($sourceOptions);
        if ($noSourceOptions) return ['types_copied' => 0, 'values_copied' => 0];

        $typesCopied = 0;
        $valuesCopied = 0;
        $newTypeIdBySourceTypeId = [];
        $newValueIdBySourceValueId = [];

        foreach ($sourceOptions as $type) {
            $newTypeResult = $this->addOptionType(productId: $targetProductId, input: [
                'type_label' => $type['type_label'],
                'default_option_descr' => $type['default_option_descr'] ?? '',
                'linked_product_id' => $type['linked_product_id'],
                'default_linked_product_id' => $type['default_linked_product_id'] ?? null,
                'linked_product_descr' => $type['linked_product_descr'] ?? '',
                'linked_image_slot' => $type['linked_image_slot'] ?? null,
            ]);
            $newTypeIdBySourceTypeId[$type['id']] = $newTypeResult['id'];
            $typesCopied++;

            foreach ($type['values'] as $value) {
                $newValueId = $this->addOptionValue(typeId: $newTypeResult['id'], input: [
                    'option_descr' => $value['option_descr'],
                    'option_premium' => $value['option_premium'] ?? 0,
                    'option_rrp' => $value['option_rrp'],
                    'option_packed_weight' => $value['option_packed_weight'],
                    'option_reference' => $value['option_reference'] ?? '',
                    'linked_product_id' => $value['linked_product_id'] ?? null,
                    'linked_image_slot' => $value['linked_image_slot'] ?? null,
                ]);
                $newValueIdBySourceValueId[$value['id']] = $newValueId;
                $valuesCopied++;
            }
        }

        // Second pass: dependencies can point at values from any type on the product,
        // so this needs the full old->new value id map built above before translating them.
        foreach ($sourceOptions as $type) {
            $sourceDependencies = $type['depends_on_value_ids'] ?? [];
            if (empty($sourceDependencies)) continue;

            $mappedValueIds = \array_map(
                callback: fn(int $sourceValueId) => $newValueIdBySourceValueId[$sourceValueId] ?? null,
                array: $sourceDependencies,
            );
            $mappedValueIds = \array_filter($mappedValueIds);

            $mappedValueIdsEmpty = empty($mappedValueIds);
            if ($mappedValueIdsEmpty) continue;

            $this->setOptionTypeDependencies(
                typeId: $newTypeIdBySourceTypeId[$type['id']],
                valueIds: $mappedValueIds,
            );
        }

        return ['types_copied' => $typesCopied, 'values_copied' => $valuesCopied];
    }

    private function deleteOptionsByProduct(int $productId): void
    {
        $this->optionType->deleteDependenciesByProduct($productId);
        $this->optionValue->hardDeleteByProduct($productId);
        $this->optionType->hardDeleteByProduct($productId);
    }
}
