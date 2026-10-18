<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Services;

use Osmium\Core\Library\OsmiumPDO;

/**
 * ShopProductService
 *
 * Handles product data processing for the product detail page.
 * Processes options, pricing adjustments, and related product lookups.
 */
class ShopProductService
{
    public function __construct(
        private OsmiumPDO $dataSource,
        private float $taxRatePercent = 20.0,
    ) {}

    /**
     * Build structured option types from raw option data.
     *
     * Each option type contains values with price adjustments (premiums),
     * weights, and RRPs. Also generates CSV strings for JavaScript.
     *
     * @param array $rawOptions Raw options from ShopProductOption model
     * @param string $currencySign Currency symbol (e.g., '£')
     * @return array Structured option types with formatted values
     */
    public function buildOptionTypes(array $rawOptions, string $currencySign): array
    {
        $rawOptionsEmpty = empty($rawOptions);
        if ($rawOptionsEmpty) return [];

        $optionTypes = [];

        foreach ($rawOptions as $index => $optionType) {
            $optionTypes[] = $this->processOptionType(
                optionType: $optionType,
                index: $index,
                currencySign: $currencySign,
            );
        }

        return $optionTypes;
    }

    /**
     * Build JSON config for JavaScript price calculator.
     *
     * @param array $optionTypes Processed option types from buildOptionTypes()
     * @return string JSON-encoded config
     */
    public function buildOptionTypesJson(array $optionTypes): string
    {
        $config = [];

        foreach ($optionTypes as $optType) {
            $config[] = [
                'id' => $optType['id'],
                'slug' => $optType['slug'],
                'label' => $optType['label'],
                'defaultDescr' => $optType['defaultDescr'],
                'values' => $this->mapValuesForJson($optType['values']),
            ];
        }

        return \json_encode($config);
    }

    /**
     * Build legacy compatibility arrays for old template format.
     *
     * Maps new multi-level options to old single/sub option format.
     *
     * @param array $optionTypes Processed option types
     * @return array Legacy format arrays (options, suboptions)
     */
    public function buildLegacyOptions(array $optionTypes): array
    {
        $legacy = [
            'options' => null,
            'optionLabel' => null,
            'optionDefault' => null,
            'optionWeights' => null,
            'optionPremiums' => null,
            'optionRRPs' => null,
            'suboptions' => null,
            'suboptionLabel' => null,
            'suboptionWeights' => null,
            'suboptionPremiums' => null,
            'suboptionRRPs' => null,
        ];

        $hasFirstOption = !empty($optionTypes[0]);
        if ($hasFirstOption) {
            $legacy['options'] = $optionTypes[0]['values'];
            $legacy['optionLabel'] = $optionTypes[0]['label'];
            $legacy['optionDefault'] = $optionTypes[0]['defaultDescr'];
            $legacy['optionWeights'] = $optionTypes[0]['weightsCSV'];
            $legacy['optionPremiums'] = $optionTypes[0]['premiumsCSV'];
            $legacy['optionRRPs'] = $optionTypes[0]['rrpsCSV'];
        }

        $hasSecondOption = !empty($optionTypes[1]);
        if ($hasSecondOption) {
            $legacy['suboptions'] = $optionTypes[1]['values'];
            $legacy['suboptionLabel'] = $optionTypes[1]['label'];
            $legacy['suboptionWeights'] = $optionTypes[1]['weightsCSV'];
            $legacy['suboptionPremiums'] = $optionTypes[1]['premiumsCSV'];
            $legacy['suboptionRRPs'] = $optionTypes[1]['rrpsCSV'];
        }

        return $legacy;
    }

    /**
     * Load related kit product data.
     *
     * @param int|null $kitId Kit product ID (null if no kit)
     * @param callable $loadProductFn Function to load product by ID
     * @return array|null Kit product data or null
     */
    public function loadKitProduct(?int $kitId, callable $loadProductFn): ?array
    {
        if (!$kitId) return null;

        $products = $loadProductFn([$kitId]);

        $kitProductMissing = empty($products[$kitId]);
        if ($kitProductMissing) return null;

        $kp = $products[$kitId];

        return [
            'name' => $kp['title'] ?? ($kp['brand_name'] . ' ' . $kp['model_name']),
            'url' => $kp['url'] ?? '',
        ];
    }

    /**
     * Format price with tax for display.
     *
     * @param float $priceExcTax Price excluding tax
     * @return string Formatted price (e.g., "99.99")
     */
    public function formatPriceIncTax(float $priceExcTax): string
    {
        $priceIncTax = $this->addSalesTax($priceExcTax);

        return \number_format(num: $priceIncTax, decimals: 2);
    }

    /**
     * Format price with tax for schema.org structured data.
     *
     * Returns plain float without thousands separator for JSON-LD compliance.
     *
     * @param float $priceExcTax Price excluding tax
     * @return string Formatted price (e.g., "1440.00")
     */
    public function schemaPriceIncTax(float $priceExcTax): string
    {
        $priceIncTax = $this->addSalesTax($priceExcTax);

        return \number_format(num: $priceIncTax, decimals: 2, thousands_separator: '');
    }

