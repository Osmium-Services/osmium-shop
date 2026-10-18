<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Price list CSV for the admin products screen: writes the export and applies
 * an uploaded import (price, RRP and weight for products and options).
 */
class ProductPriceCsv
{
    public function __construct(private OsmiumAdmin $admin)
    {
    }

    public function export($output): void
    {
        $products = $this->admin->model->product->getForExport();
        $optionsByProduct = $this->groupOptionsByProduct($this->admin->model->productOptionValue->getForExport());

        \fprintf($output, \chr(0xEF) . \chr(0xBB) . \chr(0xBF));
        \fputcsv(stream: $output, fields: ['Type', 'ID', 'Brand', 'Model', 'Reference', 'Option Type', 'Option Description', 'Price (ex VAT)', 'RRP (ex VAT)', 'Status', 'Weight (kg)'], escape: '\\');

        foreach ($products as $product) {
            $this->writeProductRow(output: $output, product: $product);
            foreach ($optionsByProduct[$product['id']] ?? [] as $option) {
                $this->writeOptionRow(output: $output, option: $option);
            }
        }
    }

    private function groupOptionsByProduct(array $allOptions): array
    {
        $optionsByProduct = [];
        foreach ($allOptions as $option) {
            $optionsByProduct[$option['product_id']][] = $option;
        }
        return $optionsByProduct;
    }

    private function writeProductRow($output, array $product): void
    {
        \fputcsv(stream: $output, fields: ['Product', $product['id'], $product['brand_name'], $product['model_name'], $product['reference'], '', '', $product['price_exc_tax'], $product['rrp_exc_tax'], $product['status_name'], $product['packed_weight']], escape: '\\');
    }

    private function writeOptionRow($output, array $option): void
    {
        \fputcsv(stream: $output, fields: ['Option', $option['option_id'], '', '', $option['option_reference'], $option['type_label'], $option['option_descr'], $option['option_premium'], $option['option_rrp'], '', $option['option_packed_weight']], escape: '\\');
    }


    public function import(array $file): array
    {
        $isCsvMime = $file['type'] === 'text/csv';
        $isCsvExt = \str_ends_with(haystack: $file['name'], needle: '.csv');

        return match (true) {
            $file['error'] !== UPLOAD_ERR_OK => ['error' => 'File upload failed'],
            !($isCsvMime || $isCsvExt) => ['error' => 'Please upload a CSV file'],
            default => $this->processImportFile(filePath: $file['tmp_name'], userId: $this->admin->auth->getCurrentUser()['id']),
        };
    }

    private function processImportFile(string $filePath, int $userId): array
    {
        $handle = \fopen(filename: $filePath, mode: 'r');
        $handleFailed = !$handle;
        if ($handleFailed) return ['error' => 'Could not open file'];

        $header = \fgetcsv(stream: $handle, escape: '\\');
        $headerEmpty = !$header;
        if ($headerEmpty) {
            \fclose($handle);
            return ['error' => 'Empty file'];
        }

        $header = $this->stripBom($header);
        $noIdColumn = !\in_array(needle: 'ID', haystack: $header, strict: true);
        if ($noIdColumn) {
            $header = \fgetcsv(stream: $handle, escape: '\\');
            $headerMissing = !$header;
            if ($headerMissing) {
                \fclose($handle);
                return ['error' => 'Could not find header row'];
            }
            $header = $this->stripBom($header);
        }

        $cols = $this->findColumnIndexes($header);
        $missingColumns = $cols['id'] === false || $cols['price'] === false;
        if ($missingColumns) {
            \fclose($handle);
            return ['error' => 'CSV must have ID and Price (ex VAT) columns'];
        }

        $stats = ['productsUpdated' => 0, 'optionsUpdated' => 0, 'skipped' => 0, 'errors' => []];
        $rowNum = 1;

        while (($row = \fgetcsv(stream: $handle, escape: '\\')) !== false) {
            $rowNum++;
            $result = $this->processImportRow(row: $row, cols: $cols, rowNum: $rowNum, userId: $userId);
            match ($result['result']) {
                'product' => $stats['productsUpdated']++,
                'option' => $stats['optionsUpdated']++,
                'skipped' => $stats['skipped']++,
                'error' => $stats['errors'][] = $result['message'],
            };
        }

        \fclose($handle);
        return ['success' => true] + $stats;
    }

    private function stripBom(array $header): array
    {
        $bom = "\xEF\xBB\xBF";
        $hasFirstElement = isset($header[0]);
        $hasBom = $hasFirstElement && \str_starts_with(haystack: $header[0], needle: $bom);
        if ($hasBom) {
            $header[0] = \substr(string: $header[0], offset: 3);
        }
        return $header;
    }

    private function findColumnIndexes(array $header): array
    {
        return [
            'type' => \array_search(needle: 'Type', haystack: $header),
            'id' => \array_search(needle: 'ID', haystack: $header),
            'price' => \array_search(needle: 'Price (ex VAT)', haystack: $header),
            'rrp' => \array_search(needle: 'RRP (ex VAT)', haystack: $header),
            'weight' => \array_search(needle: 'Weight (kg)', haystack: $header),
        ];
    }

