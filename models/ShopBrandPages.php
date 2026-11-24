<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\Strings;
use Osmium\Modules\Admin\Models\Brand;

/**
 * A brand's own page: an ordinary row in the pages table at <prefix>/<brand-slug> (prefix "brands" unless the shop
 * config sets brandPagePrefix), linked to the brand through productbrands.page_id. The shop answers for it like it
 * does for a category's page, listing the brand's products. Pages exist only for brands someone asked for one.
 */
class ShopBrandPages
{
    public const DEFAULT_PREFIX = 'brands';

    public function __construct(private Brand $brands, private AdminPages $pages, private Strings $strings) {}

    /** The folder brand pages sit under, from the shop config with a safe default */
    public static function prefix(?object $shopConfig): string
    {
        $configured = \trim((string) ($shopConfig->brandPagePrefix ?? ''), '/ ');

        return $configured !== '' ? $configured : self::DEFAULT_PREFIX;
    }

    /**
     * Create the brand's page and link it. Returns the new page id.
     *
     * @param array{id: int, name: string, page_id?: ?int} $brand
     * @throws \Exception when the brand already has a page or the address is taken (the message says which)
     */
    public function create(array $brand, string $prefix): int
    {
        $hasPage = !empty($brand['page_id']);
        if ($hasPage) throw new \Exception('This brand already has a page');

        $name = (string) $brand['name'];
        $url = $prefix . '/' . $this->strings->stringToPath($name);

        $pageId = $this->pages->create(input: [
            'url' => $url,
            'parent_page_id' => null,
            'title' => $name,
            'description' => "Everything we stock from {$name}.",
            'robots' => 'index,follow',
        ]);
        $this->brands->setPageId((int) $brand['id'], $pageId);

        return $pageId;
    }

    /**
     * Follow a brand's rename: move its page to the new name's address (the pages code adds the redirect from the old
     * one) and retitle it, but only where the address and title are still the ones made from the old name. Anything
     * customised is left alone.
     *
     * @param array<string, mixed> $page the brand's page row
     * @return array<string, mixed>|null the page's input as it was, to hand to restore(); null when nothing changed
     */
    public function rename(array $page, string $oldName, string $newName): ?array
    {
        $url = (string) $page['url'];
        $folder = \str_contains($url, '/') ? \substr($url, 0, (int) \strrpos($url, '/') + 1) : '';
        $madeFromName = $url === $folder . $this->strings->stringToPath($oldName);
        $titleIsName = (string) $page['title'] === $oldName;

        $newUrl = $madeFromName ? $folder . $this->strings->stringToPath($newName) : $url;
        $newTitle = $titleIsName ? $newName : (string) $page['title'];
        if ($newUrl === $url && $newTitle === (string) $page['title']) return null;

        $before = [
            'url' => $url,
            'parent_page_id' => $page['parent_page_id'] ?? null,
            'title' => (string) $page['title'],
            'description' => (string) ($page['description'] ?? ''),
            'robots' => (string) ($page['robots'] ?? 'index,follow'),
        ];
        $this->pages->update((int) $page['id'], ['url' => $newUrl, 'title' => $newTitle] + $before);

        return $before;
    }

    /** Put a page back as rename() found it. @param array<string, mixed> $before */
    public function restore(int $pageId, array $before): void
    {
        $this->pages->update($pageId, $before);
    }
}
