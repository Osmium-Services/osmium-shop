<?php

require_once __DIR__ . '/../../../../../core/library/Cache.php'; // Product search API endpoint
require_once __DIR__ . '/../models/ShopModel.php';
require_once __DIR__ . '/../models/ShopSearch.php';
require_once __DIR__ . '/../services/ShopSearchService.php';

use Osmium\Core\Features\IpRateLimiter;
use Osmium\Core\Library\Cache;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Shop\Models\ShopSearch;
use Osmium\Modules\Shop\Services\ShopSearchService;

$rateLimiter = new IpRateLimiter(namespace: 'search', maxAttempts: 20, windowSeconds: 10);
$trustProxyHeaders = $this->osmium->config->site->trustProxyHeaders ?? false;
$rateLimited = $rateLimiter->tooManyRequests($this->osmium->ipAddress->getVisitorIP($trustProxyHeaders));
if ($rateLimited) {
    $this->osmium->jsonError('Too many searches, please slow down.', 429);
}

$input = $this->osmium->getJsonInput();
$query = \is_string($input['query'] ?? null) ? \trim($input['query']) : ''; // A list or number sent as the query is no query, not a crash

if (\strlen($query) < 2) {
    $this->osmium->jsonSuccess(['success' => true, 'results' => []]); // Minimum 2 characters
}

try {
    $searchService = new ShopSearchService(
        search: new ShopSearch($this->osmium->dataSource, 'products'),
        synonymsPath: $_SERVER['DOCUMENT_ROOT'] . '/app/modules/shop/config/search-synonyms.php', // Site data, kept in the site's module folder so an update never overwrites it
    ); // Initialize service

    $cache = new Cache(namespace: 'search', ttl: 60);
    $cacheKey = \strtolower($query); // Initialize cache (60s TTL)

    $taxPercent = (float)($this->osmium->config->shop->taxRatePercent ?? 20);
    $taxRate = 1 + ($taxPercent / 100); // Get tax rate from config

    $osmium = $this->osmium;
    $results = $cache->get(
        key: $cacheKey,
        fallback: fn() => $searchService->search(query: $query, taxRate: $taxRate),
    ); // Get from cache or execute search

    foreach ($results as &$result) {
        $result['image'] = $osmium->imagePath($result['image']); // Select optimised image format based on browser support (outside cache)
    }
    unset($result);

    // Tell services a search happened (search analytics records it). Runs every time, not just on a cache miss.
    // Hooks are best-effort: a failing handler is logged and skipped, so it can never break the customer's search.
    $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
    $hooks->dispatch(
        event: 'search.performed',
        payload: ['term' => $query, 'resultCount' => \count($results), 'dataSource' => $this->osmium->dataSource],
    );

    $this->osmium->jsonSuccess(['success' => true, 'results' => $results]);

} catch (Exception $e) {
    // Never return the raw exception on a public endpoint. A SQL syntax error here
    // previously exposed the database engine, its version and query fragments to
    // anyone who called the API. 500 (instead of the default 200) also means an
    // outage is visible to uptime monitoring rather than only to customers -
    // product-search.js reads the JSON body regardless of status code.
    \error_log('Product search failed: ' . $e->getMessage());
    $this->osmium->jsonError('Search is temporarily unavailable, please try again.', 500);
}
