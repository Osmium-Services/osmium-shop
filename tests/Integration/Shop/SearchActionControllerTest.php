<?php

declare(strict_types=1);

namespace Tests\Integration\Shop;

use Osmium\Core\Library\Autoload;
use Osmium\Core\Library\Cache;
use Osmium\Core\Library\IpAddress;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Models\Model;
use Osmium\Core\Osmium;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Models\Page;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/library/Autoload.php';

/**
 * The public product search endpoint (shop/controllers/search-action.php): the per-visitor rate limit, the two
 * character minimum, which products are found and how they are shown (price with tax, availability, address, image),
 * what is left out (binned, unavailable statuses, products with no live page), and the one-minute result cache. Runs
 * the real script over real products (rolled back); the rate-limit and result cache files it makes are removed.
 */
class SearchActionControllerTest extends ShopDatabaseTestCase
{
    /** @var object[] Every connection stays referenced: freeing an earlier test's would roll back a later test's transaction */
    private static array $keepAlive = [];

    private string $tag;
    private string $word;
    private string $ip;
    private int $brandId;
    private array $savedServer;
    private mixed $savedPagesById;
    private array $routedPages = [];
    private array $cacheBefore;
    private array $shopConfig;
    private string $body = '';

    protected function setUp(): void
    {
        parent::setUp();
        self::$keepAlive[] = $this->db;
        (new Autoload('app/config'))->register();

        $this->tag = 'zq' . \substr(\md5(\uniqid()), 0, 8);
        $this->word = "zqword{$this->tag}"; // One unusual word, so only this test's products match it
        $this->ip = '203.0.113.' . \random_int(1, 254);
        $this->savedServer = $_SERVER;
        $_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
        $_SERVER['REMOTE_ADDR'] = $this->ip;
        $this->shopConfig = ['taxRatePercent' => 20];
        $this->cacheBefore = $this->cacheFiles();
        $this->savedPagesById = (new \ReflectionProperty(Model::class, 'pagesById'))->getValue();
        $this->routedPages = Model::pagesById();

        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "{$this->word} Brand"]);
        $this->brandId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        foreach (\array_diff($this->cacheFiles(), $this->cacheBefore) as $file) {
            @\unlink($file);
        }
        (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->savedPagesById);
        parent::tearDown();
    }

    private function cacheFiles(): array
    {
        $root = \dirname(__DIR__, 6) . '/cache';

        return \array_merge(\glob("{$root}/search/*.cache") ?: [], \glob("{$root}/rate-limit-search/*.cache") ?: []);
    }

    private function product(string $model, array $overrides = [], bool $withPage = true, string $pageState = 'live'): int
    {
        $id = $this->createTestProduct($overrides + [
            'brand_id' => $this->brandId, 'status_id' => $this->statusId(true), 'model_name' => $model, 'price_exc_tax' => 100,
        ]);
        if ($withPage) {
            $pages = new Page($this->db, 'pages');
            $pageId = $pages->getNextId();
            $url = "zq-{$this->tag}-p{$id}";
            $pages->insert($pageId, null, $url, 'Title', null, 'index,follow', \date('Y-m-d H:i:s'));
            $this->db->insert(tableName: $this->db->tablePrefix() . 'products_pages', data: ['product_id' => $id, 'page_id' => $pageId, 'is_qr' => 0]);
            if ($pageState !== 'unrouted') $this->routedPages[$pageId] = ['id' => $pageId, 'url' => $url, 'deleted_at' => $pageState === 'deleted' ? '2026-01-01 00:00:00' : null];
            (new \ReflectionProperty(Model::class, 'pagesById'))->setValue(null, $this->routedPages);
        }

        return $id;
    }

    /** @return array{success: ?array, error: ?array} */
    private function search(mixed $query): array
    {
        $this->body = \json_encode(['query' => $query]);
        $osmium = new class ($this->db, $this->shopConfig, $this->body) extends Osmium {
            public ?array $success = null;
            public ?array $error = null;

            public function __construct(OsmiumPDO $db, array $shop, private string $body)
            {
                $this->dataSource = $db;
                $this->ipAddress = new IpAddress();
                $this->config = new OsmiumConfig((object) [
                    'site' => (object) ['scheme' => 'https', 'subdomain' => '', 'domain' => 'shop.test', 'trustProxyHeaders' => false],
                    'shop' => (object) $shop,
                ]);
            }

            public function requestBody(): string
            {
                return $this->body;
            }

            public function imagePath(string $partialImagePath): string
            {
                return '/images' . $partialImagePath . '.webp';
            }

            public function jsonSuccess(array $result, int $code = 200): void
            {
                $this->success = $result;
                throw new ResponseSentException();
            }

            public function jsonError(string $message, int $code = 200, array $extra = []): void
            {
                $this->error = ['message' => $message, 'code' => $code];
                throw new ResponseSentException();
            }
        };

        $context = new class ($osmium) {
            public function __construct(public Osmium $osmium) {}
        };
        $script = function (): void {
            require 'app/services/osmium-shop/modules/shop/controllers/search-action.php';
        };
        try {
            \Closure::bind($script, $context, $context)();
        } catch (ResponseSentException) {
        }

        return ['success' => $osmium->success, 'error' => $osmium->error];
    }

    // ---- guards -------------------------------------------------------------------

    public function test_a_visitor_over_twenty_searches_in_ten_seconds_is_told_to_slow_down(): void
    {
        (new Cache(namespace: 'rate-limit-search'))->set(key: $this->ip, value: ['count' => 20, 'firstAttempt' => \time()]);

        $result = $this->search($this->word);

        $this->assertSame(['message' => 'Too many searches, please slow down.', 'code' => 429], $result['error']);
        $this->assertNull($result['success']);
    }

    public function test_the_twentieth_search_is_still_answered(): void
    {
        (new Cache(namespace: 'rate-limit-search'))->set(key: $this->ip, value: ['count' => 19, 'firstAttempt' => \time()]);

        $this->assertNull($this->search($this->word)['error']);
    }

    public function test_a_search_shorter_than_two_characters_returns_nothing_without_searching(): void
    {
        foreach (['', 'a', ' a ', '  '] as $short) {
            $this->assertSame(['success' => true, 'results' => []], $this->search($short)['success'], \json_encode($short));
        }
        $this->assertSame(['success' => true, 'results' => []], $this->search(null)['success'], 'no query at all');
    }

    public function test_a_query_that_is_not_text_is_treated_as_no_query(): void
    {
        foreach ([['a', 'list'], ['key' => 'value'], 12345, true] as $odd) {
            $this->assertSame(['success' => true, 'results' => []], $this->search($odd)['success'], \json_encode($odd));
        }
    }

    // ---- finding products ---------------------------------------------------------

    public function test_a_search_finds_a_product_and_shows_its_price_with_tax_address_and_image(): void
    {
        $id = $this->product('Alpha Panel', ['price_exc_tax' => 100]);

        $results = $this->search($this->word)['success']['results'];

        $this->assertCount(1, $results);
        $this->assertSame($id, $results[0]['id']);
        $this->assertSame("{$this->word} Brand Alpha Panel", $results[0]['title']);
        $this->assertSame("/zq-{$this->tag}-p{$id}/", $results[0]['url']);
        $this->assertSame('120.00', $results[0]['price']);
        $this->assertTrue($results[0]['available']);
        $this->assertNull($results[0]['status']);
        $this->assertSame("/images/shop/{$this->word}-brand/alpha-panel/thumb/{$this->word}-brand-alpha-panel.webp", $results[0]['image']);
    }

    public function test_the_tax_rate_from_the_shop_settings_prices_the_results(): void
    {
        $this->product('Taxed', ['price_exc_tax' => 100]);
        $this->shopConfig['taxRatePercent'] = '5';

        $this->assertSame('105.00', $this->search($this->word)['success']['results'][0]['price']);
    }

    public function test_the_search_ignores_case_and_spaces_around_the_words(): void
    {
        $this->product('Beta');

        $results = $this->search('  ' . \strtoupper($this->word) . '  ')['success']['results'];

        $this->assertCount(1, $results);
    }

    public function test_an_unavailable_product_shows_its_status(): void
    {
        $unavailable = $this->statusId(false);
        $this->product('Gone Soon', ['status_id' => $unavailable]);

        $result = $this->search($this->word)['success']['results'][0];

        $this->assertFalse($result['available']);
        $this->assertNotNull($result['status']);
    }

    public function test_available_products_come_before_unavailable_ones(): void
    {
        $unavailable = $this->product('Aaa Unavailable', ['status_id' => $this->statusId(false)]);
        $available = $this->product('Zzz Available');

        $ids = \array_column($this->search($this->word)['success']['results'], 'id');

        $this->assertSame([$available, $unavailable], $ids);
    }

    public function test_binned_products_and_products_with_no_live_page_are_not_found(): void
    {
        $this->product('Shown');
        $binned = $this->product('Binned');
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $binned])->execute();
        $this->product('No Page', withPage: false);

        $results = $this->search($this->word)['success']['results'];

        $this->assertSame(["{$this->word} Brand Shown"], \array_column($results, 'title'));
    }

    public function test_a_product_whose_page_is_deleted_or_not_routed_is_not_shown(): void
    {
        $this->product('Alive');
        $this->product('Deleted Page', pageState: 'deleted');
        $this->product('Unrouted Page', pageState: 'unrouted');

        $results = $this->search($this->word)['success']['results'];

        $this->assertSame(["{$this->word} Brand Alive"], \array_column($results, 'title'));
    }

    public function test_dots_in_a_model_name_become_underscores_in_the_image_path(): void
    {
        $this->product('Dot 1.5 Panel');

        $image = $this->search($this->word)['success']['results'][0]['image'];

        $this->assertSame("/images/shop/{$this->word}-brand/dot-1_5-panel/thumb/{$this->word}-brand-dot-1_5-panel.webp", $image);
    }

    public function test_two_characters_are_enough_to_search_but_one_is_not(): void
    {
        $id = $this->product('Qz', ['reference' => "QZ-{$this->tag}"]);
        $this->shopConfig['taxRatePercent'] = 20;

        $two = \array_column($this->search('qz')['success']['results'], 'id');
        $one = $this->search('q')['success']['results'];

        $this->assertContains($id, $two);
        $this->assertSame([], $one);
    }

    public function test_the_rate_limit_window_is_ten_seconds(): void
    {
        (new Cache(namespace: 'rate-limit-search'))->set(key: $this->ip, value: ['count' => 20, 'firstAttempt' => \time() - 11]);

        $this->assertNull($this->search($this->word)['error'], 'a full count from over ten seconds ago no longer counts');
    }

    public function test_a_search_with_nothing_matching_returns_an_empty_list(): void
    {
        $this->product('Something');

        $this->assertSame(['success' => true, 'results' => []], $this->search("{$this->word}nomatch")['success']);
    }

    public function test_at_most_ten_results_come_back(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->product("Item {$i}");
        }

        $this->assertCount(10, $this->search($this->word)['success']['results']);
    }

    // ---- the result cache ---------------------------------------------------------

    public function test_a_repeated_search_is_answered_from_the_cache_for_a_minute(): void
    {
        $id = $this->product('Cached', ['price_exc_tax' => 100]);
        $first = $this->search($this->word)['success']['results'];
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET price_exc_tax = 200 WHERE id = :id", bindings: [':id' => $id])->execute();

        $second = $this->search($this->word)['success']['results'];
        $upper = $this->search(\strtoupper($this->word))['success']['results'];

        $this->assertSame('120.00', $first[0]['price']);
        $this->assertSame('120.00', $second[0]['price'], 'the stored result is reused');
        $this->assertSame('120.00', $upper[0]['price'], 'whatever the case of the query');
    }

    public function test_the_image_address_is_worked_out_after_the_cache_so_it_always_follows_the_site(): void
    {
        $this->product('Imagery');
        $this->search($this->word);

        $cachedFiles = \array_diff($this->cacheFiles(), $this->cacheBefore);
        foreach ($cachedFiles as $file) {
            if (\str_contains((string) \file_get_contents($file), 'thumb')) {
                $this->assertStringNotContainsString('.webp', (string) \file_get_contents($file), 'the cache holds the path without its extension');
            }
        }
        $this->assertStringEndsWith('.webp', $this->search($this->word)['success']['results'][0]['image']);
    }
}
