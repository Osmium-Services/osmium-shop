<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Models;

class Product extends AdminModel
{
    protected ?string $sqlDir = __DIR__ . '/../admin/sql';

    /**
     * Live products that are out of stock, restocking, priced at zero or in no
     * category. Products only, never customer data.
     *
     * @return array<string, mixed>
     */
    public function getAttentionReport(int $limit): array
    {
        return self::shapeAttentionReport($this->fetchAll('product-attention.sql'), $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows live products from product-attention.sql
     * @return array<string, mixed>
     */
    public static function shapeAttentionReport(array $rows, int $limit): array
    {
        $lists = ['out_of_stock' => [], 'restocking' => [], 'zero_price' => [], 'no_category' => []];
        $available = 0;

        foreach ($rows as $row) {
            $product = ['id' => (int) $row['id'], 'reference' => $row['reference'], 'name' => \trim($row['brand_name'] . ' ' . $row['model_name'])];
            $isAvailable = (bool) $row['available'];
            $isRestocking = (bool) $row['restocking'];

            if ($isAvailable) {
                $available++;
            } elseif ($isRestocking) {
                $lists['restocking'][] = $product + ['restock_at' => $row['restock_at']];
            } else {
                $lists['out_of_stock'][] = $product;
            }
            if ((float) $row['price_exc_tax'] <= 0) {
                $lists['zero_price'][] = $product;
            }
            if (!(bool) $row['has_category']) {
                $lists['no_category'][] = $product;
            }
        }

        $report = ['live_products' => \count($rows), 'available' => $available];
        foreach ($lists as $name => $items) {
            $report[$name] = ['total' => \count($items), 'items' => \array_slice($items, 0, $limit)];
        }

        return $report;
    }

    /**
     * One product with its name, whether it is live and whether its status is a restocking one.
     *
     * @return array<string, mixed>|null
     */
    public function getForAssistant(int $id): ?array
    {
        return $this->fetchOne('product-get-for-assistant.sql', [':id' => $id]);
    }

    /**
     * Changes only the expected restock date (null clears it); the rest of the product is left alone, unlike the
     * admin form, which saves every field at once.
     */
    /** @return array<string, mixed>|null A live product as the shop.product.get hook shows it */
    public function getForLookup(int $id): ?array
    {
        return $this->fetchOne('product-get-for-lookup.sql', [':id' => $id]);
    }

    public function setRestockAt(int $id, ?string $date): void
    {
        $this->run('product-set-restock.sql', [':id' => $id, ':restock_at' => $date]);
    }

    /**
     * Live products matching a search pattern (already wrapped in % wildcards), at most $limit of them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForAssistant(string $pattern, int $limit): array
    {
        return $this->fetchAll('product-find-for-assistant.sql', [':pattern' => $pattern, ':limit' => $limit]);
    }

    /** Changes only the status; the restock date is dropped unless the new status is a restocking one. */
    public function setStatus(int $id, int $statusId, bool $restocking): void
    {
        $this->run('product-set-status.sql', [':id' => $id, ':status_id' => $statusId, ':restocking' => $restocking ? 1 : 0]);
    }

    /**
     * Live products that have techspec HTML to parse.
     *
     * @return array<int, array{id: int|string, techspec: string}>
     */
    public function listWithTechspec(): array
    {
        return $this->fetchAll('products-techspec-rebuild-list.sql');
    }

    /**
     * Live products (id, model_name, reference) for edit dropdowns, A to Z.
     *
     * @return array<int, array{id: int|string, model_name: string, reference: string}>
     */
    public function listForDropdown(): array
    {
        return $this->fetchAll('product-list-for-dropdown.sql');
    }

    /**
     * Live products (id and model name) for dropdowns and pickers, A to Z.
     *
     * @return array<int, array{id: int|string, model_name: string}>
     */
    public function listForPicker(): array
    {
        return $this->fetchAll('discount-product-picker-list.sql');
    }

    public function updateTechspecJson(int $id, ?string $json): void
    {
        $this->run('product-techspec-json-update.sql', [':id' => $id, ':techspec_json' => $json]);
    }

    /** @return array<string, mixed>|null the product a page belongs to, if any */
    public function findProductOfPage(int $pageId): ?array
    {
        return $this->fetchOne('page-check-product-linked.sql', [':page_id' => $pageId]);
    }

    /** @return array<string, mixed>|null the category a page is the page of, if any */
    public function findCategoryOfPage(int $pageId): ?array
    {
        return $this->fetchOne('page-check-category-linked.sql', [':page_id' => $pageId]);
    }

    /** @return array<int, array<string, mixed>> page_id of every product page, deleted products included */
    public function getPageLinks(): array
    {
        return $this->fetchAll('products-pages-list.sql');
    }

    /** @return array<int, array<string, mixed>> page_id, product_id and model_name of each live product's page */
    public function getPageOwners(): array
    {
        return $this->fetchAll('products-page-owners.sql');
    }

    /** @return array<string, mixed>|null */
    public function getBrandModel(int $id): ?array
    {
        return $this->fetchOne('product-get-brand-model.sql', [':id' => $id]);
    }

    public function revertPrice(int $id, mixed $price, mixed $rrp): void
    {
        $this->run('product-revert-price.sql', [':id' => $id, ':price' => $price, ':rrp' => $rrp]);
    }

    public function getName(int $id): string
    {
        $row = $this->fetchOne('product-get-name.sql', [':id' => $id]);
        return $row['model_name'] ?? "Product #{$id}";
    }

    /** @return array<string, mixed>|null */
    public function getPrices(int $id): ?array
    {
        return $this->fetchOne('product-get-prices.sql', [':id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function getTechspec(int $id): ?array
    {
        return $this->fetchOne('product-get-techspec.sql', [':id' => $id]);
    }

    public function insert(array $data): int
    {
        $sql = $this->loadSqlFile('product-insert.sql');
        $this->database->query($sql);
        $this->database->bind(param: ':brand_id', value: $data['brand_id']);
        $this->database->bind(param: ':model_name', value: $data['model_name']);
        $this->database->bind(param: ':reference', value: $data['reference']);
        $this->database->bind(param: ':barcode', value: $data['barcode']);
        $this->database->bind(param: ':status_id', value: $data['status_id']);
        $this->database->bind(param: ':price_exc_tax', value: $data['price_exc_tax']);
        $this->database->bind(param: ':rrp_exc_tax', value: $data['rrp_exc_tax']);
        $this->database->bind(param: ':descr', value: $data['descr']);
        $this->database->bind(param: ':descr2', value: $data['descr2']);
        $this->database->bind(param: ':descr3', value: $data['descr3']);
        $this->database->execute();
        return (int)$this->database->lastInsertId();
    }

    public function update(array $data): void
    {
        $this->run('product-update.sql', [
            ':id' => $data['id'],
            ':brand_id' => $data['brand_id'],
            ':model_name' => $data['model_name'],
            ':reference' => $data['reference'],
            ':barcode' => $data['barcode'],
            ':status_id' => $data['status_id'],
            ':restock_at' => $data['restock_at'],
            ':price_exc_tax' => $data['price_exc_tax'],
            ':rrp_exc_tax' => $data['rrp_exc_tax'],
            ':custom_offer' => $data['custom_offer'],
            ':descr' => $data['descr'],
            ':descr2' => $data['descr2'],
            ':descr3' => $data['descr3'],
            ':video_url' => $data['video_url'],
            ':techspec' => $data['techspec'],
            ':techspec_json' => $data['techspec_json'],
            ':faq' => $data['faq'],
            ':faq_list' => $data['faq_list'] ?? null,
            ':packed_weight' => $data['packed_weight'],
            ':option_label' => $data['option_label'],
            ':option_default' => $data['option_default'],
            ':kit_id' => $data['kit_id'],
        ]);
    }

    public function updatePrice(int $id, mixed $price, mixed $rrp, mixed $weight): void
    {
        $this->run('product-update-price.sql', [':id' => $id, ':price' => $price, ':rrp' => $rrp, ':weight' => $weight]);
    }

    public function linkToPage(int $productId, int $pageId): void
    {
        $this->run('products-pages-insert.sql', [':product_id' => $productId, ':page_id' => $pageId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getActive(): array
    {
        return $this->fetchAll('product-list-active.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getActiveByCategory(int $categoryId): array
    {
        return $this->fetchAll('product-list-active-by-category.sql', [':categoryId' => $categoryId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getRecent(): array
    {
        return $this->fetchAll('product-list-recent.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getDeleted(): array
    {
        return $this->fetchAll('product-list-deleted.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getForExport(): array
    {
        return $this->fetchAll('product-list-for-export.sql');
    }

    /**
     * Brand and model name of every other product, deleted ones included, since a restored product needs its images.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBrandModelExcept(int $id): array
    {
        return $this->fetchAll('product-list-brand-model-except.sql', [':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAllWithBrand(): array
    {
        return $this->fetchAll('product-list-all-with-brand.sql');
    }

    /** @return array<string, mixed>|null */
    public function getForImport(int $id): ?array
    {
        return $this->fetchOne('product-get-for-import.sql', [':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getPages(int $productId): array
    {
        return $this->fetchAll('product-pages-get.sql', [':product_id' => $productId]);
    }

    /** Returns page_id values for all non-QR product pages. */
    public function getProductPageIds(): array
    {
        return \array_column(array: $this->fetchAll('product-pages-list.sql'), column_key: 'page_id');
    }

    /** Returns page_id values for all QR product pages. */
    public function getQrPageIds(): array
    {
        return \array_column(array: $this->fetchAll('products-pages-qr-list.sql'), column_key: 'page_id');
    }

    /** @return array<int, array<string, mixed>> */
    public function getWithTechspec(): array
    {
        return $this->fetchAll('products-with-techspec.sql');
    }

    /** @return array<int, array<string, mixed>> */
    public function getWithFaq(): array
    {
        return $this->fetchAll('products-with-faq.sql');
    }

    public function countActive(): int
    {
        $row = $this->fetchOne('product-count-active.sql');
        return (int)($row['count'] ?? 0);
    }

    public function softDelete(int $id): void
    {
        $this->run('product-soft-delete.sql', [':id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->run('product-restore.sql', [':id' => $id]);
    }

    public function discontinue(int $id): void
    {
        $this->run('product-discontinue.sql', [':id' => $id]);
    }

    public function hardDelete(int $id): void
    {
        $this->run('product-hard-delete.sql', [':id' => $id]);
    }

    /** Full row for a single product (all columns). */
    public function getAll(int $id): ?array
    {
        return $this->fetchOne('product-get-all-by-id.sql', [':id' => $id]);
    }

    /** Product with its brand name. */
    public function getWithBrand(int $id): ?array
    {
        return $this->fetchOne('product-get-with-brand.sql', [':id' => $id]);
    }

    /** page_id of the first category linked to this product. */
    public function getCategoryPageId(int $productId): ?array
    {
        return $this->fetchOne('product-category-page-id.sql', [':product_id' => $productId]);
    }

    /** page_id record for this product's page link. */
    public function getPageId(int $id): ?array
    {
        return $this->fetchOne('product-get-page-id.sql', [':id' => $id]);
    }

    /** Max page_id in the pages table (below admin threshold). */
    public function getMaxPageId(): int
    {
        $row = $this->fetchOne('product-page-get-max-id.sql');
        return (int)($row['max_id'] ?? 0);
    }

    public function duplicateInsert(array $original, string $newModelName): int
    {
        $sql = $this->loadSqlFile('product-duplicate-insert.sql');
        $this->database->query($sql);
        $this->database->bind(':brand_id', $original['brand_id']);
        $this->database->bind(':model_name', $newModelName);
        $newRef = $original['reference'] ? $original['reference'] . '-COPY' : '';
        $this->database->bind(':reference', $newRef);
        $this->database->bind(':barcode', '');
        $this->database->bind(':status_id', $original['status_id']);
        $this->database->bind(':price_exc_tax', $original['price_exc_tax']);
        $this->database->bind(':rrp_exc_tax', $original['rrp_exc_tax']);
        $this->database->bind(':custom_offer', $original['custom_offer']);
        $this->database->bind(':descr', $original['descr']);
        $this->database->bind(':descr2', $original['descr2']);
        $this->database->bind(':descr3', $original['descr3']);
        $this->database->bind(':video_url', $original['video_url']);
        $this->database->bind(':techspec', $original['techspec']);
        $this->database->bind(':features', $original['features']);
        $this->database->bind(':accessories', $original['accessories']);
        $this->database->bind(':faq', $original['faq']);
        $this->database->bind(':faq_list', $original['faq_list'] ?? null);
        $this->database->bind(':packed_weight', $original['packed_weight']);
        $this->database->bind(':option_label', $original['option_label']);
        $this->database->bind(':option_default', $original['option_default']);
        $this->database->execute();
        return (int)$this->database->lastInsertId();
    }

    public function insertPageLink(int $productId, int $pageId): void
    {
        $this->run('product-page-link.sql', [':product_id' => $productId, ':page_id' => $pageId]);
    }

    public function copyCategories(int $newProductId, int $oldProductId): void
    {
        $this->run('product-copy-categories.sql', [':new_id' => $newProductId, ':old_id' => $oldProductId]);
    }

    public function deleteCategories(int $id): void
    {
        $this->run('product-delete-categories.sql', [':id' => $id]);
    }

    public function deletePages(int $id): void
    {
        $this->run('product-delete-pages.sql', [':id' => $id]);
    }

    public function getDescrImages(int $id): ?array
    {
        return $this->fetchOne('product-get-descr-images.sql', [':id' => $id]);
    }

    public function updateDescrImageField(int $id, string $field, mixed $value): void
    {
        $allowed = ['descr_image', 'descr2_image', 'descr3_image'];
        $validField = \in_array(needle: $field, haystack: $allowed, strict: true);
        if (!$validField) throw new \InvalidArgumentException("Invalid descr image field: {$field}");
        $table = $this->database->tablePrefix() . 'products';
        $sql = "UPDATE {$table} SET {$field} = :value, updated_at = NOW() WHERE id = :id";
        $this->database->query($sql);
        $this->database->bind(':value', $value);
        $this->database->bind(':id', $id);
        $this->database->execute();
    }
}
