<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\AdminPaths;
use Osmium\Modules\Admin\Models\Page;

require_once AdminPaths::path('services/AdminPages.php');

/**
 * Keeps the four pages the shop owns (shop, basket, checkout, thank-you) in step with the service: created when it
 * is installed, put in the bin (never removed) when it is disabled or removed, and restored when it is enabled
 * again, so a site without the shop has no /shop or /basket page for a visitor to find. Binned pages keep their
 * rows, so enabling again brings back the same ids and every menu item linking to them still works.
 *
 * The service.* lifecycle hooks call the static handlers; the instance methods take their collaborators, so tests
 * can fake the routes file.
 */
class ShopPages
{
    /** url => [title, robots] for each page the shop owns */
    private const DEFINITIONS = [
        'shop' => ['Shop', 'index,follow'],
        'basket' => ['Basket', 'noindex,follow'],
        'checkout' => ['Checkout', 'noindex,nofollow'],
        'thank-you' => ['Thank You', 'noindex,nofollow'],
    ];

    public function __construct(private Page $page, private AdminPages $pages) {}

    /** @return string[] the addresses of the shop's pages */
    public static function urls(): array
    {
        return \array_keys(self::DEFINITIONS);
    }

    /** pages.protected_urls: pages no page tool may delete or move */
    public static function protectedUrls(array $payload): array
    {
        return self::urls();
    }

    /** service.installed and service.enabled */
    public static function onEnabled(array $payload): void
    {
        self::forDatabase($payload['db'])->sync(true);
    }

    /** service.disabled and service.removing */
    public static function onDisabled(array $payload): void
    {
        self::forDatabase($payload['db'])->sync(false);
    }

    public function sync(bool $shopOn): void
    {
        $existing = [];
        foreach ($this->page->getAll() as $row) {
            $existing[$row['url']] = $row;
        }

        $now = \date('Y-m-d H:i:s');
        foreach (self::urls() as $url) {
            $row = $existing[$url] ?? null;
            $isMissing = $row === null;
            $isInBin = !$isMissing && $row['deleted_at'] !== null;

            if ($shopOn && $isMissing) $this->create($url);
            if ($shopOn && $isInBin) $this->page->restore((int)$row['id']);
            if (!$shopOn && !$isMissing && !$isInBin) $this->page->softDelete((int)$row['id'], $now);
        }

        $this->pages->regenerateRoutesFile();
    }

    private static function forDatabase(\Osmium\Core\Library\OsmiumPDO $db): self
    {
        $page = new Page($db, 'pages');

        return new self($page, new AdminPages($page));
    }

    private function create(string $url): void
    {
        [$title, $robots] = self::DEFINITIONS[$url];

        $this->page->insert(
            id: $this->page->getNextId(),
            parentPageId: null,
            url: $url,
            title: $title,
            description: null,
            robots: $robots,
            now: \date('Y-m-d H:i:s'),
        );
    }
}
