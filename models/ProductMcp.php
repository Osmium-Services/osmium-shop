<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

use Osmium\Core\Library\McpToolException;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Admin\Models\Changelog;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Models\Product;
use Osmium\Modules\Admin\Models\ProductCategory;
use Osmium\Modules\Admin\Models\ProductCategoryAssignment;
use Osmium\Modules\Admin\Models\Status;

/**
 * Core's product tools for the /mcp endpoint: find products by name, list the categories and statuses, rename a category, set a product's
 * availability status, set or clear a restocking product's expected restock date, and add a product to a category or take it out.
 * Nothing else about a product can be changed here: price and descriptions stay in the admin.
 * The restock date and the status each have their own query that touches only those columns, never the admin
 * form's save of every field.
 */
class ProductMcp
{
    private const RECORD_TYPE = 'product';
    private const MAX_DAYS_AHEAD = 730;
    private const MAX_LABEL_LENGTH = 50;
    private const FIND_LIMIT = 25;
    private const MIN_SEARCH_LENGTH = 2;

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        $idAndName = [
            'id' => ['type' => 'integer', 'description' => 'The product id from products_attention'],
            'expected_name' => ['type' => 'string', 'description' => "The product's name exactly as products_attention lists it (brand and model). The call is refused if it does not match the id"],
        ];