    /**
     * Format price excluding tax for display.
     *
     * @param float $priceExcTax Price excluding tax
     * @return string Formatted price (e.g., "99.99")
     */
    public function formatPriceExcTax(float $priceExcTax): string
    {
        return \number_format(num: $priceExcTax, decimals: 2);
    }

    /**
     * Calculate savings compared to RRP.
     *
     * @param float $priceExcTax Selling price excluding tax
     * @param float $rrpExcTax RRP excluding tax
     * @return array Savings amount and percentage
     */
    public function calculateSavings(float $priceExcTax, float $rrpExcTax): array
    {
        $rrpIncTax = $this->addSalesTax($rrpExcTax);
        $priceIncTax = $this->addSalesTax($priceExcTax);
        $savingsAmount = $rrpIncTax - $priceIncTax;

        $savingsPercent = 0;
        $hasBothPrices = $rrpIncTax > 0 && $priceIncTax > 0;
        if ($hasBothPrices) {
            $savingsPercent = (int)\round(($savingsAmount / $rrpIncTax) * 100);
        }

        return [
            'savings' => \number_format(num: $savingsAmount, decimals: 2),
            'savingsPercent' => $savingsPercent,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function addSalesTax(float $priceExcTax): float
    {
        return $priceExcTax * (1 + ($this->taxRatePercent / 100));
    }

    private function processOptionType(array $optionType, int $index, string $currencySign): array
    {
        $typeData = [
            'id' => $optionType['id'],
            'label' => $optionType['type_label'],
            'slug' => $optionType['type_slug'],
            'defaultDescr' => $optionType['default_option_descr'],
            'index' => $index,
            'linkedProductId' => $optionType['linked_product_id'],
            'linkedProductDescr' => $optionType['linked_product_descr'],
            'linkedImageSlot' => $optionType['linked_image_slot'],
            'linkedProductTitle' => $optionType['linked_product_title'],
            'linkedProductDescription' => $optionType['linked_product_description'],
            'linkedProductBrand' => $optionType['linked_product_brand'],
            'linkedProductModel' => $optionType['linked_product_model'],
            'linkedProductUrl' => $optionType['linked_product_url'],
            'linkedProductAvailable' => (bool) ($optionType['linked_product_available'] ?? true),
            'linkedProductRestocking' => (bool) ($optionType['linked_product_restocking'] ?? false),
            'linkedProductRestockDate' => $optionType['linked_product_restock_at'],
            'defaultLinkedProductId' => $optionType['default_linked_product_id'] ?? null,
            'defaultAvailable' => (bool) ($optionType['default_linked_product_available'] ?? true),
            'defaultRestocking' => (bool) ($optionType['default_linked_product_restocking'] ?? false),
            'defaultRestockDate' => $optionType['default_linked_product_restock_at'] ?? null,
            'dependsOnValueIds' => $optionType['depends_on_value_ids'] ?? [],
            'values' => [],
        ];

        $weights = [];
        $premiums = [];
        $rrps = [];

        foreach ($optionType['values'] as $value) {
            $processedValue = $this->processOptionValue(
                value: $value,
                currencySign: $currencySign,
            );

            $weights[] = $value['option_packed_weight'];
            $premiums[] = $value['option_premium'];
            $rrps[] = $value['option_rrp'];

            $typeData['values'][] = $processedValue;
        }

        $typeData['weightsCSV'] = \implode(separator: ',', array: $weights);
        $typeData['premiumsCSV'] = \implode(separator: ',', array: $premiums);
        $typeData['rrpsCSV'] = \implode(separator: ',', array: $rrps); // Comma-delimited strings for JavaScript

        return $typeData;
    }

    private function processOptionValue(array $value, string $currencySign): array
    {
        $priceIncTax = $this->addSalesTax((float)$value['option_premium']);
        $sign = $priceIncTax >= 0 ? '+' : '-';
        $absPrice = \number_format(num: \abs($priceIncTax), decimals: 2);

        $value['priceIncTax'] = $sign . $currencySign . $absPrice;
        $value['linkedProductId'] = $value['linked_product_id'];
        $value['linkedProductAvailable'] = (bool) ($value['linked_product_available'] ?? true);
        $value['linkedProductRestocking'] = (bool) ($value['linked_product_restocking'] ?? false);
        $value['linkedProductRestockDate'] = $value['linked_product_restock_at'];

        return $value;
    }

    private function mapValuesForJson(array $values): array
    {
        return \array_map(
            callback: fn($v) => [
                'rrp' => (float)$v['option_rrp'],
                'premium' => (float)$v['option_premium'],
                'weight' => (float)$v['option_packed_weight'],
                'descr' => $v['option_descr'],
                'ref' => $v['option_reference'],
            ],
            array: $values,
        );
    }
}
