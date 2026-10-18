<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Services\AdminPages;
use Osmium\Modules\Admin\Services\ShopPages;
use Tests\Integration\DatabaseTestCase;

require_once 'app/services/osmium-shop/models/ShopPages.php';

/**
 * The four shop pages follow the Shop service: created when it is installed or enabled,
 * put in the bin (never removed) when it is disabled and restored with the same ids
 * when it is enabled again. The routes file is faked so the real
 * app/config/routes.php is never rewritten.
 */
class ShopPagesTest extends DatabaseTestCase
{
    private Page $page;
    private object $pages;
    private ShopPages $shopPages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = new Page($this->db, 'pages');
        $this->pages = new class ($this->page) extends AdminPages {
            public int $routeRegenerations = 0;

            public function regenerateRoutesFile(): void
            {
                $this->routeRegenerations++;
            }
        };
        $this->shopPages = new ShopPages($this->page, $this->pages);

        foreach ($this->page->getAll() as $row) {
            if (\in_array($row['url'], ShopPages::urls(), true)) $this->page->hardDelete((int) $row['id']);
        } // Known starting point: none of the four exist
        $this->pages->routeRegenerations = 0;
    }

    /** @return array<string, array<string, mixed>> live shop page rows keyed by url (the bin is left out) */
    private function shopRows(): array
    {
        $rows = [];
        foreach ($this->allShopRows() as $url => $row) {
            if ($row['deleted_at'] === null) $rows[$url] = $row;
        }

        return $rows;
    }

    /** @return array<string, array<string, mixed>> every shop page row keyed by url, bin included */
    private function allShopRows(): array
    {
        $rows = [];
        foreach ($this->page->getAll() as $row) {
            if (\in_array($row['url'], ShopPages::urls(), true)) $rows[$row['url']] = $row;
        }

        return $rows;
    }

    public function test_switching_on_creates_all_four_pages(): void
    {
        $this->shopPages->sync(true);

        $rows = $this->shopRows();
        $this->assertEqualsCanonicalizing(ShopPages::urls(), \array_keys($rows));
        $this->assertSame('Shop', $rows['shop']['title']);
        $this->assertSame('index,follow', $rows['shop']['robots']);
        $this->assertSame('noindex,nofollow', $rows['checkout']['robots']);
        $this->assertSame('noindex,follow', $rows['basket']['robots']);
    }

    public function test_switching_off_bins_the_pages_and_leaves_others_alone(): void
    {
        $other = $this->page->getNextId();
        $this->page->insert($other, null, 'zz-keep-me', 'Keep', null, 'index,follow', $this->now());
        $this->shopPages->sync(true);

        $this->shopPages->sync(false);

        $this->assertSame([], $this->shopRows());
        $this->assertCount(4, $this->allShopRows()); // Still there, in the bin
        $this->assertNull($this->page->getById($other)['deleted_at']);
    }

    public function test_switching_back_on_restores_the_same_pages_under_the_same_ids(): void
    {
        $this->shopPages->sync(true);
        $idsBefore = \array_map(fn (array $row) => (int) $row['id'], $this->shopRows());

        $this->shopPages->sync(false);
        $this->shopPages->sync(true);

        $idsAfter = \array_map(fn (array $row) => (int) $row['id'], $this->shopRows());
        $this->assertSame($idsBefore, $idsAfter); // Menu items link by id, so they keep working
        $this->assertCount(4, $this->allShopRows()); // Nothing was created a second time
    }

    public function test_switching_on_keeps_an_existing_page_and_adds_only_the_missing(): void
    {
        $id = $this->page->getNextId();
        $this->page->insert($id, null, 'shop', 'My Edited Shop', null, 'index,follow', $this->now());

        $this->shopPages->sync(true);

        $rows = $this->shopRows();
        $this->assertCount(4, $rows);
        $this->assertSame($id, (int) $rows['shop']['id']);
        $this->assertSame('My Edited Shop', $rows['shop']['title']);
    }

    public function test_switching_off_when_the_pages_are_already_gone_creates_none(): void
    {
        $this->shopPages->sync(false);

        $this->assertSame([], $this->shopRows());
    }

    public function test_syncing_twice_does_not_duplicate(): void
    {
        $this->shopPages->sync(true);
        $this->shopPages->sync(true);

        $this->assertCount(4, $this->shopRows());
    }

    public function test_every_sync_regenerates_the_routes_file(): void
    {
        $this->shopPages->sync(true);
        $this->shopPages->sync(false);

        $this->assertSame(2, $this->pages->routeRegenerations);
    }

    public function test_the_four_pages_are_offered_as_protected_addresses(): void
    {
        $this->assertSame(['shop', 'basket', 'checkout', 'thank-you'], ShopPages::protectedUrls([]));
    }
}
