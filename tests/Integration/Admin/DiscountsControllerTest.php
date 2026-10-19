<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Header;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Library\Url;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Controllers\DiscountsController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\AdminPresence;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/core/admin/models/ListRows.php';
require_once 'app/services/osmium-shop/models/PaymentProviders.php';
require_once 'app/services/osmium-shop/models/Discount.php';
require_once 'app/services/osmium-shop/models/DiscountListView.php';
require_once 'app/services/osmium-shop/models/DiscountRules.php';
require_once 'app/services/osmium-shop/controllers/DiscountsController.php';

/**
 * The discount screen: the form that creates and edits discounts and coupon codes (what is stored, what is
 * refused, what a type change clears) and the action endpoint (bin, restore, permanent delete and who may do
 * them). Real controller, real models, rolled back.
 */
class DiscountsControllerTest extends ShopDatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private AdminAuth $auth;
    private array $savedServer;
    private string $runTag;
    private int $stashedId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $_POST = [];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->runTag = 'ZQ' . \strtoupper(\substr(\uniqid(), -6));

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog', 'listRows']));
        $this->model = new OsmiumAdminModel($this->db, $models);
        $this->model->addModel($this->db, 'product', \Osmium\Modules\Admin\Models\Product::class, 'products'); // The shop service's model
        $this->model->addModel($this->db, 'discount', \Osmium\Modules\Admin\Models\Discount::class, 'discounts'); // The shop service's model

        $this->auth = new class (
            $this->model,
            new RecordingAdminMailer(),
            new Password(),
        ) extends AdminAuth {
            public array $input = [];

            public function getJsonInput(): array
            {
                return $this->input;
            }
        };
        $this->model->changelog->setGetUser(fn () => $this->auth->getCurrentUser());

        $this->admin = new class ($this->model, $this->auth) extends OsmiumAdmin {
            public ?array $response = null;

            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
                $this->presence = new class extends AdminPresence {
                    public function __construct() {}

                    public function handleAction(string $action, array $input, string $recordType): bool
                    {
                        return false;
                    }

                    public function registerCurrentUser(string $recordType, int $recordId): void {}

                    public function list(int $currentUserId, string $recordType): array
                    {
                        return [];
                    }
                };
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
        parent::tearDown();
    }

    private function controller(array &$data): DiscountsController
    {
        $osmium = new class ($this->db, $this->stashedId) extends Osmium {
            public function __construct(OsmiumPDO $db, private int $stashedId)
            {
                $this->dataSource = $db;
                $this->url = new class extends Url {
                    public function __construct() {}

                    public function to(string $path): string
                    {
                        return $path;
                    }
                };
                $this->header = new class extends Header {
                    public function __construct() {}

                    public function redirect(string $targetURL, int $statusCode = 302): void
                    {
                        throw new \RuntimeException($targetURL);
                    }
                };
            }

            public function getStashedParam(string $key, mixed $default = null): mixed
            {
                return $key === 'id' && $this->stashedId ? $this->stashedId : $default;
            }

            public function clearStashedQuerystring(): void {}
        };

        return new DiscountsController($osmium, $this->admin, $data);
    }

    private function signInAs(string $role): array
    {
        $_SESSION['admin_user_id'] = 1;
        $_SESSION['admin_user_role'] = $role;
        $_SESSION['admin_user_username'] = "{$role}-tester";

        return ['admin' => ['basePath' => '/admin/', 'user' => ['role' => $role]]];
    }

    /**
     * Posts the edit form.
     *
     * @return array{redirect: ?string, data: array}
     */
    private function submit(array $fields, bool $validToken = true, string $role = 'admin'): array
    {
        $data = $this->signInAs($role);
        $_POST = $fields + ['token' => $validToken ? 'good-token' : 'forged'];
        $redirect = null;

        try {
            $this->controller($data)->edit();
        } catch (\RuntimeException $e) {
            $redirect = $e->getMessage();
        }

        return ['redirect' => $redirect, 'data' => $data];
    }

    /** @return array{ok: bool, body?: array, error?: string} */
    private function act(array $input, string $role = 'admin', bool $validToken = true): array
    {
        $data = $this->signInAs($role);
        $this->auth->input = $input + ['csrf_token' => $validToken ? 'good-token' : 'forged'];

        \set_error_handler(fn (int $no, string $str) => \str_contains($str, 'headers already sent')); // The controller sets a JSON header; the CLI has already printed progress dots
        try {
            $this->controller($data)->action();
        } catch (ResponseSentException) {
        } finally {
            \restore_error_handler();
        }

        return $this->admin->response;
    }

    private function sitewide(array $overrides = []): array
    {
        return $overrides + [
            'name' => "{$this->runTag} sale",
            'type' => 'sitewide',
            'value_type' => 'percent',
            'value' => '10',
            'active' => '1',
        ];
    }

    private function coupon(string $suffix, array $overrides = []): array
    {
        return $overrides + [
            'name' => "{$this->runTag} coupon {$suffix}",
            'type' => 'coupon',
            'code' => \strtolower("{$this->runTag}{$suffix}"),
            'value_type' => 'fixed',
            'value' => '5',
            'active' => '1',
        ];
    }

    private function stored(int $id): array
    {
        return $this->db->prepare(
            sql: 'SELECT * FROM ' . $this->db->tablePrefix() . 'discounts WHERE id = :id',
            bindings: [':id' => $id],
        )->single() ?: [];
    }

    private function discountCount(): int
    {
        return (int) $this->db->prepare('SELECT COUNT(*) AS total FROM ' . $this->db->tablePrefix() . 'discounts')->single()['total'];
    }

    private function createdId(array $result): int
    {
        $this->assertMatchesRegularExpression('#^/admin/shop/discounts/edit/\?id=\d+$#', (string) $result['redirect']);

        return (int) \substr((string) $result['redirect'], \strrpos((string) $result['redirect'], '=') + 1);
    }

    private function changelogFor(int $id): array
    {
        $table = $this->db->tablePrefix() . 'changelog';

        return \array_column($this->db->prepare(
            sql: "SELECT description FROM {$table} WHERE record_type = 'discount' AND record_id = :id ORDER BY id",
            bindings: [':id' => $id],
        )->resultset(), 'description');
    }

    private function scopedProducts(int $id): array
    {
        return $this->model->discount->getProductIds($id);
    }

    // ---- the form: creating -------------------------------------------------------

    public function test_a_forged_token_creates_nothing(): void
    {
        $before = $this->discountCount();

        $result = $this->submit($this->sitewide(), validToken: false);

        $this->assertNull($result['redirect']);
        $this->assertSame('Invalid form submission. Please try again.', $result['data']['admin']['error']);
        $this->assertSame($before, $this->discountCount());
    }

    public function test_a_sitewide_discount_is_stored_and_the_user_is_sent_to_its_edit_page(): void
    {
        $id = $this->createdId($this->submit($this->sitewide(['value' => '12.5'])));

        $row = $this->stored($id);
        $this->assertSame("{$this->runTag} sale", $row['name']);
        $this->assertSame('sitewide', $row['type']);
        $this->assertSame('percent', $row['value_type']);
        $this->assertEqualsWithDelta(12.5, (float) $row['value'], 0.001);
        $this->assertNull($row['code']);
        $this->assertSame(1, (int) $row['active']);
        $this->assertTrue($_SESSION['discount_saved'], 'the edit page shows its saved message once');
        $this->assertSame(["Created discount: {$this->runTag} sale"], $this->changelogFor($id));
    }

    public function test_a_discount_left_unticked_is_saved_switched_off(): void
    {
        $fields = $this->sitewide();
        unset($fields['active']);

        $id = $this->createdId($this->submit($fields));

        $this->assertSame(0, (int) $this->stored($id)['active']);
    }

    public function test_a_coupon_code_is_stored_in_capitals_with_its_minimum_spend_and_limits(): void
    {
        $id = $this->createdId($this->submit($this->coupon('A', [
            'min_spend_inc_tax' => '40',
            'usage_limit' => '100',
            'usage_limit_per_customer' => '2',
            'starts_at' => '2030-01-01T09:00',
            'ends_at' => '2030-02-01T09:00',
        ])));

        $row = $this->stored($id);
        $this->assertSame("{$this->runTag}A", $row['code']);
        $this->assertEqualsWithDelta(40.0, (float) $row['min_spend_inc_tax'], 0.001);
        $this->assertSame(100, (int) $row['usage_limit']);
        $this->assertSame(2, (int) $row['usage_limit_per_customer']);
        $this->assertStringStartsWith('2030-01-01 09:00', $row['starts_at']);
        $this->assertStringStartsWith('2030-02-01 09:00', $row['ends_at']);
    }

    public function test_a_free_delivery_coupon_takes_no_amount_off_whatever_value_was_typed(): void
    {
        $id = $this->createdId($this->submit($this->coupon('B', ['value_type' => 'free_delivery', 'value' => '99'])));

        $this->assertEqualsWithDelta(0.0, (float) $this->stored($id)['value'], 0.001);
    }

    public function test_a_minimum_spend_is_only_kept_for_a_coupon(): void
    {
        $id = $this->createdId($this->submit($this->sitewide(['min_spend_inc_tax' => '40'])));

        $this->assertNull($this->stored($id)['min_spend_inc_tax']);
    }

    public function test_a_per_product_discount_keeps_only_its_chosen_products(): void
    {
        $first = $this->createTestProduct();
        $second = $this->createTestProduct();

        $id = $this->createdId($this->submit($this->sitewide(['type' => 'product', 'product_ids' => [(string) $first, (string) $second]])));

        $this->assertEqualsCanonicalizing([$first, $second], $this->scopedProducts($id));
    }

    public function test_products_ticked_on_a_sitewide_discount_are_not_kept(): void
    {
        $product = $this->createTestProduct();

        $id = $this->createdId($this->submit($this->sitewide(['product_ids' => [(string) $product]])));

        $this->assertSame([], $this->scopedProducts($id));
    }

    // ---- the form: refusals -------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedForms(): array
    {
        return [
            'no name' => [['name' => ''], 'Discount name is required'],
            'unknown type' => [['type' => 'bogus'], 'Invalid discount type'],
            'unknown value type' => [['value_type' => 'bogus'], 'Invalid discount value type'],
            'free delivery outside a coupon' => [['value_type' => 'free_delivery'], 'Free delivery is only available as a coupon code'],
            'no amount' => [['value' => '0'], 'Discount value must be greater than zero'],
            'over a hundred percent' => [['value' => '101'], 'Percentage discount cannot exceed 100%'],
            'ends before it starts' => [['starts_at' => '2030-02-01T09:00', 'ends_at' => '2030-01-01T09:00'], 'End date must be after the start date'],
            'per-product with no products' => [['type' => 'product'], 'Select at least one product for a per-product discount'],
            'coupon with no code' => [['type' => 'coupon', 'code' => ''], 'A coupon code is required'],
            'coupon code with a space' => [['type' => 'coupon', 'code' => 'TWO WORDS'], 'Coupon codes may only contain letters, numbers, hyphens and underscores'],
            'absurd minimum spend' => [['type' => 'coupon', 'code' => 'BIGSPEND', 'min_spend_inc_tax' => '2000000'], 'Minimum spend must be under'],
        ];
    }

    /**
     * @dataProvider refusedForms
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedForms')]
    public function test_a_bad_form_is_refused_with_its_reason_and_stores_nothing(array $changes, string $reason): void
    {
        $before = $this->discountCount();

        $result = $this->submit($changes + $this->sitewide());

        $this->assertNull($result['redirect']);
        $this->assertStringContainsString($reason, $result['data']['admin']['error']);
        $this->assertSame($before, $this->discountCount());
    }

    public function test_a_coupon_code_already_in_use_is_refused(): void
    {
        $this->createdId($this->submit($this->coupon('C')));
        $before = $this->discountCount();

        $result = $this->submit($this->coupon('C', ['name' => "{$this->runTag} copy"]));

        $this->assertSame('That coupon code is already in use', $result['data']['admin']['error']);
        $this->assertSame($before, $this->discountCount());
    }

    // ---- the form: editing --------------------------------------------------------

    public function test_editing_a_discount_updates_it_and_logs_the_change(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));

        $result = $this->submit($this->sitewide(['id' => (string) $id, 'name' => "{$this->runTag} renamed", 'value' => '20']));

        $this->assertSame("/admin/shop/discounts/edit/?id={$id}", $result['redirect']);
        $this->assertSame("{$this->runTag} renamed", $this->stored($id)['name']);
        $this->assertEqualsWithDelta(20.0, (float) $this->stored($id)['value'], 0.001);
        $this->assertContains("Updated discount: {$this->runTag} renamed", $this->changelogFor($id));
    }

    public function test_a_coupon_can_be_saved_again_with_its_own_code(): void
    {
        $id = $this->createdId($this->submit($this->coupon('D')));

        $result = $this->submit($this->coupon('D', ['id' => (string) $id, 'value' => '7']));

        $this->assertNull($result['data']['admin']['error'] ?? null, 'its own code is not "already in use"');
        $this->assertEqualsWithDelta(7.0, (float) $this->stored($id)['value'], 0.001);
    }

    public function test_a_coupon_cannot_take_the_code_of_another_discount(): void
    {
        $this->createdId($this->submit($this->coupon('E')));
        $id = $this->createdId($this->submit($this->coupon('F')));

        $result = $this->submit($this->coupon('E', ['id' => (string) $id]));

        $this->assertSame('That coupon code is already in use', $result['data']['admin']['error']);
        $this->assertSame("{$this->runTag}F", $this->stored($id)['code']);
    }

    public function test_changing_a_coupon_to_sitewide_clears_its_code(): void
    {
        $id = $this->createdId($this->submit($this->coupon('G')));

        $this->submit($this->sitewide(['id' => (string) $id, 'code' => "{$this->runTag}G"])); // The form still posts the old code

        $this->assertSame('sitewide', $this->stored($id)['type']);
        $this->assertNull($this->stored($id)['code']);
    }

    public function test_changing_a_per_product_discount_to_sitewide_drops_its_products(): void
    {
        $product = $this->createTestProduct();
        $id = $this->createdId($this->submit($this->sitewide(['type' => 'product', 'product_ids' => [(string) $product]])));
        $this->assertSame([$product], $this->scopedProducts($id));

        $this->submit($this->sitewide(['id' => (string) $id]));

        $this->assertSame([], $this->scopedProducts($id));
    }

    public function test_a_refused_edit_leaves_the_stored_discount_alone(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));

        $this->submit($this->sitewide(['id' => (string) $id, 'name' => 'Changed', 'value' => '500']));

        $this->assertSame("{$this->runTag} sale", $this->stored($id)['name']);
    }

    // ---- the form: showing it -----------------------------------------------------

    public function test_a_new_discount_form_starts_empty_and_sitewide(): void
    {
        $data = $this->signInAs('admin');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller($data)->edit();

        $this->assertTrue($data['admin']['isNew']);
        $this->assertSame('sitewide', $data['admin']['discount']['type']);
        $this->assertSame('percent', $data['admin']['discount']['value_type']);
        $this->assertSame([], $data['admin']['selectedProductIds']);
        $this->assertFalse($data['admin']['discountForm']['isCouponType']);
    }

    public function test_an_existing_discount_form_is_filled_in_with_dates_in_the_browsers_format(): void
    {
        $product = $this->createTestProduct();
        $id = $this->createdId($this->submit($this->sitewide([
            'type' => 'product',
            'product_ids' => [(string) $product],
            'starts_at' => '2030-01-01T09:30',
        ])));
        $this->stashedId = $id;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        $this->controller($data)->edit();

        $this->assertFalse($data['admin']['isNew']);
        $this->assertSame($id, $data['admin']['discountId']);
        $this->assertTrue($data['admin']['discountForm']['isProductType']);
        $this->assertSame([$product], $data['admin']['selectedProductIds']);
        $this->assertSame('2030-01-01T09:30', $data['admin']['discountForm']['startsAtLocal']);
        $this->assertSame('', $data['admin']['discountForm']['endsAtLocal']);
    }

    public function test_the_saved_message_shows_once(): void
    {
        $_SESSION['discount_saved'] = true;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $data = $this->signInAs('admin');
        $this->controller($data)->edit();
        $this->assertTrue($data['admin']['showSavedMessage']);

        $data = $this->signInAs('admin');
        $this->controller($data)->edit();
        $this->assertFalse($data['admin']['showSavedMessage']);
    }

    public function test_asking_for_a_discount_that_does_not_exist_goes_back_to_the_list(): void
    {
        $this->stashedId = 999999999;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        try {
            $this->controller($data)->edit();
            $this->fail('expected a redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('/admin/shop/discounts/', $e->getMessage());
        }
    }

    // ---- the action endpoint ------------------------------------------------------

    public function test_only_a_post_is_accepted_by_the_action_endpoint(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertSame('Method not allowed', $this->act(['action' => 'delete', 'id' => 1])['error']);
    }

    public function test_a_wrong_token_changes_nothing_on_the_action_endpoint(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));

        $response = $this->act(['action' => 'delete', 'id' => $id], validToken: false);

        $this->assertStringContainsString('Invalid request token', $response['error']);
        $this->assertNull($this->stored($id)['deleted_at']);
    }

    public function test_a_request_without_an_action_or_a_discount_is_refused(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));

        $this->assertSame('Missing parameters', $this->act(['id' => $id])['error'], 'no action');
        $this->assertSame('Missing parameters', $this->act(['action' => 'delete'])['error'], 'no discount');
        $this->assertSame('Unknown action', $this->act(['action' => 'explode', 'id' => $id])['error']);
    }

    public function test_deleting_moves_a_discount_to_the_bin_and_restoring_brings_it_back(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));

        $deleted = $this->act(['action' => 'delete', 'id' => $id], role: 'editor');
        $this->assertSame(['success' => true, 'action' => 'delete', 'id' => $id], $deleted['body']);
        $this->assertNotNull($this->stored($id)['deleted_at']);

        $restored = $this->act(['action' => 'restore', 'id' => $id], role: 'editor');
        $this->assertTrue($restored['ok']);
        $this->assertNull($this->stored($id)['deleted_at']);

        $this->assertSame(
            ["Created discount: {$this->runTag} sale", "Deleted discount: {$this->runTag} sale", "Restored discount: {$this->runTag} sale"],
            $this->changelogFor($id),
        );
    }

    public function test_only_an_admin_can_delete_a_discount_for_good(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));

        $refused = $this->act(['action' => 'permanently_delete', 'id' => $id], role: 'editor');
        $this->assertSame('Permission denied', $refused['error']);
        $this->assertNotEmpty($this->stored($id), 'the editor changed nothing');

        $allowed = $this->act(['action' => 'permanently_delete', 'id' => $id], role: 'admin');
        $this->assertTrue($allowed['ok']);
        $this->assertSame([], $this->stored($id));
    }

    // ---- the list -----------------------------------------------------------------

    public function test_the_list_shows_the_discounts_and_who_may_empty_the_bin(): void
    {
        $id = $this->createdId($this->submit($this->sitewide()));
        $binned = $this->createdId($this->submit($this->sitewide(['name' => "{$this->runTag} binned"])));
        $this->act(['action' => 'delete', 'id' => $binned]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $data = $this->signInAs('admin');

        $this->controller($data)->index();

        $names = \array_column($data['admin']['discounts'], 'name');
        $this->assertContains("{$this->runTag} sale", $names);
        $this->assertNotContains("{$this->runTag} binned", $names, 'a binned discount is not in the live list');
        $this->assertContains("{$this->runTag} binned", \array_column($data['admin']['deletedDiscounts'], 'name'));
        $this->assertTrue($data['admin']['isAdmin']);
        $this->assertArrayHasKey('paymentProviderSupportsDiscounts', $data['admin']);
        $this->assertNotEmpty($this->stored($id));
    }
}
