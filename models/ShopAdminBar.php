<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * What the shop tells the front-end staff bar: which product or category page is on screen (adminbar.context),
 * and the admin link that edits it (adminbar.links). Both read-only and cheap.
 */
class ShopAdminBar
{
    /**
     * @param array{data?: array<string, mixed>} $payload the finished page's view data
     * @return string|null "product:123", "category:45" or "shop-index:all" on those pages, null elsewhere
     */
    public static function context(array $payload): ?string
    {
        $data = $payload['data'] ?? [];

        $productId = (int)($data['product']['id'] ?? 0);
        $isProduct = $productId > 0;
        if ($isProduct) return "product:{$productId}";

        $categoryId = (int)($data['category']['ID'] ?? 0);
        $isCategory = $categoryId > 0;
        if ($isCategory) return "category:{$categoryId}";

        // The shop landing page lists products without being a category; the search page sets no list total
        $isShopIndex = isset($data['shop']['listTotal']);

        return $isShopIndex ? 'shop-index:all' : null;
    }

    /**
     * @param array{context?: string, role?: string} $payload
     * @return array<int, array<string, mixed>>|null the edit link for a product or category page
     */
    public static function links(array $payload): ?array
    {
        $context = (string)($payload['context'] ?? '');

        $isProduct = \preg_match('/^product:(\d+)$/', $context, $match) === 1;
        if ($isProduct) {
            return [['label' => 'Edit product', 'icon' => 'bx-edit-alt', 'path' => 'shop/products/edit/?id=' . (int)$match[1], 'primary' => true]];
        }

        $isShopIndex = $context === 'shop-index:all';
        if ($isShopIndex) {
            // The landing page lists things, like the blog index, so it gets the blog index's icon
            return [['label' => 'Edit categories', 'icon' => 'bx-news', 'path' => 'shop/categories/', 'primary' => true]];
        }

        $isCategory = \preg_match('/^category:\d+$/', $context) === 1;
        if ($isCategory) {
            // The category editor is one tree for every category, so there is no per-category page to open
            return [['label' => 'Edit categories', 'icon' => 'bx-edit-alt', 'path' => 'shop/categories/', 'primary' => true]];
        }

        return null;
    }
}
