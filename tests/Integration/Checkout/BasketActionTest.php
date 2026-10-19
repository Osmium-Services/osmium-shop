<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Tests\Helpers\ControllerHarness;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * The basket endpoint end to end: the real controller file, the real basket
 * and totals services, a real database. What a browser can ask for, what it
 * is refused, and what it is shown afterwards.
 */
class BasketActionTest extends ShopDatabaseTestCase
{
    private const TOKEN = 'session-token-123';

    private string $controller;
    private int $productId;
    private string|false $previousDocumentRoot;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();

        $root = \dirname(__DIR__, 6);
        $this->controller = $root . '/app/services/osmium-shop/api/basket-action.php';
        $this->previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? false;
        $_SERVER['DOCUMENT_ROOT'] = $root;

        $this->session = ControllerHarness::beginSession(self::TOKEN);

        ControllerHarness::resetProviderMemo([]);
        $this->productId = $this->createTestProduct(['price_exc_tax' => 100.00, 'model_name' => 'Basket Panel']);
    }

    protected function tearDown(): void
    {
        ControllerHarness::endSession($this->session);
        ControllerHarness::resetProviderMemo(null);
        if ($this->previousDocumentRoot === false) unset($_SERVER['DOCUMENT_ROOT']);
        else $_SERVER['DOCUMENT_ROOT'] = $this->previousDocumentRoot;

        parent::tearDown();
    }

    private function act(array $input, bool $withToken = true, array $checkout = []): array
    {
        $input = $input + ($withToken ? ['csrf_token' => self::TOKEN] : []);

        return ControllerHarness::run(
            controllerFile: $this->controller,
            input: $input,
            db: $this->db,
            checkout: $checkout + ['paymentProvider' => 'none', 'deliveryCharge' => 4.99],
        );
    }

    private function add(int $quantity = 1): array
    {
        return $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => $quantity]);
    }

    // ── reading ──────────────────────────────────────────────────────────────

    public function test_an_empty_basket_can_be_read_without_a_token(): void
    {
        $response = $this->act(['action' => 'get'], withToken: false);

        $this->assertTrue($response['ok']);
        $this->assertTrue($response['body']['success']);
        $this->assertSame([], $response['body']['lines']);
        $this->assertSame('GBP', $response['body']['currency']);
        $this->assertFalse($response['body']['discounts_enabled']);
        $this->assertGreaterThan(0, $response['body']['basket_id']);
    }

    // ── refusing changes without a valid token ───────────────────────────────

    public function test_every_change_is_refused_without_a_valid_token(): void
    {
        $changes = [
            ['action' => 'add', 'product_id' => $this->productId],
            ['action' => 'update_quantity', 'item_id' => 1, 'quantity' => 5],
            ['action' => 'remove', 'item_id' => 1],
            ['action' => 'apply_coupon', 'code' => 'SAVE10'],
            ['action' => 'remove_coupon'],
        ];

        foreach ($changes as $change) {
            $missing = $this->act($change, withToken: false);
            $wrong = $this->act($change + ['csrf_token' => 'not-the-token'], withToken: false);

            $this->assertFalse($missing['ok'], $change['action']);
            $this->assertStringContainsString('session has expired', $missing['body']['error']);
            $this->assertFalse($wrong['ok'], $change['action']);
        }
    }

    public function test_a_missing_session_token_is_refused_even_when_the_browser_sends_an_empty_one(): void
    {
        $_SESSION = [];

        $response = $this->act(['action' => 'add', 'product_id' => $this->productId, 'csrf_token' => '']);

        $this->assertFalse($response['ok']);
    }

    // ── adding ───────────────────────────────────────────────────────────────

    public function test_adding_a_product_shows_the_priced_line_and_totals(): void
    {
        $response = $this->add(quantity: 2);

        $this->assertTrue($response['ok']);
        $this->assertCount(1, $response['body']['lines']);
        $this->assertSame($this->productId, (int) $response['body']['lines'][0]['product_id']);
        $this->assertSame(2, (int) $response['body']['lines'][0]['quantity']);
        $this->assertEqualsWithDelta(200.00, (float) $response['body']['subtotal_exc_tax'], 0.001);
        $this->assertSame(2, (int) $response['body']['item_count']);
        $this->assertArrayHasKey('thumbImage', $response['body']['lines'][0]);
    }

    public function test_a_quantity_below_one_is_treated_as_one(): void
    {
        $this->assertSame(1, (int) $this->add(quantity: 0)['body']['lines'][0]['quantity']);
        $this->assertSame(2, (int) $this->add(quantity: -5)['body']['lines'][0]['quantity']); // Same line, one more
    }

    public function test_adding_the_same_product_again_adds_to_its_line(): void
    {
        $this->add(quantity: 1);
        $response = $this->add(quantity: 3);

        $this->assertCount(1, $response['body']['lines']);
        $this->assertSame(4, (int) $response['body']['lines'][0]['quantity']);
    }

    public function test_a_product_that_does_not_exist_is_refused_politely(): void
    {
        $response = $this->act(['action' => 'add', 'product_id' => 987654321, 'quantity' => 1]);

        $this->assertFalse($response['ok']);
        $this->assertSame('That product is no longer available.', $response['body']['error']);
    }

    // ── changing and removing ────────────────────────────────────────────────

    public function test_a_line_quantity_can_be_changed(): void
    {
        $itemId = (int) $this->add()['body']['lines'][0]['id'];

        $response = $this->act(['action' => 'update_quantity', 'item_id' => $itemId, 'quantity' => 5]);

        $this->assertSame(5, (int) $response['body']['lines'][0]['quantity']);
        $this->assertEqualsWithDelta(500.00, (float) $response['body']['subtotal_exc_tax'], 0.001);
    }

    public function test_setting_a_quantity_below_one_removes_the_line(): void
    {
        $itemId = (int) $this->add()['body']['lines'][0]['id'];

        $response = $this->act(['action' => 'update_quantity', 'item_id' => $itemId, 'quantity' => 0]);

        $this->assertSame([], $response['body']['lines']);
    }

    public function test_a_line_can_be_removed(): void
    {
        $itemId = (int) $this->add()['body']['lines'][0]['id'];

        $response = $this->act(['action' => 'remove', 'item_id' => $itemId]);

        $this->assertTrue($response['ok']);
        $this->assertSame([], $response['body']['lines']);
    }

    // ── coupons ──────────────────────────────────────────────────────────────

    public function test_applying_an_empty_coupon_asks_for_one(): void
    {
        $response = $this->act(['action' => 'apply_coupon', 'code' => '   ']);

        $this->assertSame('Please enter a coupon code.', $response['body']['error']);
    }

    public function test_coupons_are_unavailable_with_no_payment_provider(): void
    {
        $response = $this->act(['action' => 'apply_coupon', 'code' => 'SAVE10']);

        $this->assertSame('Coupon codes are not available at the moment.', $response['body']['error']);
        $this->assertArrayNotHasKey('applied_coupon_code', $_SESSION);
    }

    public function test_an_unknown_coupon_is_refused_and_not_remembered(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);

        $response = $this->act(['action' => 'apply_coupon', 'code' => 'NOPE'], checkout: ['paymentProvider' => 'fake']);

        $this->assertSame("That code isn't valid or has expired.", $response['body']['error']);
        $this->assertArrayNotHasKey('applied_coupon_code', $_SESSION);
    }

    public function test_a_valid_coupon_is_remembered_and_discounts_the_basket_until_removed(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->createCoupon('SAVE10', percent: 10.0);
        $checkout = ['paymentProvider' => 'fake'];

        $before = $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1], checkout: $checkout);
        $applied = $this->act(['action' => 'apply_coupon', 'code' => '  SAVE10 '], checkout: $checkout);
        $removed = $this->act(['action' => 'remove_coupon'], checkout: $checkout);

        $this->assertEqualsWithDelta(125.99, (float) $before['body']['total_inc_tax'], 0.001); // (100.00 + 4.99 delivery) plus 20% VAT
        $this->assertTrue($applied['ok']);
        $this->assertTrue($applied['body']['discounts_enabled']);
        $this->assertEqualsWithDelta(113.99, (float) $applied['body']['total_inc_tax'], 0.001); // 10% off the goods, delivery unchanged
        $this->assertEqualsWithDelta(125.99, (float) $removed['body']['total_inc_tax'], 0.001);
        $this->assertArrayNotHasKey('applied_coupon_code', $_SESSION);
    }

    public function test_an_applied_coupon_is_remembered_by_the_session_trimmed(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->createCoupon('SAVE10', percent: 10.0);

        $this->act(['action' => 'apply_coupon', 'code' => '  SAVE10 '], checkout: ['paymentProvider' => 'fake']);

        $this->assertSame('SAVE10', $_SESSION['applied_coupon_code']);
    }

    public function test_a_code_is_refused_while_a_sitewide_sale_is_running_and_not_remembered(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->createCoupon('SAVE10', percent: 10.0);
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discounts (name, type, value_type, value, active)
                  VALUES ('Test sale', 'sitewide', 'percent', 20, 1)",
        )->execute();
        $checkout = ['paymentProvider' => 'fake'];

        $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1], checkout: $checkout);
        $response = $this->act(['action' => 'apply_coupon', 'code' => 'SAVE10'], checkout: $checkout);

        $this->assertFalse($response['ok']);
        $this->assertSame('A sale is already running, so coupon codes cannot be used with it.', $response['body']['error']);
        $this->assertArrayNotHasKey('applied_coupon_code', $_SESSION);
    }

    public function test_a_coupon_under_its_minimum_spend_is_refused_with_the_shortfall_and_not_remembered(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->createCoupon('BIG150', percent: 10.0, minSpend: 150.00);
        $checkout = ['paymentProvider' => 'fake'];

        $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1], checkout: $checkout); // £120.00 inc VAT
        $response = $this->act(['action' => 'apply_coupon', 'code' => 'BIG150'], checkout: $checkout);

        $this->assertFalse($response['ok']);
        $this->assertSame('Spend £30.00 more (inc. VAT) to use this code. The minimum is £150.00.', $response['body']['error']);
        $this->assertArrayNotHasKey('applied_coupon_code', $_SESSION);
    }

    public function test_a_coupon_is_accepted_once_the_basket_reaches_its_minimum_spend(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->createCoupon('BIG150', percent: 10.0, minSpend: 150.00);
        $checkout = ['paymentProvider' => 'fake'];

        $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => 2], checkout: $checkout); // £240.00 inc VAT
        $response = $this->act(['action' => 'apply_coupon', 'code' => 'BIG150'], checkout: $checkout);

        $this->assertTrue($response['ok']);
        $this->assertSame('BIG150', $response['body']['discount']['code']);
        $this->assertNull($response['body']['coupon_notice']);
        $this->assertSame('BIG150', $_SESSION['applied_coupon_code']);
    }

    public function test_a_remembered_coupon_is_held_back_with_a_notice_when_the_basket_drops_below_its_minimum(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->createCoupon('BIG150', percent: 10.0, minSpend: 150.00);
        $checkout = ['paymentProvider' => 'fake'];

        $added = $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => 2], checkout: $checkout);
        $this->act(['action' => 'apply_coupon', 'code' => 'BIG150'], checkout: $checkout);
        $itemId = (int) $added['body']['lines'][0]['id'];
        $reduced = $this->act(['action' => 'update_quantity', 'item_id' => $itemId, 'quantity' => 1], checkout: $checkout);

        $this->assertNull($reduced['body']['discount']);
        $this->assertSame('min_spend', $reduced['body']['coupon_notice']['type']);
        $this->assertSame('BIG150', $reduced['body']['coupon_notice']['code']);
        $this->assertEqualsWithDelta(125.99, (float) $reduced['body']['total_inc_tax'], 0.001); // Full price: the coupon is not taking money off
        $this->assertSame('BIG150', $_SESSION['applied_coupon_code']); // Still remembered, so it applies again if the basket grows back
    }

    public function test_a_free_delivery_coupon_waives_delivery_through_the_basket(): void
    {
        ControllerHarness::resetProviderMemo(['fake' => $this->fakeProvider()]);
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discounts (name, type, code, value_type, value, active)
                  VALUES ('Test free delivery', 'coupon', 'FREEDEL', 'free_delivery', 0, 1)",
        )->execute();
        $checkout = ['paymentProvider' => 'fake'];

        $before = $this->act(['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1], checkout: $checkout);
        $applied = $this->act(['action' => 'apply_coupon', 'code' => 'FREEDEL'], checkout: $checkout);

        $this->assertEqualsWithDelta(125.99, (float) $before['body']['total_inc_tax'], 0.001); // £100 + £4.99 delivery, plus 20% VAT
        $this->assertTrue($applied['ok']);
        $this->assertTrue($applied['body']['free_delivery']);
        $this->assertSame('FREEDEL', $applied['body']['discount']['code']);
        $this->assertEqualsWithDelta(120.00, (float) $applied['body']['total_inc_tax'], 0.001); // Delivery waived, goods untouched
    }

    public function test_removing_a_coupon_when_none_is_applied_is_harmless(): void
    {
        $response = $this->act(['action' => 'remove_coupon']);

        $this->assertTrue($response['ok']);
    }

    // ── anything else ────────────────────────────────────────────────────────

    public function test_an_unknown_action_is_refused(): void
    {
        $response = $this->act(['action' => 'steal_everything']);

        $this->assertFalse($response['ok']);
        $this->assertSame('Unknown action', $response['body']['error']);
    }

    public function test_no_action_at_all_is_refused(): void
    {
        $this->assertFalse($this->act([])['ok']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function fakeProvider(): array
    {
        return ['id' => 'fake', 'label' => 'Fake', 'ready' => true, 'frontend' => [], 'settingsRoute' => null];
    }

    private function createCoupon(string $code, float $percent, ?float $minSpend = null): void
    {
        $this->db->prepare(
            sql: "INSERT INTO {$this->db->tablePrefix()}discounts (name, type, code, value_type, value, min_spend_inc_tax, active)
                  VALUES (:name, 'coupon', :code, 'percent', :value, :min, 1)",
            bindings: [':name' => 'Test coupon ' . $code, ':code' => $code, ':value' => $percent, ':min' => $minSpend],
        )->execute();
    }
}
