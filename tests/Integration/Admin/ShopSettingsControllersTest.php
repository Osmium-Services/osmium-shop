<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Core\Library\CustomerNotifications;
use Osmium\Core\Library\FileSystem;
use Osmium\Core\Library\Header;
use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\Password;
use Osmium\Core\Library\Url;
use Osmium\Core\Osmium;
use Osmium\Core\OsmiumConfig;
use Osmium\Modules\Admin\Controllers\PaymentSettingsController;
use Osmium\Modules\Admin\Controllers\ShopSettingsController;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Modules\Admin\Services\OsmiumAdminModel;
use Tests\Helpers\RecordingAdminMailer;
use Tests\Integration\DatabaseTestCase;

require_once 'app/core/Osmium.php';
require_once 'app/core/admin/services/OsmiumAdminModel.php';
require_once 'app/core/admin/models/Changelog.php';
require_once 'app/services/osmium-shop/models/CustomerNotifications.php';
require_once 'app/services/osmium-shop/models/PaymentProviders.php';
require_once 'app/services/osmium-shop/models/PaymentSettingsView.php';
require_once 'app/services/osmium-shop/controllers/ShopSettingsController.php';
require_once 'app/services/osmium-shop/controllers/PaymentSettingsController.php';

class TestShopSettingsController extends ShopSettingsController
{
    public function __construct(Osmium $osmium, OsmiumAdmin $admin, array &$data, string $dir)
    {
        $this->shopConfigFilePath = "{$dir}/shop.json.php";
        $this->checkoutConfigFilePath = "{$dir}/checkout.json.php";
        parent::__construct($osmium, $admin, $data);
    }
}

class TestPaymentSettingsController extends PaymentSettingsController
{
    public function __construct(Osmium $osmium, OsmiumAdmin $admin, array &$data, string $dir)
    {
        $this->shopConfigFilePath = "{$dir}/shop.json.php";
        $this->checkoutConfigFilePath = "{$dir}/checkout.json.php";
        parent::__construct($osmium, $admin, $data);
    }
}

/**
 * The shop and payment settings pages: what each form writes to the shop and checkout config files, what it
 * leaves alone, and what a bad token or a bad value does. The controllers run for real against temp copies of
 * the config files (their paths are overridable), so the site's own config is never touched.
 */
