<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Tests\Helpers\ShopDatabaseTestCase;

/**
 * Basket lifecycle and option pricing: which basket a session gets, what the
 * header count shows, and that option premiums are priced from the database.
 */
class CheckoutBasketLifecycleTest extends ShopDatabaseTestCase
{
    private CheckoutBasketService $baskets;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->productId = $this->createTestProduct(['price_exc_tax' => 100.00]);
    }

    private function session(): string
    {
        return 'lifecycle-' . \uniqid();
    }

    /** A type of its own for the product, so each value is the only one of its type unless a type is passed. */
    private function createOptionType(?int $productId = null): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontypes (product_id, type_label, type_slug) VALUES (:product_id, 'Type', :slug)",
            bindings: [':product_id' => $productId ?? $this->productId, ':slug' => 't' . \uniqid()],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function createOptionValue(float $premium, string $descr, float $weight = 0.0, ?string $reference = null, bool $deleted = false, ?int $typeId = null): int
    {
        $typeId ??= $this->createOptionType();
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptionvalues
                (option_type_id, option_premium, option_descr, option_packed_weight, option_reference, deleted_at)
                VALUES (:type_id, :premium, :descr, :weight, :reference, :deleted)",
            bindings: [
                ':type_id' => $typeId,
                ':premium' => $premium,
                ':descr' => $descr,
                ':weight' => $weight,
                ':reference' => $reference,
                ':deleted' => $deleted ? $this->now() : null,
            ],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    public function test_the_same_session_gets_the_same_active_basket(): void
    {
        $session = $this->session();

        $first = $this->baskets->getOrCreateBasket($session);
        $second = $this->baskets->getOrCreateBasket($session);

        $this->assertSame($first, $second);
    }

    public function test_different_sessions_get_different_baskets(): void
    {
        $this->assertNotSame(
            $this->baskets->getOrCreateBasket($this->session()),
            $this->baskets->getOrCreateBasket($this->session()),
        );
    }

    public function test_a_converted_basket_is_not_reused_and_the_session_gets_a_fresh_one(): void
    {
        $session = $this->session();
        $old = $this->baskets->getOrCreateBasket($session);
        $this->baskets->addLine(basketId: $old, productId: $this->productId, quantity: 1);

        $converted = $this->baskets->markConverted($session);
        $new = $this->baskets->getOrCreateBasket($session);

        $this->assertTrue($converted);
        $this->assertNotSame($old, $new);
        $this->assertSame([], $this->baskets->getLines($new)['lines']);
        $this->assertCount(expectedCount: 1, haystack: $this->baskets->getLines($old)['lines']); // The order's lines survive conversion
    }

    public function test_mark_converted_is_false_when_the_session_has_no_active_basket(): void
    {
        $this->assertFalse($this->baskets->markConverted($this->session()));
    }

    public function test_mark_converted_only_touches_its_own_session(): void
    {
        $mine = $this->session();
        $theirs = $this->session();
        $theirBasket = $this->baskets->getOrCreateBasket($theirs);
        $this->baskets->getOrCreateBasket($mine);

        $this->baskets->markConverted($mine);

        $this->assertSame($theirBasket, $this->baskets->getOrCreateBasket($theirs));
    }

    public function test_count_items_is_zero_for_a_session_with_no_basket(): void
    {
        $this->assertSame(0, $this->baskets->countItems($this->session()));
    }

    public function test_count_items_sums_quantities_across_lines(): void
    {
        $session = $this->session();
        $basketId = $this->baskets->getOrCreateBasket($session);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 2);
        $this->baskets->addLine(basketId: $basketId, productId: $this->createTestProduct(), quantity: 3);

        $this->assertSame(5, $this->baskets->countItems($session));
    }

    public function test_count_items_ignores_a_converted_basket(): void
    {
        $session = $this->session();
        $basketId = $this->baskets->getOrCreateBasket($session);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 4);

        $this->baskets->markConverted($session);

        $this->assertSame(0, $this->baskets->countItems($session));
    }

    public function test_update_and_remove_cannot_reach_another_baskets_line(): void
    {
        $mine = $this->baskets->getOrCreateBasket($this->session());
        $theirs = $this->baskets->getOrCreateBasket($this->session());
        $theirItem = $this->baskets->addLine(basketId: $theirs, productId: $this->productId, quantity: 1);

        $updated = $this->baskets->updateQuantity(basketId: $mine, itemId: $theirItem, quantity: 9);
        $removed = $this->baskets->removeLine(basketId: $mine, itemId: $theirItem);

        $this->assertFalse($updated);
        $this->assertFalse($removed);
        $this->assertSame(1, (int) $this->baskets->getLines($theirs)['lines'][0]['quantity']);
    }

    public function test_option_premium_is_added_to_the_unit_price_from_the_database(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $valueId = $this->createOptionValue(premium: 25.50, descr: 'Black frame');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$valueId]);

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertEqualsWithDelta(125.50, $line['unit_price_exc_tax'], 0.001);
    }

    public function test_option_weight_is_added_and_the_label_carries_the_reference_code(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $valueId = $this->createOptionValue(premium: 0.0, descr: 'Long cable', weight: 1.5, reference: 'CAB-5M');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$valueId]);

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertSame('Long cable (CAB-5M)', $line['product_options']);
        $this->assertGreaterThanOrEqual(1.5, $line['weight']);
    }

    public function test_several_option_premiums_add_up(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $a = $this->createOptionValue(premium: 10.00, descr: 'A');
        $b = $this->createOptionValue(premium: 5.25, descr: 'B');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$a, $b]);

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertEqualsWithDelta(115.25, $line['unit_price_exc_tax'], 0.001);
        $this->assertSame('A, B', $line['product_options']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableStatuses')]
    public function test_a_product_whose_status_is_not_available_cannot_be_added(int $statusId): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $this->db->prepare(sql: "UPDATE {$this->db->tablePrefix()}products SET status_id = :s WHERE id = :id", bindings: [':s' => $statusId, ':id' => $this->productId])->execute();

        $this->expectException(\Osmium\Modules\Checkout\Services\ShopOrderException::class);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);
    }

    /** @return array<string, array{int}> */
    public static function unavailableStatuses(): array
    {
        return ['discontinued' => [3], 'out of stock' => [4], 'in store only' => [7], 'coming soon' => [8]];
    }

    public function test_another_products_option_value_cannot_be_borrowed(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $otherProduct = $this->createTestProduct(['price_exc_tax' => 100.00]);
        $foreign = $this->createOptionValue(premium: -90.00, descr: 'Other product discount', typeId: $this->createOptionType($otherProduct));

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$foreign]);

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertSame(100.0, (float) $line['unit_price_exc_tax']);
        $this->assertNull($line['option_value_ids']);
    }

    public function test_two_values_of_one_option_type_are_refused(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $typeId = $this->createOptionType();
        $a = $this->createOptionValue(premium: -40.00, descr: 'A', typeId: $typeId);
        $b = $this->createOptionValue(premium: -40.00, descr: 'B', typeId: $typeId);

        $this->expectException(\Osmium\Modules\Checkout\Services\ShopOrderException::class);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$a, $b]);
    }

    public function test_the_same_option_id_sent_twice_counts_once(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $value = $this->createOptionValue(premium: 10.00, descr: 'Finish');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$value, $value, $value]);

        $this->assertSame(110.0, (float) $this->baskets->getLines($basketId)['lines'][0]['unit_price_exc_tax']);
    }

    public function test_a_negative_premium_cannot_take_the_unit_price_below_nothing(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $value = $this->createOptionValue(premium: -500.00, descr: 'Huge discount');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$value]);

        $this->assertSame(0.0, (float) $this->baskets->getLines($basketId)['lines'][0]['unit_price_exc_tax']);
    }

    public function test_unknown_and_deleted_option_values_add_nothing(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $deleted = $this->createOptionValue(premium: 50.00, descr: 'Gone', deleted: true);

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$deleted, 987654321]);

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertEqualsWithDelta(100.00, $line['unit_price_exc_tax'], 0.001);
    }

    public function test_a_client_supplied_label_never_changes_the_price(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, productOptions: 'Free upgrade -90%');

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertEqualsWithDelta(100.00, $line['unit_price_exc_tax'], 0.001);
    }

    public function test_the_same_product_with_a_different_option_is_a_separate_line(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $premium = $this->createOptionValue(premium: 40.00, descr: 'Premium finish');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$premium]);

        $lines = $this->baskets->getLines($basketId)['lines'];
        $this->assertCount(expectedCount: 2, haystack: $lines, message: 'The premium unit was merged into the plain line and priced at the plain price');
    }

    public function test_a_blank_option_label_merges_with_a_line_that_has_none(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, productOptions: '');

        $lines = $this->baskets->getLines($basketId)['lines'];
        $this->assertCount(expectedCount: 1, haystack: $lines);
        $this->assertSame(2, (int) $lines[0]['quantity']);
    }

    public function test_the_same_product_and_same_option_still_merges(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $premium = $this->createOptionValue(premium: 40.00, descr: 'Premium finish');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$premium]);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 2, optionValueIds: [$premium]);

        $lines = $this->baskets->getLines($basketId)['lines'];
        $this->assertCount(expectedCount: 1, haystack: $lines);
        $this->assertSame(3, (int) $lines[0]['quantity']);
        $this->assertEqualsWithDelta(140.00, $lines[0]['unit_price_exc_tax'], 0.001);
    }

    public function test_the_chosen_option_ids_are_kept_on_the_line_in_sorted_order(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $first = $this->createOptionValue(premium: 10.00, descr: 'Finish');
        $second = $this->createOptionValue(premium: 5.00, descr: 'Mount');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$second, $first]);

        $line = $this->baskets->getLines($basketId)['lines'][0];
        $this->assertSame("{$first},{$second}", $line['option_value_ids']);
    }

    public function test_a_line_with_no_options_keeps_no_option_ids(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1);

        $this->assertNull($this->baskets->getLines($basketId)['lines'][0]['option_value_ids']);
    }

    public function test_unknown_and_deleted_option_ids_are_not_kept(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $kept = $this->createOptionValue(premium: 10.00, descr: 'Finish');
        $deleted = $this->createOptionValue(premium: 10.00, descr: 'Old finish', deleted: true);

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$kept, $deleted, 987654321]);

        $this->assertSame((string) $kept, $this->baskets->getLines($basketId)['lines'][0]['option_value_ids']);
    }

    public function test_options_with_identical_text_but_different_ids_are_separate_lines(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $one = $this->createOptionValue(premium: 10.00, descr: 'Gland', reference: 'G1');
        $two = $this->createOptionValue(premium: 10.00, descr: 'Gland', reference: 'G1');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$one]);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$two]);

        $this->assertCount(expectedCount: 2, haystack: $this->baskets->getLines($basketId)['lines'], message: 'Two different option rows were merged because their label text matched, so one row\'s linked product would be lost');
    }

    public function test_the_order_the_options_are_chosen_in_does_not_split_a_line(): void
    {
        $basketId = $this->baskets->getOrCreateBasket($this->session());
        $first = $this->createOptionValue(premium: 10.00, descr: 'Finish');
        $second = $this->createOptionValue(premium: 5.00, descr: 'Mount');

        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$first, $second]);
        $this->baskets->addLine(basketId: $basketId, productId: $this->productId, quantity: 1, optionValueIds: [$second, $first]);

        $lines = $this->baskets->getLines($basketId)['lines'];
        $this->assertCount(expectedCount: 1, haystack: $lines);
        $this->assertSame(2, (int) $lines[0]['quantity']);
    }
}
