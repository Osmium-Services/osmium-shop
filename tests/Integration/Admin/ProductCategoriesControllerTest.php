<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Library\Strings;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\ProductCategoriesController;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Models\ProductCategory;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminPages;
use Osmium\Modules\Admin\Services\AdminShopCategories;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/models/ProductCategory.php';
require_once 'app/services/osmium-shop/models/AdminShopCategories.php';
require_once 'app/services/osmium-shop/controllers/ProductCategoriesController.php';

/**
 * The same category model, but emptying the snapshot tables with DELETE. TRUNCATE ends the open transaction in
 * MySQL, which would leave every test's rows behind in the dev database.
 */
class RollbackSafeProductCategory extends ProductCategory
{
    public function truncateSnapshot(): void
    {
        $this->database->query('DELETE FROM ' . $this->database->tablePrefix() . 'productcategories_snapshot');
        $this->database->execute();
    }

    public function truncateAssocSnapshot(): void
    {
        $this->database->query('DELETE FROM ' . $this->database->tablePrefix() . 'products_productcategories_snapshot');
        $this->database->execute();
    }
}

/**
 * The category screen's action endpoint and index: adding, editing, nesting, removing and ordering categories
 * and the products inside them, the edit lock between editors, and who may delete for good. Real controller,
 * real models, rolled back. The page routes file is never rewritten, and restoring a whole backup is not driven
 * from here (it opens its own transaction and wipes every category).
 */