class ShopSettingsControllersTest extends DatabaseTestCase
{
    private OsmiumAdminModel $model;
    private object $admin;
    private string $dir;
    private array $savedServer;

    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() !== PHP_SESSION_ACTIVE) \session_start();
        $_SESSION = ['token' => 'good-token'];
        $_POST = [];
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->dir = \sys_get_temp_dir() . '/osmium_shop_settings_test_' . \uniqid();
        \mkdir($this->dir);
        $this->writeConfig('shop.json.php', [
            'shop' => [
                'currency' => 'GBP', 'currencySign' => '£', 'taxRatePercent' => '20', 'taxName' => 'VAT', 'country' => 'GB', 'deliveryMessage' => 'Old delivery',
                'searchEnabled' => false, 'basketAbandonedHours' => 2,
                'categoryShowCounts' => false, 'categoryShowSummaryText' => true, 'categoryShowCallToAction' => true,
                'productShowSummary' => true, 'productShowPriceExcTax' => true, 'productShowDeliveryMessage' => true,
                'customerNotifications' => ['order_paid' => ['email' => true, 'sms' => false], 'order_dispatched' => ['email' => true, 'sms' => true]],
            ],
        ]);
        $this->writeConfig('checkout.json.php', ['checkout' => ['paymentProvider' => 'none']]);

        $all = (new FileSystem())->readJSONFile('app/core/admin/config/models/models.json.php');
        $models = (object) \array_intersect_key((array) $all, \array_flip(['changelog']));
        $this->model = new OsmiumAdminModel($this->db, $models);

        $auth = new class ($this->model, new RecordingAdminMailer(), new Password()) extends AdminAuth {};

        $this->admin = new class ($this->model, $auth) extends OsmiumAdmin {
            public function __construct(OsmiumAdminModel $model, AdminAuth $auth)
            {
                $this->model = $model;
                $this->auth = $auth;
            }
        };
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($this->dir);
        $_SESSION = [];
        $_POST = [];
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    private function writeConfig(string $name, array $data): void
    {
        \file_put_contents("{$this->dir}/{$name}", "<?php exit(); ?>\n" . \json_encode($data, JSON_PRETTY_PRINT));
    }

    private function readConfig(string $name): array
    {
        $raw = (string) \file_get_contents("{$this->dir}/{$name}");

        return \json_decode(\substr($raw, \strpos($raw, '{')), true);
    }

    private function osmium(): Osmium
    {
        return new class ($this->db) extends Osmium {
            public function __construct(OsmiumPDO $db)
            {
                $this->dataSource = $db;
                $this->config = new OsmiumConfig((object) ['site' => (object) ['scheme' => 'http', 'subdomain' => '', 'domain' => 'x.test', 'hostName' => 'x.test', 'trustProxyHeaders' => false]]);
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
        };
    }

    private function controller(string $class, array &$data): object
    {
        $dir = $this->dir;
        $subclass = $class === ShopSettingsController::class ? TestShopSettingsController::class : TestPaymentSettingsController::class;

        return new $subclass($this->osmium(), $this->admin, $data, $dir);
    }

    /** @return array{redirect: ?string, data: array} */
    private function request(string $class, array $fields, string $method = 'POST', bool $validToken = true): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $method === 'POST' ? $fields + ['token' => $validToken ? 'good-token' : 'forged'] : [];
        $data = ['admin' => ['basePath' => '/admin/']];
        $redirect = null;

        try {
            $this->controller($class, $data)->index();
        } catch (\RuntimeException $e) {
            $redirect = $e->getMessage();
        }

        return ['redirect' => $redirect, 'data' => $data];
    }

    private function lastChangelog(): string
    {
        $table = $this->db->tablePrefix() . 'changelog';

        return (string) ($this->db->prepare(sql: "SELECT description FROM {$table} ORDER BY id DESC LIMIT 1")->single()['description'] ?? '');
    }

    public function test_a_forged_token_changes_no_config_and_sends_the_user_back_with_an_error(): void
    {
        $pages = [
            'shop' => [ShopSettingsController::class, 'shop.json.php', 'settings/shop/'],
            'payments' => [PaymentSettingsController::class, 'checkout.json.php', 'settings/payments/'],
        ];

        foreach ($pages as $page => [$class, $file, $path]) {
            $before = \file_get_contents("{$this->dir}/{$file}");
            unset($_SESSION['settings_error']);

            $result = $this->request($class, ['payment_provider' => 'evil', 'currency' => 'XXX'], validToken: false);

            $this->assertSame("/admin/{$path}", $result['redirect'], $page);
            $this->assertSame($before, \file_get_contents("{$this->dir}/{$file}"), "{$page} must not write");
            $this->assertSame('Invalid form submission. Please try again.', $_SESSION['settings_error'], $page);
        }
    }

    public function test_the_shop_form_saves_prices_and_display_options(): void
    {
        $this->request(ShopSettingsController::class, ['currency' => 'EUR', 'currency_sign' => '€', 'tax_rate' => '19', 'tax_name' => 'MwSt', 'country' => 'de', 'delivery_message' => 'Two days', 'basket_abandoned_hours' => '6', 'search_enabled' => '1', 'category_show_counts' => '1']);

        $shop = $this->readConfig('shop.json.php')['shop'];
        $this->assertSame(['EUR', '€', '19', 'MwSt', 'DE', 'Two days', 6, true, true], [$shop['currency'], $shop['currencySign'], $shop['taxRatePercent'], $shop['taxName'], $shop['country'], $shop['deliveryMessage'], $shop['basketAbandonedHours'], $shop['searchEnabled'], $shop['categoryShowCounts']]);
        $this->assertFalse($shop['productShowSummary'], 'an unticked display option is saved as off');
        $this->assertTrue($shop['categoryShowCounts']);
        $this->assertSame('Updated shop settings', $this->lastChangelog());
    }

    public function test_a_country_that_is_not_two_letters_is_left_unchanged_with_a_message(): void
    {
        foreach (['UK1', 'G', 'United Kingdom', '1A'] as $bad) {
            unset($_SESSION['settings_error']);

            $this->request(ShopSettingsController::class, ['country' => $bad]);

            $this->assertSame('GB', $this->readConfig('shop.json.php')['shop']['country'], $bad);
            $this->assertStringContainsString('two-letter code', $_SESSION['settings_error'], $bad);
        }
    }

    public function test_the_notification_matrix_is_saved_for_email_and_sms_is_kept_when_no_provider_is_ready(): void
    {
        $this->request(ShopSettingsController::class, ['notify_order_dispatched_email' => '1']);

        $matrix = $this->readConfig('shop.json.php')['shop']['customerNotifications'];
        $this->assertTrue($matrix['order_dispatched']['email']);
        $this->assertFalse($matrix['order_paid']['email'], 'an unticked event is switched off');
        $this->assertArrayHasKey('sms', $matrix['order_dispatched'], 'the SMS column is carried through, not dropped');
    }

    public function test_the_payments_form_saves_the_provider_to_the_checkout_config_only(): void
    {
        $shopBefore = \file_get_contents("{$this->dir}/shop.json.php");

        $result = $this->request(PaymentSettingsController::class, ['payment_provider' => 'stripe']);

        $this->assertSame('stripe', $this->readConfig('checkout.json.php')['checkout']['paymentProvider']);
        $this->assertSame($shopBefore, \file_get_contents("{$this->dir}/shop.json.php"));
        $this->assertSame('/admin/settings/payments/', $result['redirect']);
        $this->assertSame('Updated payment settings', $this->lastChangelog());
    }

    public function test_the_shop_page_lists_the_notification_events_and_sms_state(): void
    {
        $notifications = $this->request(ShopSettingsController::class, [], 'GET')['data']['admin']['notifications'];

        $this->assertSame(\array_keys(CustomerNotifications::EVENTS), \array_keys($notifications['events']));
        $this->assertSame(['email', 'sms'], \array_keys($notifications['transports']));
        $this->assertTrue($notifications['settings']['order_paid']['email'], 'the saved matrix comes from the shop config');
        $this->assertFalse($notifications['smsReady'], 'no SMS service is installed in the test setup');
    }

    public function test_the_payments_page_offers_the_saved_provider(): void
    {
        $admin = $this->request(PaymentSettingsController::class, [], 'GET')['data']['admin'];

        $this->assertIsArray($admin['providerOptions']);
        $this->assertIsArray($admin['externalProviders']);
        $this->assertArrayHasKey('providerReadinessWarning', $admin);
        $this->assertNull($admin['providerReadinessWarning'], 'none is selected, so nothing to warn about');
    }
}
