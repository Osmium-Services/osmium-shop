<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProductOption extends ShopModel
{
    /**
     * Get all option types and their values for a product
     * Returns nested structure: optionTypes[] -> values[]
     * Supports up to 5 option types per product
     *
     * @param int $productID
     * @return array
     */
    public function getAllOptionsForProduct(int $productID): array
    {
        $query = $this->loadSqlFile('option-get-all-for-product.sql');
        $query = \str_replace(':productID', (string)$productID, $query);
        $this->database->query($query);
        $rows = $this->database->resultset();

        foreach ($rows as &$row) { // Resolve linked product URLs from PAGES constant
            if ($row['linked_product_page_id']) {
                $page = $this->pagesById()[$row['linked_product_page_id']] ?? null;
                $row['linked_product_url'] = ($page && $page['deleted_at'] === null) ? $page['url'] : null;
                continue;
            }

            $row['linked_product_url'] = null;
        }
        unset($row);

        $dependenciesByType = $this->getDependenciesGroupedByType($productID);

        return $this->nestOptionResults($rows, $dependenciesByType);
    }

    /**
     * Fetch trigger-value dependencies for the product's option types, grouped by type id
     *
     * @param int $productID
     * @return array<int, int[]>
     */
    private function getDependenciesGroupedByType(int $productID): array
    {
        $query = $this->loadSqlFile('option-dependency-list-by-product.sql');
        $query = \str_replace(':productID', (string)$productID, $query);
        $this->database->query($query);
        $rows = $this->database->resultset();

        $grouped = [];
        foreach ($rows as $row) {
            $typeId = $row['option_type_id'] ?? null;
            $valueId = $row['depends_on_value_id'] ?? null;
            $rowIncomplete = $typeId === null || $valueId === null;
            if ($rowIncomplete) continue;

            $grouped[$typeId][] = (int)$valueId;
        }

        return $grouped;
    }

    /**
     * Transform flat query results into nested option types -> values structure
     *
     * @param array $rows
     * @return array
     */
    private function nestOptionResults(array $rows, array $dependenciesByType = []): array
    {
        $optionTypes = [];

        foreach ($rows as $row) {
            $typeId = $row['type_id'];

            if (!isset($optionTypes[$typeId])) {
                $optionTypes[$typeId] = [
                    'id' => $typeId,
                    'type_label' => $row['type_label'],
                    'type_slug' => $row['type_slug'],
                    'default_option_descr' => $row['default_option_descr'],
                    'linked_product_id' => $row['linked_product_id'],
                    'linked_product_descr' => $row['linked_product_descr'],
                    'linked_image_slot' => $row['type_linked_image_slot'],
                    'linked_product_title' => $row['linked_product_title'],
                    'linked_product_description' => $row['linked_product_description'],
                    'linked_product_brand' => $row['linked_product_brand'],
                    'linked_product_model' => $row['linked_product_model'],
                    'linked_product_url' => $row['linked_product_url'],
                    'linked_product_available' => $row['linked_product_available'],
                    'linked_product_restocking' => $row['linked_product_restocking'],
                    'linked_product_restock_at' => $row['linked_product_restock_at'],
                    'default_linked_product_id' => $row['default_linked_product_id'],
                    'default_linked_product_available' => $row['default_linked_product_available'],
                    'default_linked_product_restocking' => $row['default_linked_product_restocking'],
                    'default_linked_product_restock_at' => $row['default_linked_product_restock_at'],
                    'sort' => $row['type_sort'],
                    'depends_on_value_ids' => $dependenciesByType[$typeId] ?? [],
                    'values' => []
                ];
            }

            if ($row['value_id'] !== null) {
                $optionTypes[$typeId]['values'][] = [
                    'id' => $row['value_id'],
                    'option_rrp' => $row['option_rrp'],
                    'option_premium' => $row['option_premium'],
                    'option_descr' => $row['option_descr'],
                    'option_packed_weight' => $row['option_packed_weight'],
                    'option_reference' => $row['option_reference'],
                    'linked_product_id' => $row['value_linked_product_id'],
                    'linked_product_available' => $row['linked_value_product_available'],
                    'linked_product_restocking' => $row['linked_value_product_restocking'],
                    'linked_product_restock_at' => $row['linked_value_product_restock_at'],
                    'linked_image_slot' => $row['value_linked_image_slot'],
                    'sort' => $row['value_sort']
                ]; // Add value if it exists (LEFT JOIN may return null values)
            }
        }

        $optionResults = \array_values($optionTypes); // Re-index array to be sequential (0, 1, 2...);

        return $optionResults;
    }

    // ============================================
    // LEGACY METHODS - For backward compatibility
    // ============================================

    /**
     * @deprecated Use getAllOptionsForProduct() instead
     */
    public function getOptionsByProductID(int $productID)
    {
        $options = $this->getAllOptionsForProduct($productID);
        return !empty($options[0]['values']) ? $options[0]['values'] : [];
    }

    /**
     * @deprecated Use getAllOptionsForProduct() instead
     */
    public function getSubOptionsByProductID(int $productID)
    {
        $options = $this->getAllOptionsForProduct($productID);
        return !empty($options[1]['values']) ? $options[1]['values'] : [];
    }
}