class ProductCategoriesControllerTest extends ShopDatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private string $runTag;
    private int $userId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $_POST = [];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->runTag = 'zq' . \strtolower(\substr(\uniqid(), -6));
        $this->userId = 1;

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog', 'page', 'user']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'productCategory', RollbackSafeProductCategory::class, 'productcategories'); // The shop service's model

        $this->auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new Password(),
        ) extends AdminAuth {
            public function getCurrentUserId(): ?int
            {
                return $_SESSION['admin_user_id'] ?? null;
            }
        };
        $this->model->changelog->setGetUser(fn () => $this->auth->getCurrentUser());

        $pages = new class (new Page($this->db, 'pages')) extends AdminPages {
            public function regenerateRoutesFile(): void {}
        };

        $this->admin = new class ($this->model, $this->auth, $pages) extends OsmiumAdmin {
            public ?array $response = null;
            public array $input = [];

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth, AdminPages $pages)
            {
                $this->model = $model;
                $this->auth = $auth;
                $this->pages = $pages;
            }

            public function getJsonInput(): array
            {
                return $this->input;
            }

            public function jsonSuccess(array $result, int $code = 200): void
            {
                $this->response = ['ok' => true, 'body' => $result];
                throw new ResponseSentException();
            }

            public function jsonError(string $message, int $code = 200, array $extra = []): void
            {
                $this->response = ['ok' => false, 'error' => $message];
                throw new ResponseSentException();
            }
        };
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_SERVER = $this->savedServer;
        \http_response_code(200);
        (new \ReflectionProperty(\Osmium\Core\Models\Model::class, 'pagesById'))->setValue(null, null);
        parent::tearDown();
    }

    private function signInAs(string $role, int $userId = 1): array
    {
        $this->userId = $userId;
        $_SESSION['admin_user_id'] = $userId;
        $_SESSION['admin_user_role'] = $role;
        $_SESSION['admin_user_username'] = "{$role}-tester";

        return ['admin' => ['basePath' => '/admin/', 'avatarPath' => '/a/']];
    }

    private function controller(array &$data): ProductCategoriesController
    {
        $osmium = new class ($this->db) extends Osmium {
            public function __construct(OsmiumPDO $db)
            {
                $this->dataSource = $db;
                $this->strings = new Strings();
                $this->config = new \Osmium\Core\OsmiumConfig((object) ['site' => (object) ['scheme' => 'http', 'subdomain' => '', 'domain' => 'x.test', 'hostName' => 'x.test', 'trustProxyHeaders' => false, 'imagesPath' => '/img']]);
            }
        };

        return new ProductCategoriesController($osmium, $this->admin, $data);
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function act(array $input, string $role = 'admin', int $userId = 1, bool $validToken = true): array
    {
        $data = $this->signInAs($role, $userId);
        $this->admin->input = $input + ['csrf_token' => $validToken ? 'good-token' : 'forged'];

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent')); // The controller sets a JSON header; the CLI has already printed progress dots
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    /** The controller finds a parent's page address in the generated routes list, which the test database does not feed. */
    private function knowPages(int ...$categoryIds): void
    {
        $property = new \ReflectionProperty(\Osmium\Core\Models\Model::class, 'pagesById');
        $known = $property->getValue() ?? [];
        foreach ($categoryIds as $id) {
            $page = $this->pageRow((int) $this->row($id)['page_id']);
            $known[(int) $page['id']] = $page;
        }
        $property->setValue(null, $known);
    }

    private function brandId(): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "{$this->runTag} brand"]);

        return (int) $this->db->lastInsertId();
    }

    private function addCategory(string $label, ?int $parentId = null, array $extra = []): int
    {
        $response = $this->act(['action' => 'add', 'label' => "{$this->runTag} {$label}", 'parent_id' => $parentId] + $extra);
        $this->assertTrue($response['ok'], $response['error'] ?? '');

        return (int) $response['body']['id'];
    }

    private function row(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'productcategories WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function pageRow(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'pages WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function changelogFor(int $id): array
    {
        return \array_column($this->db->prepare(
            sql: "SELECT description FROM " . $this->db->tablePrefix() . "changelog WHERE record_type = 'category' AND record_id = :id ORDER BY id",
            bindings: [':id' => $id],
        )->resultset(), 'description');
    }

    private function putProductIn(int $productId, int $categoryId, int $sort = 0): void
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'products_productcategories', data: [
            'product_id' => $productId,
            'category_id' => $categoryId,
            'sort' => $sort,
        ]);
    }

    private function productSort(int $productId, int $categoryId): int
    {
        return (int) $this->db->prepare(
            sql: 'SELECT sort FROM ' . $this->db->tablePrefix() . 'products_productcategories WHERE product_id = :p AND category_id = :c',
            bindings: [':p' => $productId, ':c' => $categoryId],
        )->single()['sort'];
    }

    private function lockHeldBy(int $userId, string $name = 'Someone Else'): void
    {
        $this->model->productCategory->insertLock($userId, $name);
    }

    // ---- request checks -----------------------------------------------------------

    public function test_a_wrong_token_changes_nothing_but_the_read_only_lock_actions_need_none(): void
    {
        $response = $this->act(['action' => 'add', 'label' => "{$this->runTag} Forged"], validToken: false);
        $this->assertStringContainsString('Invalid request token', $response['error']);

        $this->assertTrue($this->act(['action' => 'get_categories'], validToken: false)['ok']);
        $this->assertTrue($this->act(['action' => 'check_lock'], validToken: false)['ok']);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->assertSame('Invalid action', $this->act(['action' => 'explode'])['error']);
        $this->assertSame(400, \http_response_code());
    }

    // ---- add ----------------------------------------------------------------------

    public function test_a_category_gets_its_own_page_named_from_its_label(): void
    {
        $id = $this->addCategory('Solar Panels', extra: ['title' => 'Panels', 'subtitle' => 'All the panels']);

        $row = $this->row($id);
        $this->assertSame("{$this->runTag} Solar Panels", $row['label']);
        $this->assertNull($row['parent_id']);

        $page = $this->pageRow((int) $row['page_id']);
        $this->assertSame("{$this->runTag}-solar-panels", $page['url']);
        $this->assertSame('Panels', $page['title']);
        $this->assertSame('All the panels', $page['description']);
        $this->assertSame('noindex,follow', $page['robots']);
        $this->assertSame(["Created category: {$this->runTag} Solar Panels"], $this->changelogFor($id));
    }

    public function test_a_page_title_falls_back_to_the_label(): void
    {
        $id = $this->addCategory('Untitled');

        $this->assertSame("{$this->runTag} Untitled", $this->pageRow((int) $this->row($id)['page_id'])['title']);
    }

    public function test_a_sub_category_lives_under_its_parents_page_address(): void
    {
        $parent = $this->addCategory('Batteries');
        $this->knowPages($parent);
        $child = $this->addCategory('Lithium', $parent);

        $this->assertSame($parent, (int) $this->row($child)['parent_id']);
        $parentPage = $this->pageRow((int) $this->row($parent)['page_id']);
        $childPage = $this->pageRow((int) $this->row($child)['page_id']);
        $this->assertSame("{$parentPage['url']}/{$this->runTag}-lithium", $childPage['url']);
        $this->assertSame((int) $parentPage['id'], (int) $childPage['parent_page_id']);
    }

    public function test_each_new_category_goes_to_the_end_of_its_level(): void
    {
        $first = $this->addCategory('First');
        $second = $this->addCategory('Second');

        $this->assertGreaterThan((int) $this->row($first)['sort'], (int) $this->row($second)['sort']);
    }

    public function test_a_category_needs_a_label(): void
    {
        $this->assertSame('Label is required', $this->act(['action' => 'add', 'label' => '  '])['error']);
    }

    public function test_two_categories_cannot_share_an_address(): void
    {
        $this->addCategory('Twin');

        $again = $this->act(['action' => 'add', 'label' => "{$this->runTag} Twin"]);

        $this->assertSame('A page with this URL already exists', $again['error']);
    }

    // ---- update -------------------------------------------------------------------

    public function test_editing_a_category_updates_it_and_its_page_address(): void
    {
        $id = $this->addCategory('Old Name');

        $this->assertTrue($this->act(['action' => 'update', 'id' => $id, 'label' => "{$this->runTag} New Name", 'title' => 'T', 'description' => 'D'])['ok']);

        $row = $this->row($id);
        $this->assertSame("{$this->runTag} New Name", $row['label']);
        $this->assertSame('T', $row['title']);
        $this->assertSame("{$this->runTag}-new-name", $this->pageRow((int) $row['page_id'])['url']);
        $this->assertContains("Updated category: {$this->runTag} New Name", $this->changelogFor($id));
    }

    public function test_editing_a_category_with_no_page_still_saves_it(): void
    {
        $id = $this->addCategory('No Page');
        $this->db->prepare(
            sql: 'DELETE FROM ' . $this->db->tablePrefix() . 'productcategories_pages WHERE productcategory_id = :id',
            bindings: [':id' => $id],
        )->execute();

        $this->assertTrue($this->act(['action' => 'update', 'id' => $id, 'label' => "{$this->runTag} Renamed"])['ok']);

        $this->assertSame("{$this->runTag} Renamed", $this->row($id)['label']);
        $this->assertNotNull($this->row($id)['updated_at']);
    }

    public function test_a_category_can_be_moved_under_another(): void
    {
        $a = $this->addCategory('A');
        $b = $this->addCategory('B');
        $this->knowPages($a);

        $this->act(['action' => 'update', 'id' => $b, 'label' => "{$this->runTag} B", 'parent_id' => $a]);

        $this->assertSame($a, (int) $this->row($b)['parent_id']);
        $this->assertStringStartsWith("{$this->runTag}-a/", $this->pageRow((int) $this->row($b)['page_id'])['url']);
    }

    public function test_a_category_cannot_be_its_own_parent_or_sit_under_its_own_descendant(): void
    {
        $parent = $this->addCategory('Parent');
        $child = $this->addCategory('Child', $parent);

        $self = $this->act(['action' => 'update', 'id' => $parent, 'label' => "{$this->runTag} Parent", 'parent_id' => $parent]);
        $this->assertSame('Category cannot be its own parent', $self['error']);

        $loop = $this->act(['action' => 'update', 'id' => $parent, 'label' => "{$this->runTag} Parent", 'parent_id' => $child]);
        $this->assertSame('Cannot set parent to a descendant category', $loop['error']);
        $this->assertNull($this->row($parent)['parent_id']);
    }

    public function test_an_update_needs_a_real_category_and_a_label(): void
    {
        $id = $this->addCategory('Real');

        $this->assertSame('Category ID is required', $this->act(['action' => 'update', 'label' => 'X'])['error']);
        $this->assertSame('Label is required', $this->act(['action' => 'update', 'id' => $id, 'label' => ''])['error']);
        $this->assertSame('Category not found', $this->act(['action' => 'update', 'id' => 999999999, 'label' => 'X'])['error']);
    }

    // ---- delete, restore, delete for good -----------------------------------------

    public function test_an_empty_category_is_binned_and_restored(): void
    {
        $id = $this->addCategory('Empty');

        $this->assertTrue($this->act(['action' => 'delete', 'id' => $id], role: 'editor')['ok']);
        $this->assertNotNull($this->row($id)['deleted_at']);

        $this->assertTrue($this->act(['action' => 'restore', 'id' => $id], role: 'editor')['ok']);
        $this->assertNull($this->row($id)['deleted_at']);
        $this->assertSame(
            ["Created category: {$this->runTag} Empty", "Deleted category #{$id}", "Restored category #{$id}"],
            $this->changelogFor($id),
        );
    }

    public function test_a_category_with_sub_categories_cannot_be_deleted(): void
    {
        $parent = $this->addCategory('Busy');
        $this->addCategory('Sub One', $parent);
        $this->addCategory('Sub Two', $parent);

        $this->assertSame('Cannot delete: 2 subcategories in this category', $this->act(['action' => 'delete', 'id' => $parent])['error']);
        $this->assertNull($this->row($parent)['deleted_at']);
    }

    public function test_a_category_with_one_sub_category_says_so_in_the_singular(): void
    {
        $parent = $this->addCategory('Singular');
        $this->addCategory('Only', $parent);

        $this->assertSame('Cannot delete: 1 subcategory in this category', $this->act(['action' => 'delete', 'id' => $parent])['error']);
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $id = $this->addCategory('Stocked');
        $this->putProductIn($this->createTestProduct(), $id);

        $this->assertSame('Cannot delete: 1 product(s) assigned to this category', $this->act(['action' => 'delete', 'id' => $id])['error']);
        $this->assertNull($this->row($id)['deleted_at']);
    }

    public function test_only_an_admin_can_delete_a_category_for_good_and_its_product_links_go_with_it(): void
    {
        $id = $this->addCategory('Doomed');
        $product = $this->createTestProduct();
        $this->putProductIn($product, $id);

        $refused = $this->act(['action' => 'permanent_delete', 'id' => $id], role: 'editor');
        $this->assertSame('Only administrators can permanently delete categories', $refused['error']);
        $this->assertNotSame([], $this->row($id));

        $this->assertTrue($this->act(['action' => 'permanent_delete', 'id' => $id], role: 'admin')['ok']);
        $this->assertSame([], $this->row($id));
        $links = $this->db->prepare(
            sql: 'SELECT COUNT(*) AS n FROM ' . $this->db->tablePrefix() . 'products_productcategories WHERE category_id = :c',
            bindings: [':c' => $id],
        )->single();
        $this->assertSame(0, (int) $links['n']);
    }

    public function test_delete_and_restore_need_an_id(): void
    {
        $this->assertSame('Category ID is required', $this->act(['action' => 'delete'])['error']);
        $this->assertSame('Category ID is required', $this->act(['action' => 'restore'])['error']);
        $this->assertSame('Category ID is required', $this->act(['action' => 'permanent_delete'])['error']);
    }

    // ---- reorder ------------------------------------------------------------------

    public function test_reordering_saves_each_categorys_place_and_parent(): void
    {
        $a = $this->addCategory('A');
        $b = $this->addCategory('B');

        $this->assertTrue($this->act(['action' => 'reorder', 'items' => [
            ['id' => $b, 'sort' => 1, 'parent_id' => null],
            ['id' => $a, 'sort' => 2, 'parent_id' => $b],
        ]])['ok']);

        $this->assertSame(1, (int) $this->row($b)['sort']);
        $this->assertSame(2, (int) $this->row($a)['sort']);
        $this->assertSame($b, (int) $this->row($a)['parent_id']);
        $this->assertNull($this->row($b)['parent_id']);
    }

    public function test_reordering_nothing_is_refused(): void
    {
        $this->assertSame('No items to reorder', $this->act(['action' => 'reorder', 'items' => []])['error']);
    }

    // ---- products inside a category -----------------------------------------------

    public function test_the_products_in_a_category_come_back_in_their_set_order_with_titles(): void
    {
        $id = $this->addCategory('Ordered');
        $brand = $this->brandId();
        $first = $this->createTestProduct(['brand_id' => $brand, 'status_id' => $this->statusId(true)]);
        $second = $this->createTestProduct(['brand_id' => $brand, 'status_id' => $this->statusId(true)]);
        $this->putProductIn($first, $id, 2);
        $this->putProductIn($second, $id, 1);

        $response = $this->act(['action' => 'get_category_products', 'category_id' => $id]);

        $this->assertTrue($response['ok']);
        $this->assertSame([$second, $first], \array_map('intval', \array_column($response['body']['products'], 'id')));
        $this->assertStringStartsWith('/img/shop/', $response['body']['products'][0]['thumbnail']);
        $this->assertNotSame('', \trim($response['body']['products'][0]['title']));
    }

    public function test_the_product_list_needs_a_real_category_id(): void
    {
        $this->assertSame('Invalid category ID', $this->act(['action' => 'get_category_products', 'category_id' => 0])['error']);
    }

    public function test_products_are_re_ordered_inside_their_category(): void
    {
        $id = $this->addCategory('Sortable');
        $first = $this->createTestProduct();
        $second = $this->createTestProduct();
        $this->putProductIn($first, $id, 1);
        $this->putProductIn($second, $id, 2);

        $this->assertTrue($this->act(['action' => 'reorder_products', 'category_id' => $id, 'items' => [
            ['product_id' => $second, 'sort' => 1],
            ['product_id' => $first, 'sort' => 2],
        ]])['ok']);

        $this->assertSame(1, $this->productSort($second, $id));
        $this->assertSame(2, $this->productSort($first, $id));
        $this->assertContains("Reordered products in category #{$id}", $this->changelogFor($id));
    }

    public function test_re_ordering_products_needs_a_category_and_some_items(): void
    {
        $id = $this->addCategory('Needs');

        $this->assertSame('Invalid category ID', $this->act(['action' => 'reorder_products', 'items' => [['product_id' => 1, 'sort' => 1]]])['error']);
        $this->assertSame('No items to reorder', $this->act(['action' => 'reorder_products', 'category_id' => $id, 'items' => []])['error']);
    }

    // ---- the edit lock ------------------------------------------------------------

    public function test_taking_the_lock_makes_a_backup_and_a_second_editor_is_told_who_has_it(): void
    {
        $taken = $this->act(['action' => 'acquire_lock'], userId: 1);
        $this->assertTrue($taken['ok']);
        $this->assertFalse($taken['body']['locked']);
        $this->assertTrue($taken['body']['backup_created']);

        $other = $this->act(['action' => 'acquire_lock'], userId: 2);
        $this->assertTrue($other['body']['locked']);
        $this->assertNotSame('', $other['body']['user_name']);

        $check = $this->act(['action' => 'check_lock'], userId: 2);
        $this->assertTrue($check['body']['locked']);
        $this->assertFalse($this->act(['action' => 'check_lock'], userId: 1)['body']['locked']);
    }

    public function test_an_editor_blocked_by_someone_elses_lock_cannot_change_anything(): void
    {
        $id = $this->addCategory('Guarded');
        $this->act(['action' => 'release_lock'], userId: 1);
        $this->lockHeldBy(2, 'Alex Other');

        foreach (['add' => ['label' => 'Nope'], 'update' => ['id' => $id, 'label' => 'Nope'], 'delete' => ['id' => $id], 'reorder' => ['items' => [['id' => $id, 'sort' => 9, 'parent_id' => null]]]] as $action => $fields) {
            $response = $this->act(['action' => $action] + $fields, userId: 1);
            $this->assertSame('Categories are being edited by Alex Other. Please try again later.', $response['error'], $action);
        }
        $this->assertSame("{$this->runTag} Guarded", $this->row($id)['label']);
    }

    public function test_releasing_the_lock_lets_the_next_editor_in(): void
    {
        $this->act(['action' => 'acquire_lock'], userId: 1);
        $this->act(['action' => 'release_lock'], userId: 1);

        $other = $this->act(['action' => 'acquire_lock'], userId: 2);

        $this->assertFalse($other['body']['locked']);
    }

    public function test_a_lock_left_for_over_half_an_hour_no_longer_blocks_anyone(): void
    {
        $this->lockHeldBy(2, 'Gone Home');
        $this->db->prepare('UPDATE ' . $this->db->tablePrefix() . 'productcategories_lock SET created_at = DATE_SUB(NOW(), INTERVAL 31 MINUTE)')->execute();

        $this->assertTrue($this->act(['action' => 'add', 'label' => "{$this->runTag} After", 'parent_id' => null], userId: 1)['ok']);
    }

    public function test_restoring_a_backup_needs_one_to_exist(): void
    {
        $this->assertSame('No backup found', $this->act(['action' => 'restore_backup'])['error']);
    }

    // ---- the page -----------------------------------------------------------------

    public function test_the_index_builds_the_category_tree_the_bin_and_the_lock_state(): void
    {
        $parent = $this->addCategory('Top');
        $child = $this->addCategory('Below', $parent);
        $binned = $this->addCategory('Binned');
        $this->act(['action' => 'delete', 'id' => $binned]);
        $data = $this->signInAs('admin');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller($data)->index();

        $tree = $data['admin']['categoryTree'];
        $top = \array_values(\array_filter($tree, fn (array $c) => (int) $c['id'] === $parent));
        $this->assertCount(1, $top);
        $this->assertSame([$child], \array_map(fn (array $c) => (int) $c['id'], $top[0]['children']));
        $this->assertContains($child, \array_map('intval', \array_column($data['admin']['allCategories'], 'id')));
        $this->assertContains($binned, \array_map('intval', \array_column($data['admin']['deletedCategories'], 'id')));
        $this->assertNotContains($binned, \array_map('intval', \array_column($data['admin']['allCategories'], 'id')));
        $this->assertFalse((bool) $data['admin']['lockedByOther']);
    }

    public function test_the_index_says_when_another_editor_holds_the_lock(): void
    {
        $this->act(['action' => 'release_lock'], userId: 1);
        $this->lockHeldBy(2, 'Alex Other');
        $data = $this->signInAs('admin', userId: 1);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller($data)->index();

        $this->assertTrue((bool) $data['admin']['lockedByOther']);
        $this->assertSame('Alex Other', $data['admin']['lockInfo']['user_name']);
        $this->assertSame('AO', $data['admin']['lockInfo']['initials']);
    }
}
