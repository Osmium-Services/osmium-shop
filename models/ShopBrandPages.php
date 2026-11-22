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
}
