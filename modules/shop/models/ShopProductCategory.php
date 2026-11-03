<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProductCategory extends ShopModel
{
    public private(set) int $id = 0;
    public private(set) int $parentID = 0;
    public private(set) string $label = '';
    public private(set) string $title = '';
    public private(set) string $subtitle = '';
    public private(set) int $sort = 0;
    public private(set) string $description = '';
    public private(set) string $socialImage = '';

    public function loadByID(?int $categoryID = null)
    {
        $invalidId = $categoryID < 1;
        if ($invalidId) return false;

        $query = $this->loadSqlFile('category-get-by-id.sql');
        $this->database->query($query);
        $this->database->bind(':categoryID', $categoryID);
        $data = $this->database->single();

        $noData = !\is_array($data);
        if ($noData) return false;

        $this->id = $categoryID;
        $this->parentID = $data['parent_id'] ?? 0;
        $this->label = $data['label'] ?? '';
        $this->title = $data['title'] ?? '';
        $this->subtitle = $data['subtitle'] ?? '';
        $this->sort = $data['sort'] ?? 0;
        $this->description = $data['description'] ?? '';
        $this->socialImage = $data['social_image'] ?? '';

        return true;
    }

    public function subcategories(): array
    {
        $query = $this->loadSqlFile('category-subcategories.sql');
        $this->database->query($query);
        $this->database->bind(':thisID', $this->id);
        $results = $this->database->resultset();

        foreach ($results as &$row) { // Get URLs from PAGES constant
            $page = $this->pagesById()[$row['page_id']] ?? null;
            $row['link'] = ($page && $page['deleted_at'] === null) ? $page['url'] : null;
        }
        unset($row);

        return $results;
    }

    /**
     * Top-level categories with their page URL, for the shop landing page and the home page cards. A category
     * with no image of its own gets `fallback_image`: the path of its first orderable product's image
     * ('' when it has neither), to be resolved with imagePath().
     */
    public function topLevel(): array
    {
        $query = $this->loadSqlFile('category-top-level.sql');
        $this->database->query($query);
        $results = $this->database->resultset();

        $firstProducts = $this->firstProductByCategory();

        foreach ($results as &$row) { // Get URLs from PAGES constant
            $page = $this->pagesById()[$row['page_id']] ?? null;
            $row['link'] = ($page && $page['deleted_at'] === null) ? $page['url'] : null;

            $fallback = $firstProducts[(int)$row['id']] ?? null;
            $needsFallback = empty($row['image']) && $fallback !== null;
            $row['fallback_image'] = $needsFallback
                ? ShopProduct::imagePathFor(ShopProduct::sanitizePath($fallback['brand_name']), ShopProduct::sanitizePath($fallback['model_name']))
                : '';
        }
        unset($row);

        return $results;
    }

    /**
     * First orderable product in each category, keyed by category id.
     *
     * @return array<int, array{brand_name: string, model_name: string}>
     */
    private function firstProductByCategory(): array
    {
        $query = $this->loadSqlFile('category-first-product.sql');
        $this->database->query($query);
        $rows = $this->database->resultset();

        $firstByCategory = [];
        foreach ($rows as $row) {
            $categoryId = (int)$row['category_id'];

            $alreadySeen = isset($firstByCategory[$categoryId]);
            if ($alreadySeen) continue; // Ordered newest first, so the first row wins

            $firstByCategory[$categoryId] = [
                'brand_name' => (string)$row['brand_name'],
                'model_name' => (string)$row['model_name'],
            ];
        }

        return $firstByCategory;
    }

    /**
     * Direct subcategories of a category id, whether or not they have products yet. Unlike subcategories()
     * it does not need this instance loaded with loadByID(), so the tree walk can call it recursively.
     */
    public function subcategoriesOf(int $categoryID): array
    {
        $query = $this->loadSqlFile('category-subcategories-all.sql');
        $this->database->query($query);
        $this->database->bind(':thisID', $categoryID);
        $results = $this->database->resultset();

        foreach ($results as &$row) { // Get URLs from PAGES constant
            $page = $this->pagesById()[$row['page_id']] ?? null;
            $row['link'] = ($page && $page['deleted_at'] === null) ? $page['url'] : null;
        }
        unset($row);

        return $results;
    }

    /**
     * The full category tree, top level down, for a sidebar menu: the same tree on every page. Pass the current
     * page's category id to flag its node 'active'. Each node: title, link ('/path/' or ''), active, hasProducts, children.
     */
    public function tree(?int $activeCategoryID = null): array
    {
        return $this->treeFrom($this->topLevel(), $activeCategoryID);
    }

    private function treeFrom(array $categories, ?int $activeCategoryID): array
    {
        return \array_map(
            callback: function (array $category) use ($activeCategoryID): array {
                $children = $this->treeFrom($this->subcategoriesOf((int)$category['id']), $activeCategoryID);
                $hasOwnProducts = \in_array((int)$category['id'], $this->categoryIDsWithDirectProducts(), true);
                $hasProducts = $hasOwnProducts || \array_any($children, fn(array $child): bool => $child['hasProducts']);

                return [
                    'title' => $category['title'] ?: $category['label'],
                    'link' => $category['link'] ? '/' . $category['link'] . '/' : '',
                    'active' => (int)$category['id'] === $activeCategoryID,
                    'hasProducts' => $hasProducts,
                    'children' => $children,
                ];
            },
            array: $categories,
        );
    }

    /**
     * Whether a category or any category beneath it has an orderable product: the rule the tree uses, reused by
     * the home page cards so an empty category is not linked.
     */
    public function hasProductsBeneath(int $categoryID): bool
    {
        $hasOwnProducts = \in_array($categoryID, $this->categoryIDsWithDirectProducts(), true);
        if ($hasOwnProducts) return true;

        foreach ($this->subcategoriesOf($categoryID) as $child) {
            if ($this->hasProductsBeneath((int)$child['id'])) return true;
        }

        return false;
    }

    /** Category ids with an orderable product assigned directly; loaded once per request. */
    private ?array $categoryIDsWithDirectProductsCache = null;
    private function categoryIDsWithDirectProducts(): array
    {
        if ($this->categoryIDsWithDirectProductsCache !== null) return $this->categoryIDsWithDirectProductsCache;

        $query = $this->loadSqlFile('category-ids-with-direct-products.sql');
        $this->database->query($query);
        $rows = $this->database->resultset();

        return $this->categoryIDsWithDirectProductsCache = \array_map(
            callback: fn(array $row): int => (int)$row['category_id'],
            array: $rows,
        );
    }
}
