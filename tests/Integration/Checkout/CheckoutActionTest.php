<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Core\Library\StoreFinance;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopPaymentException;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Tests\Helpers\ControllerHarness;
use Tests\Helpers\FakePaymentProvider;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * The checkout endpoint end to end: the real controller file, real services, a real
 * database, and a fake payment provider installed as a service so the payment hooks
 * run through the real loader. What happens to an order and to the customer's money
 * as the browser walks through create_order and capture_order.
 */
class CheckoutActionTest extends ShopDatabaseTestCase
{
    private const TOKEN = 'checkout-token-456';
    private const SESSION_EXPIRED = 'Your session has expired. Please reload the page and try again.';

    private string $controller;
    private string $serviceDir;
    private int $productId;
    private array $session;
    private int $ordersAtStart;
    private ?int $orderId = null;
    private string|false $previousDocumentRoot;
    private string|false $previousErrorLog;
    private string $errorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $root = \dirname(__DIR__, 6);
        $this->controller = $root . '/app/services/osmium-shop/api/checkout-action.php';
        $this->previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? false;
        $_SERVER['DOCUMENT_ROOT'] = $root;

        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'checkouterr');
        $this->previousErrorLog = \ini_set('error_log', $this->errorLog);

        $this->session = ControllerHarness::beginSession(self::TOKEN);
        FakePaymentProvider::reset();
        $this->installFakePaymentService();
        ControllerHarness::resetProviderMemo([
            'fake' => ['id' => 'fake', 'label' => 'Fake', 'ready' => true, 'frontend' => [], 'settingsRoute' => null],
        ]);

        $this->productId = $this->createTestProduct(['price_exc_tax' => 100.00, 'model_name' => 'Checkout Panel']);
        $this->ordersAtStart = $this->orderTotal();
    }

    protected function tearDown(): void
    {
        ControllerHarness::endSession($this->session);
        ControllerHarness::resetProviderMemo(null);
        \array_map('unlink', \glob($this->serviceDir . '/*') ?: []);
        \rmdir($this->serviceDir);
        \ini_set('error_log', (string) $this->previousErrorLog);
        \unlink($this->errorLog);
        if ($this->previousDocumentRoot === false) unset($_SERVER['DOCUMENT_ROOT']);
        else $_SERVER['DOCUMENT_ROOT'] = $this->previousDocumentRoot;

        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function installFakePaymentService(): void
    {
        $this->serviceDir = \sys_get_temp_dir() . '/fake-payments-' . \uniqid();
        \mkdir($this->serviceDir);
        \file_put_contents($this->serviceDir . '/service.json', \json_encode([
            'id' => 'fake-payments',
            'name' => 'Fake Payments',
            'version' => '1.0.0',
            'description' => 'Test payment provider',
            'autoload' => [],
            'hooks' => [
                'payment.create' => [FakePaymentProvider::class . '::create'],
                'payment.confirm' => [FakePaymentProvider::class . '::confirm'],
            ],
        ]));

        (new ServiceModel($this->db))->insert(
            serviceId: 'fake-payments',
            name: 'Fake Payments',
            version: '1.0.0',
            description: null,
            authorName: null,
            githubRepo: null,
            path: $this->serviceDir,
            config: null,
            installedBy: null,
        );
    }

    private function act(array $input, bool $withToken = true, array $site = [], array $checkout = []): array
    {
        $input = $input + ($withToken ? ['csrf_token' => self::TOKEN] : []);

        return ControllerHarness::run(
            controllerFile: $this->controller,
            input: $input,
            db: $this->db,
            checkout: $checkout + ['paymentProvider' => 'fake', 'deliveryCharge' => 4.99, 'deliveryExcludedPostcodes' => ['JE', 'GY']],
            site: $site + ['subdomain' => '', 'name' => 'Test Site', 'FQDN' => 'https://example.test'],
            extraConfig: ['email' => (object) ['provider' => 'smtp', 'smtpHost' => '', 'smtpPort' => 587, 'smtpUsername' => '', 'smtpPassword' => '', 'fromAddress' => '', 'fromName' => '']],
        );
    }

    private function basket(): CheckoutBasketService
    {
        return new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
    }

    private function fillBasket(int $quantity = 1): int
    {
        $basket = $this->basket();
        $basketId = $basket->getOrCreateBasket(\session_id());
        $basket->addLine(basketId: $basketId, productId: $this->productId, quantity: $quantity);

        return $basketId;
    }

    private function customer(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Jane Smith',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '01234 567890',
            'delivery_address1' => '1 High Street',
            'delivery_city' => 'Cheltenham',
            'delivery_postcode' => 'GL20 8JH',
            'terms_accepted' => '1',
        ];
    }

    private function createOrder(array $customer = []): array
    {
        return $this->act(['action' => 'create_order'] + $this->customer($customer));
    }

    private function orders(): ShopOrderService
    {
        return new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->basket(),
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );
    }

    private function storedOrder(): array
    {
        return $this->orders()->getOrder($this->orderId ?? (int) $_SESSION['pending_order_id']);
    }

    /** Create a pending order and make the fake provider confirm exactly what it is owed. */
    private function orderAwaitingCapture(array $paymentOverrides = []): array
    {
        $this->fillBasket();
        $created = $this->createOrder();
        $this->assertTrue($created['body']['success']);
        $this->orderId = (int) $_SESSION['pending_order_id'];
        $order = $this->storedOrder();

        FakePaymentProvider::$confirmAnswer = $paymentOverrides + [
            'amount' => (float) $order['total_inc_tax'],
            'currency' => 'GBP',
            'status' => 'completed',
            'capture_ref' => 'CAP-1',
            'payer_email' => 'payer@example.com',
        ];

        return $order;
    }

    private function capture(?string $ref = 'FAKE-REF-1'): array
    {
        return $this->act(['action' => 'capture_order', 'provider_order_ref' => $ref]);
    }

    private function hookCalls(string $hook): int
    {
        return \count(\array_filter(FakePaymentProvider::$calls, fn(array $c) => $c['hook'] === $hook));
    }

    private function orderTotal(): int
    {
        return (int) $this->db->prepare("SELECT COUNT(*) AS n FROM {$this->db->tablePrefix()}shop_orders")->single()['n'];
    }

    /** Orders this test has created (the dev database may already hold seeded ones). */
    private function orderRows(): int
    {
        return $this->orderTotal() - $this->ordersAtStart;
    }

    // ── guards ───────────────────────────────────────────────────────────────

    public function test_no_session_at_all_is_told_to_reload_and_a_live_site_adds_no_diagnostics(): void
    {
        \session_id('');

        $response = $this->act(['action' => 'create_order']);

        $this->assertFalse($response['body']['success']);
        $this->assertSame(self::SESSION_EXPIRED, $response['body']['error']);
        $this->assertArrayNotHasKey('diagnostic', $response['body']);
    }

    public function test_a_staging_site_explains_why_the_session_is_missing(): void
    {
        \session_id('');

        $response = $this->act(['action' => 'create_order'], site: ['subdomain' => 'staging']);

        $this->assertStringContainsString('No session_id()', $response['body']['diagnostic']);
    }

    public function test_a_wrong_or_missing_token_is_refused_for_every_action(): void
    {
        foreach (['create_order', 'capture_order'] as $action) {
            $missing = $this->act(['action' => $action], withToken: false);
            $wrong = $this->act(['action' => $action, 'csrf_token' => 'nope'], withToken: false);

            $this->assertFalse($missing['body']['success'], $action);
            $this->assertSame(self::SESSION_EXPIRED, $missing['body']['error']);
            $this->assertFalse($wrong['body']['success'], $action);
            $this->assertArrayNotHasKey('diagnostic', $wrong['body']); // Never on a live site
        }

        $this->assertSame(0, $this->hookCalls('payment.create'));
    }

    public function test_a_staging_site_reports_token_lengths_but_not_the_tokens(): void
    {
        $response = $this->act(['action' => 'create_order'], withToken: false, site: ['subdomain' => 'staging']);

        $this->assertStringContainsString('submittedTokenLen=0', $response['body']['diagnostic']);
        $this->assertStringNotContainsString(self::TOKEN, $response['body']['diagnostic']);
    }

    public function test_a_staging_diagnostic_never_echoes_cookie_values_or_the_session_id(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'PHPSESSID=cookiesecret99; theme=dark';

        try {
            $bad = $this->act(['action' => 'create_order'], withToken: false, site: ['subdomain' => 'staging']);
            \session_id('');
            $none = $this->act(['action' => 'create_order'], site: ['subdomain' => 'staging']);
        } finally {
            unset($_SERVER['HTTP_COOKIE']);
        }

        foreach ([$bad, $none] as $response) {
            $this->assertStringContainsString('PHPSESSID,theme', $response['body']['diagnostic']);
            $this->assertStringNotContainsString('cookiesecret99', $response['body']['diagnostic']);
        }
        $this->assertStringNotContainsString(\session_id() ?: 'no-id', $bad['body']['diagnostic']);
    }

    public function test_with_no_payment_provider_nothing_can_be_ordered_or_paid(): void
    {
        $this->fillBasket();

        $response = $this->act(['action' => 'create_order'] + $this->customer(), checkout: ['paymentProvider' => 'none']);

        $this->assertFalse($response['ok']);
        $this->assertStringContainsString('Card payment is not available', $response['body']['error']);
        $this->assertSame(0, $this->orderRows());
    }

    public function test_a_provider_that_is_installed_but_not_ready_cannot_take_payment(): void
    {
        ControllerHarness::resetProviderMemo([
            'fake' => ['id' => 'fake', 'label' => 'Fake', 'ready' => false, 'frontend' => [], 'settingsRoute' => null],
        ]);
        $this->fillBasket();

        $response = $this->createOrder();

        $this->assertStringContainsString('Card payment is not available', $response['body']['error']);
        $this->assertSame(0, $this->hookCalls('payment.create'));
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->assertSame('Unknown action', $this->act(['action' => 'refund_everyone'])['body']['error']);
    }

    // ── create_order ─────────────────────────────────────────────────────────

    public function test_an_empty_basket_cannot_be_ordered(): void
    {
        $response = $this->createOrder();

        $this->assertSame('Your basket is empty.', $response['body']['error']);
        $this->assertSame(0, $this->hookCalls('payment.create'));
    }

    public function test_a_bad_form_is_returned_with_its_errors_and_creates_nothing(): void
    {
        $this->fillBasket();

        $response = $this->createOrder(['customer_email' => 'not-an-email']);

        $this->assertTrue($response['ok']);
        $this->assertFalse($response['body']['success']);
        $this->assertArrayHasKey('customer_email', $response['body']['errors']);
        $this->assertSame('Please check the details you entered.', $response['body']['error']);
        $this->assertSame(0, $this->orderRows());
        $this->assertSame(0, $this->hookCalls('payment.create'));
    }

    public function test_a_valid_order_is_stored_pending_and_handed_to_the_provider(): void
    {
        $this->fillBasket(quantity: 2);

        $response = $this->createOrder();

        $this->assertTrue($response['body']['success']);
        $this->assertSame('FAKE-REF-1', $response['body']['provider_order_ref']);
        $this->assertSame('secret-1', $response['body']['client_secret']);
        $this->assertMatchesRegularExpression('/^SG2-\d+$/', $response['body']['order_ref']);

        $order = $this->storedOrder();
        $this->assertSame('pending', $order['status']);
        $this->assertSame('fake', $order['payment_provider']);
        $this->assertSame('FAKE-REF-1', $order['payment_order_ref']);
        $this->assertEqualsWithDelta(245.99, (float) $order["total_inc_tax"], 0.001); // (200.00 + 4.99 delivery) plus VAT, recomputed from the basket

        $this->assertSame('FAKE-REF-1', $_SESSION['pending_payment_ref']);
        $this->assertSame((int) $order['id'], $_SESSION['pending_order_id']);

        $this->assertSame(1, $this->hookCalls('payment.create'));
        $call = FakePaymentProvider::$calls[0]['payload'];
        $this->assertSame('fake', $call['provider']);
        $this->assertSame((int) $order['id'], (int) $call['order']['id']);
    }

    public function test_a_provider_that_never_answers_leaves_a_pending_order_and_no_session_to_confirm(): void
    {
        FakePaymentProvider::$createAnswer = null;
        $this->fillBasket();

        $response = $this->createOrder();

        $this->assertFalse($response['body']['success']);
        $this->assertStringContainsString('We could not start the payment', $response['body']['error']);
        $this->assertSame(1, $this->orderRows()); // Written before payment is attempted, so a human can see it
        $this->assertArrayNotHasKey('pending_order_id', $_SESSION);
        $this->assertArrayNotHasKey('diagnostic', $response['body']);
    }

    public function test_a_provider_answer_without_a_reference_cannot_be_confirmed_later(): void
    {
        FakePaymentProvider::$createAnswer = ['client_secret' => 'secret-1'];
        $this->fillBasket();

        $response = $this->createOrder();

        $this->assertFalse($response['body']['success']);
        $this->assertArrayNotHasKey('pending_payment_ref', $_SESSION);
    }

    public function test_a_provider_failure_gives_the_customer_a_plain_message_and_staging_the_detail(): void
    {
        FakePaymentProvider::$createAnswer = new ShopPaymentException('Card network unreachable');
        $this->fillBasket();

        $live = $this->createOrder();
        $staging = $this->act(['action' => 'create_order'] + $this->customer(), site: ['subdomain' => 'staging']);

        $this->assertStringNotContainsString('unreachable', \json_encode($live['body']));
        $this->assertSame('Card network unreachable', $staging['body']['diagnostic']);
    }

    public function test_an_unexpected_provider_error_is_a_generic_message(): void
    {
        FakePaymentProvider::$createAnswer = new \RuntimeException('SQLSTATE secret detail');
        $this->fillBasket();

        $response = $this->createOrder();

        $this->assertFalse($response['ok']);
        $this->assertSame('Sorry, something went wrong. Please try again.', $response['body']['error']);
    }

    public function test_an_excluded_delivery_postcode_is_refused(): void
    {
        $this->fillBasket();

        $response = $this->createOrder(['delivery_postcode' => 'JE2 3AA']);

        $this->assertFalse($response['body']['success'] ?? true);
        $this->assertSame(0, $this->hookCalls('payment.create'));
        $this->assertSame(0, $this->orderRows());
    }

    // ── capture_order ────────────────────────────────────────────────────────

    public function test_nothing_can_be_confirmed_without_a_pending_order_in_the_session(): void
    {
        $response = $this->capture();

        $this->assertFalse($response['ok']);
        $this->assertStringContainsString('could not find your order', $response['body']['error']);
        $this->assertSame(0, $this->hookCalls('payment.confirm'));
    }

    public function test_a_different_payment_reference_is_not_matched_to_this_session(): void
    {
        $this->orderAwaitingCapture();

        $response = $this->capture('SOMEONE-ELSES-REF');

        $this->assertStringContainsString('could not match that payment', $response['body']['error']);
        $this->assertSame(0, $this->hookCalls('payment.confirm'));
        $this->assertSame('pending', $this->storedOrder()['status']);
    }

    public function test_a_completed_payment_for_the_right_amount_marks_the_order_paid(): void
    {
        $order = $this->orderAwaitingCapture();

        $response = $this->capture();

        $this->assertTrue($response['body']['success']);
        $this->assertSame($order['order_ref'], $response['body']['order_ref']);
        $this->assertGreaterThan(0, (int) $response['body']['invoice_number']);

        $paid = $this->storedOrder();
        $this->assertSame('paid', $paid['status']);
        $this->assertSame('CAP-1', $paid['payment_capture_ref']);
        $this->assertSame('payer@example.com', $paid['payment_payer_email']);
        $this->assertSame((int) $response['body']['invoice_number'], (int) $paid['invoice_number']);
        $this->assertSame('FAKE-REF-1', FakePaymentProvider::$calls[\array_key_last(FakePaymentProvider::$calls)]['payload']['ref']);
    }

    public function test_a_successful_payment_clears_the_session_and_leaves_the_sale_for_the_thank_you_page(): void
    {
        $this->orderAwaitingCapture();
        $_SESSION['applied_coupon_code'] = 'SAVE10';

        $this->capture();

        $this->assertArrayNotHasKey('pending_order_id', $_SESSION);
        $this->assertArrayNotHasKey('pending_payment_ref', $_SESSION);
        $this->assertArrayNotHasKey('applied_coupon_code', $_SESSION);
        $this->assertEqualsWithDelta(125.99, (float) $_SESSION['purchase_event']['value'], 0.001);
        $this->assertSame('GBP', $_SESSION['purchase_event']['currency']);
    }

    public function test_the_basket_is_converted_once_paid(): void
    {
        $order = $this->orderAwaitingCapture();

        $this->capture();

        $row = $this->db->prepare(
            sql: "SELECT status FROM {$this->db->tablePrefix()}checkout_baskets WHERE id = :id",
            bindings: [':id' => $order['basket_id']],
        )->single();
        $this->assertSame('converted', $row['status']);
    }

    public function test_a_failed_confirmation_email_never_undoes_a_paid_order(): void
    {
        $this->orderAwaitingCapture();

        $response = $this->capture(); // The test mailer is deliberately unconfigured, so the email cannot be sent

        $this->assertTrue($response['body']['success']);
        $this->assertSame('paid', $this->storedOrder()['status']);
    }

    public function test_confirming_a_second_time_after_success_finds_no_pending_order(): void
    {
        $this->orderAwaitingCapture();
        $this->capture();

        $again = $this->capture();

        $this->assertFalse($again['ok']);
        $this->assertSame(1, $this->hookCalls('payment.confirm')); // The provider was not asked twice
    }

    public function test_the_wrong_amount_is_never_called_paid_and_the_order_waits_for_a_person(): void
    {
        $this->orderAwaitingCapture(['amount' => 1.00]);

        $response = $this->capture();

        $this->assertFalse($response['ok']);
        $this->assertStringContainsString('do not pay twice', $response['body']['error']);
        $this->assertSame('pending', $this->storedOrder()['status']);
        $this->assertArrayHasKey('pending_order_id', $_SESSION);
    }

    public function test_the_wrong_currency_is_never_called_paid(): void
    {
        $this->orderAwaitingCapture(['currency' => 'USD']);

        $response = $this->capture();

        $this->assertStringContainsString('do not pay twice', $response['body']['error']);
        $this->assertSame('pending', $this->storedOrder()['status']);
    }

    public function test_a_payment_still_pending_at_the_provider_is_not_reported_as_a_failure(): void
    {
        $this->orderAwaitingCapture(['status' => 'pending']);

        $response = $this->capture();

        $this->assertTrue($response['body']['success']);
        $this->assertTrue($response['body']['pending']);
        $this->assertSame('pending', $this->storedOrder()['status']);
        $this->assertArrayHasKey('pending_order_id', $_SESSION); // The webhook will resolve it
    }

    public function test_a_declined_payment_can_be_retried(): void
    {
        $this->orderAwaitingCapture(['status' => 'declined']);

        $response = $this->capture();

        $this->assertFalse($response['ok']);
        $this->assertSame('Your payment was not completed. Please try again or contact us.', $response['body']['error']);
        $this->assertSame('pending', $this->storedOrder()['status']);
        $this->assertArrayHasKey('pending_payment_ref', $_SESSION);
    }

    public function test_an_order_already_paid_by_the_webhook_is_reported_without_asking_the_provider_again(): void
    {
        $order = $this->orderAwaitingCapture();
        $this->orders()->markPaid(orderId: (int) $order['id'], captureRef: 'WEBHOOK-CAP', payerEmail: null);

        $response = $this->capture();

        $this->assertTrue($response['body']['success']);
        $this->assertTrue($response['body']['already_paid']);
        $this->assertSame(0, $this->hookCalls('payment.confirm'));
        $this->assertSame('WEBHOOK-CAP', $this->storedOrder()['payment_capture_ref']); // Not overwritten
    }

    /** @return array<string, array{string}> */
    public static function closedStatuses(): array
    {
        return ['failed' => ['failed'], 'cancelled' => ['cancelled']];
    }

    /** @dataProvider closedStatuses */
    public function test_orders_that_failed_or_were_cancelled_cannot_be_called_paid(string $status): void
    {
        $order = $this->orderAwaitingCapture();
        $this->orders()->markStatus((int) $order['id'], $status);

        $response = $this->capture();

        $this->assertFalse($response['ok']);
        $this->assertSame('This order can no longer be paid. Please contact us if you need help.', $response['body']['error']);
        $this->assertSame($status, $this->storedOrder()['status']);
        $this->assertSame(0, $this->hookCalls('payment.confirm'));
    }

    public function test_a_provider_that_cannot_confirm_leaves_the_order_pending(): void
    {
        $this->orderAwaitingCapture();
        FakePaymentProvider::$confirmAnswer = null;

        $response = $this->capture();

        $this->assertFalse($response['body']['success']);
        $this->assertSame('pending', $this->storedOrder()['status']);
    }
}