        return [
            [
                'name' => 'product_categories_list',
                'description' => 'The live product categories with id, label and parent, to pick a category for product_category_add.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::categoriesList',
            ],
            [
                'name' => 'products_find',
                'description' => 'Find live products by name or reference, to get the id and exact name the product tools need. The search matches brand, model and reference; several words must all appear in that order (for example "acme 100w"). Up to ' . self::FIND_LIMIT . ' matches with id, name, reference, status and restock date; says when there are more, so narrow the search.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['search' => ['type' => 'string', 'description' => 'At least ' . self::MIN_SEARCH_LENGTH . ' characters of the brand, model or reference']],
                    'required' => ['search'],
                ],
                'handler' => self::class . '::find',
            ],
            [
                'name' => 'product_statuses_list',
                'description' => 'The live product availability statuses with id, short and long label, whether the product counts as available, whether it is a restocking status and whether it is enabled. Use it to pick a status for product_status_set.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::statusesList',
            ],
            [
                'name' => 'product_status_set',
                'description' => 'Change the availability status of a live product. Give the product id and its exact expected_name, and the status_id and exact expected_status (the short label) from product_statuses_list. Only the status changes. Moving to a status that is not a restocking one clears the product\'s restock date; moving to a restocking one leaves the date as it is, so set it with product_restock_date_set. Disabled statuses are refused. Undo by setting the old status again (the result names it).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $idAndName + [
                        'status_id' => ['type' => 'integer', 'description' => 'The status id from product_statuses_list'],
                        'expected_status' => ['type' => 'string', 'description' => "The status's exact short label as listed. The call is refused if it does not match the id"],
                    ],
                    'required' => ['id', 'expected_name', 'status_id', 'expected_status'],
                ],
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::setStatus',
            ],
            [
                'name' => 'product_category_remove',
                'description' => 'Take a live product out of a category. Give the exact category label as listed by product_categories_list. Refused if the product is not in that category. The category and the product stay; only the link goes, and the product loses its place in the category\'s product order, so adding it back puts it at the end.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $idAndName + [
                        'category_id' => ['type' => 'integer', 'description' => 'The category id from product_categories_list'],
                        'expected_category' => ['type' => 'string', 'description' => "The category's exact label as listed. The call is refused if it does not match the id"],
                    ],
                    'required' => ['id', 'expected_name', 'category_id', 'expected_category'],
                ],
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::removeCategory',
            ],
            [
                'name' => 'product_category_rename',
                'description' => 'Rename a live product category. Give the category id from product_categories_list, its exact current label as expected_label, and the new label (up to ' . self::MAX_LABEL_LENGTH . ' characters). Only the label changes: the address, parent, order and products stay as they are, and the category page title follows the new label only when the category has no title of its own. Refused if a sibling already has that label, or if someone is editing categories in the admin. Undo by renaming it back.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'The category id from product_categories_list'],
                        'expected_label' => ['type' => 'string', 'description' => "The category's exact current label as listed. The call is refused if it does not match the id"],
                        'label' => ['type' => 'string', 'description' => 'The new label'],
                    ],
                    'required' => ['id', 'expected_label', 'label'],
                ],
                'write' => true,
                'handler' => self::class . '::renameCategory',
            ],
            [
                'name' => 'product_restock_date_set',
                'description' => 'Set the expected restock date of a live product whose status is a restocking one, or clear it. Date is YYYY-MM-DD, today or later and within two years; leave it blank to clear. Only that one field changes. Refused if the product is not in a restocking status (change the status in the admin). Undo by setting the old date again.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $idAndName + ['restock_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or blank to clear']],
                    'required' => ['id', 'expected_name', 'restock_at'],
                ],
                'write' => true,
                'destructive' => true,
                'handler' => self::class . '::setRestockDate',
            ],
            [
                'name' => 'product_category_add',
                'description' => 'Add a live product to a live category, for example to fix a product in no category. Give the exact category label as listed by product_categories_list. Refused if it is already in that category. To take a product out of a category use product_category_remove.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $idAndName + [
                        'category_id' => ['type' => 'integer', 'description' => 'The category id from product_categories_list'],
                        'expected_category' => ['type' => 'string', 'description' => "The category's exact label as listed. The call is refused if it does not match the id"],
                    ],
                    'required' => ['id', 'expected_name', 'category_id', 'expected_category'],
                ],
                'write' => true,
                'handler' => self::class . '::addCategory',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function categoriesList(array $call): array
    {
        $rows = (new ProductCategory($call['dataSource'], 'productcategories'))->getSimpleList();

        return ['total' => \count($rows), 'categories' => self::shapeCategories($rows)];
    }

    /**
     * @param array<int, array<string, mixed>> $rows from category-list-simple.sql
     * @return array<int, array<string, mixed>>
     */
    public static function shapeCategories(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['id']] = (string) $row['label'];
        }

        $list = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $list[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'parent' => $parentId === null ? null : ($labels[$parentId] ?? null),
            ];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function setRestockDate(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        [$id, $expectedName] = self::idAndName($arguments);
        $products = new Product($call['dataSource'], 'products');
        $product = self::requireProduct($products->getForAssistant($id), $expectedName, $id);

        if (!(bool) $product['restocking']) {
            throw new McpToolException('This product is not in a restocking status, so it has no restock date. Change its status in the admin first.');
        }

        $given = $arguments['restock_at'] ?? null;
        if (!\is_string($given)) throw new McpToolException('Give restock_at as YYYY-MM-DD, or blank to clear it.');

        $error = self::dateError(\trim($given), \date('Y-m-d'));
        if ($error !== null) throw new McpToolException($error);

        $date = \trim($given) === '' ? null : \trim($given);
        $products->setRestockAt($id, $date);

        $name = self::name($product);
        self::changelog($call)->log(description: "Updated product: {$name}", recordType: self::RECORD_TYPE, recordId: $id);
        $synced = static::notifyServices($call, $id);

        return [
            'updated' => true,
            'id' => $id,
            'name' => $name,
            'restock_at' => $date,
            'was' => $product['restock_at'],
            'services_notified' => $synced,
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function addCategory(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        [$id, $expectedName] = self::idAndName($arguments);
        $products = new Product($call['dataSource'], 'products');
        $product = self::requireProduct($products->getForAssistant($id), $expectedName, $id);

        $categoryId = $arguments['category_id'] ?? null;
        $expectedCategory = \trim((string) ($arguments['expected_category'] ?? ''));
        $valid = \is_int($categoryId) && $categoryId > 0 && $expectedCategory !== '';
        if (!$valid) throw new McpToolException('Give the category_id (a whole number) and its exact expected_category.');

        $category = (new ProductCategory($call['dataSource'], 'productcategories'))->getById($categoryId);
        if ($category === null) throw new McpToolException("No live category with id {$categoryId}. Check product_categories_list.");
        if (\trim((string) $category['label']) !== $expectedCategory) {
            throw new McpToolException("The category label does not match category {$categoryId}. Check product_categories_list and try again.");
        }

        $assignments = new ProductCategoryAssignment($call['dataSource'], 'products_productcategories');
        if ($assignments->exists($id, $categoryId)) throw new McpToolException('This product is already in that category.');

        $assignments->insert($id, $categoryId);

        $name = self::name($product);
        self::changelog($call)->log(
            description: "Added product {$name} to category {$category['label']}",
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );

        return ['added' => true, 'id' => $id, 'name' => $name, 'category_id' => $categoryId, 'category' => (string) $category['label'], 'undo' => 'Remove it again with product_category_remove'];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function removeCategory(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        [$id, $expectedName] = self::idAndName($arguments);
        $products = new Product($call['dataSource'], 'products');
        $product = self::requireProduct($products->getForAssistant($id), $expectedName, $id);

        $categoryId = $arguments['category_id'] ?? null;
        $expectedCategory = \trim((string) ($arguments['expected_category'] ?? ''));
        $valid = \is_int($categoryId) && $categoryId > 0 && $expectedCategory !== '';
        if (!$valid) throw new McpToolException('Give the category_id (a whole number) and its exact expected_category.');

        $category = (new ProductCategory($call['dataSource'], 'productcategories'))->getById($categoryId);
        if ($category === null) throw new McpToolException("No live category with id {$categoryId}. Check product_categories_list.");
        if (\trim((string) $category['label']) !== $expectedCategory) {
            throw new McpToolException("The category label does not match category {$categoryId}. Check product_categories_list and try again.");
        }

        $assignments = new ProductCategoryAssignment($call['dataSource'], 'products_productcategories');
        if (!$assignments->exists($id, $categoryId)) throw new McpToolException('This product is not in that category.');

        $assignments->delete($id, $categoryId);

        $name = self::name($product);
        self::changelog($call)->log(
            description: "Removed product {$name} from category {$category['label']}",
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );

        return ['removed' => true, 'id' => $id, 'name' => $name, 'category_id' => $categoryId, 'category' => (string) $category['label'], 'undo' => 'Add it to the category again with product_category_add; it goes to the end of the category\'s product order'];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function find(array $call): array
    {
        $search = $call['arguments']['search'] ?? null;
        $pattern = \is_string($search) ? self::searchPattern($search) : null;
        if ($pattern === null) throw new McpToolException('Give a search of at least ' . self::MIN_SEARCH_LENGTH . ' characters.');

        $rows = (new Product($call['dataSource'], 'products'))->findForAssistant($pattern, self::FIND_LIMIT + 1);

        return self::shapeFound($rows);
    }

    /**
     * The LIKE pattern for a search: words in order with anything between, wildcard characters in the text taken
     * literally. Null when the search is too short.
     */
    public static function searchPattern(string $search): ?string
    {
        $words = \preg_split('/\s+/', \trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (\mb_strlen(\implode('', $words)) < self::MIN_SEARCH_LENGTH) return null;

        $escaped = \array_map(static fn(string $word): string => \addcslashes($word, '%_\\\\'), $words);

        return '%' . \implode('%', $escaped) . '%';
    }

    /**
     * @param array<int, array<string, mixed>> $rows from product-find-for-assistant.sql, one more than the limit at most
     * @return array<string, mixed>
     */
    public static function shapeFound(array $rows): array
    {
        $hasMore = \count($rows) > self::FIND_LIMIT;
        $products = \array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => self::name($row),
            'reference' => (string) $row['reference'],
            'status' => $row['status_name'],
            'restock_at' => $row['restock_at'],
        ], \array_slice($rows, 0, self::FIND_LIMIT));

        return ['shown' => \count($products), 'more' => $hasMore, 'products' => $products];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function statusesList(array $call): array
    {
        $rows = (new Status($call['dataSource'], 'productstatus'))->getForAssistant();

        return ['total' => \count($rows), 'statuses' => self::shapeStatuses($rows)];
    }

    /**
     * @param array<int, array<string, mixed>> $rows from status-list-for-assistant.sql
     * @return array<int, array<string, mixed>>
     */
    public static function shapeStatuses(array $rows): array
    {
        return \array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['descr_short'],
            'long_label' => (string) $row['descr_long'],
            'available' => (bool) $row['product_available'],
            'restocking' => (bool) $row['restocking'],
            'enabled' => (bool) $row['enabled'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function setStatus(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        [$id, $expectedName] = self::idAndName($arguments);
        $products = new Product($call['dataSource'], 'products');
        $product = self::requireProduct($products->getForAssistant($id), $expectedName, $id);

        $statusId = $arguments['status_id'] ?? null;
        $expectedStatus = \trim((string) ($arguments['expected_status'] ?? ''));
        $valid = \is_int($statusId) && $statusId > 0 && $expectedStatus !== '';
        if (!$valid) throw new McpToolException('Give the status_id (a whole number) and its exact expected_status.');

        $status = (new Status($call['dataSource'], 'productstatus'))->getById($statusId);
        $isLive = $status !== null && $status['deleted_at'] === null;
        if (!$isLive) throw new McpToolException("No live status with id {$statusId}. Check product_statuses_list.");
        if (\trim((string) $status['descr_short']) !== $expectedStatus) {
            throw new McpToolException("The status label does not match status {$statusId}. Check product_statuses_list and try again.");
        }
        if (!(bool) $status['enabled']) throw new McpToolException('That status is disabled. Enable it in the admin first.');
        if ((int) $product['status_id'] === $statusId) throw new McpToolException('The product already has that status.');

        $restocking = (bool) $status['restocking'];
        $products->setStatus($id, $statusId, $restocking);

        $name = self::name($product);
        self::changelog($call)->log(description: "Updated product: {$name}", recordType: self::RECORD_TYPE, recordId: $id);
        $synced = static::notifyServices($call, $id);

        return [
            'updated' => true,
            'id' => $id,
            'name' => $name,
            'status' => (string) $status['descr_short'],
            'was' => ['status_id' => (int) $product['status_id'], 'status' => $product['status_name'], 'restock_at' => $product['restock_at']],
            'restock_date_cleared' => !$restocking && $product['restock_at'] !== null,
            'needs_restock_date' => $restocking && $product['restock_at'] === null,
            'services_notified' => $synced,
        ];
    }

    /**
     * Renames a live category. Only the label changes, so the address, parent and products stay put. When the
     * category has no title of its own the admin shows the label as its page title, so that follows the rename.
     *
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function renameCategory(array $call): array
    {
        $arguments = $call['arguments'] ?? [];
        $id = $arguments['id'] ?? null;
        $expected = \trim((string) ($arguments['expected_label'] ?? ''));
        $given = $arguments['label'] ?? null;
        $valid = \is_int($id) && $id > 0 && $expected !== '' && \is_string($given);
        if (!$valid) throw new McpToolException('Give the category id (a whole number), its exact expected_label and the new label.');

        $categories = new ProductCategory($call['dataSource'], 'productcategories');
        $category = $categories->getById($id);
        if ($category === null) throw new McpToolException("No live category with id {$id}. Check product_categories_list.");
        if (\trim((string) $category['label']) !== $expected) {
            throw new McpToolException("The label does not match category {$id}. Check product_categories_list and try again.");
        }

        $label = \trim($given);
        $error = self::labelError($label, $id, $category['parent_id'] === null ? null : (int) $category['parent_id'], $categories->getSimpleList());
        if ($error !== null) throw new McpToolException($error);

        $isLocked = $categories->getCurrentLock() !== null;
        if ($isLocked) throw new McpToolException('Someone is editing the categories in the admin. Try again in a few minutes.');

        $pageLink = $categories->getPageByCategory($id);
        $pageId = $pageLink ? (int) $pageLink['page_id'] : null;
        $categories->update([
            'id' => $id,
            'parent_id' => $category['parent_id'],
            'page_id' => $pageId,
            'label' => $label,
            'title' => $category['title'],
            'subtitle' => $category['subtitle'],
            'description' => $category['description'],
            'image' => $category['image'],
            'social_image' => $category['social_image'],
        ]);

        $titleFollows = $pageId !== null && \trim((string) ($category['title'] ?? '')) === '';
        if ($titleFollows) self::renamePageTitle($call, $pageId, $label);

        self::changelog($call)->log(description: "Updated category: {$label}", recordType: 'category', recordId: $id);

        return ['updated' => true, 'id' => $id, 'label' => $label, 'was' => $expected, 'page_title_updated' => $titleFollows];
    }

    /**
     * What is wrong with a new category label, or null when it is fine. A sibling is a live category with the same
     * parent.
     *
     * @param array<int, array<string, mixed>> $live from category-list-simple.sql
     */
    public static function labelError(string $label, int $id, ?int $parentId, array $live): ?string
    {
        if ($label === '') return 'The new label cannot be blank.';
        if (\mb_strlen($label) > self::MAX_LABEL_LENGTH) return 'The label must be ' . self::MAX_LABEL_LENGTH . ' characters or fewer.';

        foreach ($live as $row) {
            $isSibling = (int) $row['id'] !== $id && ($row['parent_id'] === null ? null : (int) $row['parent_id']) === $parentId;
            if ($isSibling && \mb_strtolower(\trim((string) $row['label'])) === \mb_strtolower($label)) {
                return 'Another category at the same level already has that label.';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $call */
    private static function renamePageTitle(array $call, int $pageId, string $title): void
    {
        $pages = new Page($call['dataSource'], 'pages');
        $page = $pages->getById($pageId);
        if ($page === null) return;

        $adminPages = new AdminPages(page: $pages, urlTrailingSlash: $call['osmium']->config->site->urlTrailingSlash ?? false);
        $adminPages->update($pageId, [
            'url' => (string) $page['url'],
            'title' => $title,
            'description' => (string) ($page['description'] ?? ''),
            'robots' => (string) ($page['robots'] ?? 'index,follow'),
            'parent_page_id' => $page['parent_page_id'],
        ]);
    }

    /**
     * What is wrong with a restock date, or null when it is fine. Blank clears the date.
     *
     * @param string $today YYYY-MM-DD, passed in so the rule can be tested
     */
    public static function dateError(string $date, string $today): ?string
    {
        if ($date === '') return null;

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $isRealDate = $parsed !== false && $parsed->format('Y-m-d') === $date;
        if (!$isRealDate) return 'The restock date must be a real date as YYYY-MM-DD, or blank to clear it.';

        $limit = (new \DateTimeImmutable($today))->modify('+' . self::MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
        if ($date < $today) return 'The restock date cannot be in the past.';
        if ($date > $limit) return 'The restock date is more than two years away, which is probably a typo.';

        return null;
    }

    /**
     * @param array<string, mixed> $row brand_name and model_name from product-get-for-assistant.sql
     */
    public static function name(array $row): string
    {
        return \trim((string) ($row['brand_name'] ?? '') . ' ' . (string) ($row['model_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string}
     */
    public static function idAndName(array $arguments): array
    {
        $id = $arguments['id'] ?? null;
        $name = $arguments['expected_name'] ?? null;
        $valid = \is_int($id) && $id > 0 && \is_string($name) && \trim($name) !== '';
        if (!$valid) throw new McpToolException('Give the product id (a whole number) and its exact expected_name.');

        return [$id, \trim($name)];
    }

    /**
     * Refuses unless the product exists, is live and its name is exactly the one given, so a slip of the id cannot
     * change the wrong product.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private static function requireProduct(?array $row, string $expectedName, int $id): array
    {
        $isLive = $row !== null && $row['deleted_at'] === null;
        if (!$isLive) throw new McpToolException("No live product with id {$id}. Check products_attention.");
        if (self::name($row) !== $expectedName) {
            throw new McpToolException("The name does not match product {$id}. Check products_attention and try again.");
        }

        return $row;
    }

    /**
     * Tells any installed service listening for product changes (such as Google Merchant Center) that a product
     * was saved, as the admin does. It never blocks the change: a failure is logged and reported as false.
     *
     * @param array<string, mixed> $call
     */
    protected static function notifyServices(array $call, int $productId): bool
    {
        try {
            $loader = new ServiceLoader(new ServiceModel($call['dataSource']));
            $shopFolder = $loader->moduleFolder('shop') ?? \dirname(__DIR__, 3) . '/modules/shop'; // The service that supplies the shop module
            require_once $shopFolder . '/core/OsmiumShopClass.php'; // The admin has it loaded already; /mcp does not
            $shop = new \Osmium\Modules\Shop\Core\OsmiumShop($call['osmium']);
            (new ServiceHooks($loader))->dispatch(
                event: 'product.saved',
                payload: ['productId' => $productId, 'osmium' => $call['osmium'], 'shop' => $shop],
            );

            return true;
        } catch (\Throwable $e) {
            \error_log("ProductMcp: product.saved was not sent for product {$productId}: {$e->getMessage()}");

            return false;
        }
    }

    /** @param array<string, mixed> $call */
    private static function changelog(array $call): Changelog
    {
        $changelog = new Changelog($call['dataSource'], 'changelog');
        $changelog->setGetUser(fn(): array => ['username' => $call['actor']]);

        return $changelog;
    }
}