    private function processImportRow(array $row, array $cols, int $rowNum, int $userId): array
    {
        $rowType = $cols['type'] !== false && isset($row[$cols['type']]) ? \trim($row[$cols['type']]) : 'Product';
        $id = (int)($row[$cols['id']] ?? 0);
        $newPrice = \trim($row[$cols['price']] ?? '');

        $missingId = !$id;
        if ($missingId) return ['result' => 'error', 'message' => "Row {$rowNum}: Invalid ID"];
        $priceBlank = $newPrice === '';
        if ($priceBlank) return ['result' => 'skipped'];
        $priceInvalid = !\is_numeric($newPrice);
        if ($priceInvalid) return ['result' => 'error', 'message' => "Row {$rowNum}: Invalid price '{$newPrice}'"];

        $isOptionRow = $rowType === 'Option';
        if ($isOptionRow) {
            return $this->processOptionImportRow(optionId: $id, row: $row, cols: $cols, rowNum: $rowNum);
        }

        $priceIsZero = (float)$newPrice == 0;
        if ($priceIsZero) return ['result' => 'skipped'];
        return $this->processProductImportRow(productId: $id, row: $row, cols: $cols, rowNum: $rowNum, userId: $userId);
    }

    private function processProductImportRow(int $productId, array $row, array $cols, int $rowNum, int $userId): array
    {
        $product = $this->admin->model->product->getForImport($productId);

        $productNotFound = !$product;
        if ($productNotFound) return ['result' => 'error', 'message' => "Row {$rowNum}: Product ID {$productId} not found"];

        $oldPrice = (float)$product['price_exc_tax'];
        $oldRrp = $product['rrp_exc_tax'] !== null ? (float)$product['rrp_exc_tax'] : null;
        $oldWeight = $product['packed_weight'] !== null ? (float)$product['packed_weight'] : null;
        $newPrice = (float)\trim($row[$cols['price']]);
        $newRrp = $cols['rrp'] !== false && \trim($row[$cols['rrp']] ?? '') !== '' && \is_numeric(\trim($row[$cols['rrp']])) ? (float)\trim($row[$cols['rrp']]) : $oldRrp;
        $newWeight = $cols['weight'] !== false && \trim($row[$cols['weight']] ?? '') !== '' && \is_numeric(\trim($row[$cols['weight']])) ? (float)\trim($row[$cols['weight']]) : $oldWeight;

        $priceChanged = \abs($oldPrice - $newPrice) > 0.001;
        $rrpChanged = $oldRrp !== $newRrp && $cols['rrp'] !== false && \trim($row[$cols['rrp']] ?? '') !== '';
        $weightChanged = $oldWeight !== $newWeight && $cols['weight'] !== false && \trim($row[$cols['weight']] ?? '') !== '';

        $nothingChanged = !$priceChanged && !$rrpChanged && !$weightChanged;
        if ($nothingChanged) return ['result' => 'skipped'];

        $this->admin->model->productPriceHistory->insertImport(
            productId: $productId,
            oldPrice: $oldPrice,
            newPrice: $newPrice,
            oldRrp: $oldRrp,
            newRrp: $newRrp,
            userId: $userId,
        );

        $this->admin->model->product->updatePrice($productId, $newPrice, $newRrp, $newWeight);

        return ['result' => 'product'];
    }

    private function processOptionImportRow(int $optionId, array $row, array $cols, int $rowNum): array
    {
        $option = $this->admin->model->productOptionValue->getForImport($optionId);

        $optionNotFound = !$option;
        if ($optionNotFound) return ['result' => 'error', 'message' => "Row {$rowNum}: Option ID {$optionId} not found"];

        $oldPremium = (float)$option['option_premium'];
        $oldRrp = $option['option_rrp'] !== null ? (float)$option['option_rrp'] : null;
        $oldWeight = $option['option_packed_weight'] !== null ? (float)$option['option_packed_weight'] : null;
        $newPremium = (float)\trim($row[$cols['price']]);
        $newRrp = $cols['rrp'] !== false && \trim($row[$cols['rrp']] ?? '') !== '' && \is_numeric(\trim($row[$cols['rrp']])) ? (float)\trim($row[$cols['rrp']]) : $oldRrp;
        $newWeight = $cols['weight'] !== false && \trim($row[$cols['weight']] ?? '') !== '' && \is_numeric(\trim($row[$cols['weight']])) ? (float)\trim($row[$cols['weight']]) : $oldWeight;

        $premiumChanged = \abs($oldPremium - $newPremium) > 0.001;
        $rrpChanged = $oldRrp !== $newRrp && $cols['rrp'] !== false && \trim($row[$cols['rrp']] ?? '') !== '';
        $weightChanged = $oldWeight !== $newWeight && $cols['weight'] !== false && \trim($row[$cols['weight']] ?? '') !== '';

        $nothingChanged = !$premiumChanged && !$rrpChanged && !$weightChanged;
        if ($nothingChanged) return ['result' => 'skipped'];

        $this->admin->model->productOptionValue->updatePrice($optionId, $newPremium, $newRrp, $newWeight);

        return ['result' => 'option'];
    }
}
