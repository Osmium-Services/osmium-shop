<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Services\CheckoutKitParts;
use Tests\Integration\DatabaseTestCase;

/**
 * Which products a kit line takes stock from: each chosen option's linked
 * product, or the type's default product when no choice was made.
 */
class CheckoutKitPartsTest extends DatabaseTestCase
{
    private CheckoutKitParts $kitParts;
    private int $kitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitParts = new CheckoutKitParts(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->kitId = $this->createTestProduct();
    }

    private function createType(int $productId, int $sort = 0, ?int $defaultLinked = null, bool $deleted = false): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontypes
                (product_id, type_label, type_slug, default_option_descr, default_linked_product_id, sort, deleted_at)
                VALUES (:product, 'Type', :slug, 'Standard', :default_linked, :sort, :deleted)",
            bindings: [
                ':product' => $productId,
                ':slug' => 'type-' . \uniqid(),
                ':default_linked' => $defaultLinked,
                ':sort' => $sort,
                ':deleted' => $deleted ? $this->now() : null,
            ],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function createValue(int $typeId, ?int $linked = null): int
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptionvalues
                (option_type_id, option_premium, option_descr, option_packed_weight, linked_product_id)
                VALUES (:type, 10.00, 'Choice', 0, :linked)",
            bindings: [':type' => $typeId, ':linked' => $linked],
        )->execute();

        return (int) $this->db->lastInsertId();
    }

    private function dependOn(int $typeId, int $valueId): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontype_dependencies (option_type_id, depends_on_value_id) VALUES (:type, :value)",
            bindings: [':type' => $typeId, ':value' => $valueId],
        )->execute();
    }

    private function part(int $productId, ?int $valueId): array
    {
        return ['product_id' => $productId, 'option_value_id' => $valueId, 'quantity' => 1];
    }

    public function test_a_product_with_no_options_has_no_parts(): void
    {
        $this->assertSame([], $this->kitParts->resolve($this->kitId, []));
    }

    public function test_a_chosen_option_that_links_to_a_product_is_a_part(): void
    {
        $battery = $this->createTestProduct();
        $typeId = $this->createType($this->kitId);
        $valueId = $this->createValue($typeId, linked: $battery);

        $this->assertSame([$this->part($battery, $valueId)], $this->kitParts->resolve($this->kitId, [$valueId]));
    }

    public function test_a_chosen_option_with_no_link_adds_no_part(): void
    {
        $typeId = $this->createType($this->kitId);
        $valueId = $this->createValue($typeId);

        $this->assertSame([], $this->kitParts->resolve($this->kitId, [$valueId]));
    }

    public function test_a_type_with_no_choice_made_uses_its_default_product(): void
    {
        $gland = $this->createTestProduct();
        $this->createType($this->kitId, defaultLinked: $gland);

        $this->assertSame([$this->part($gland, null)], $this->kitParts->resolve($this->kitId, []));
    }

    public function test_a_type_with_no_choice_and_no_default_link_adds_no_part(): void
    {
        $this->createType($this->kitId);

        $this->assertSame([], $this->kitParts->resolve($this->kitId, []));
    }

    public function test_a_chosen_value_replaces_the_types_default_product(): void
    {
        $single = $this->createTestProduct();
        $double = $this->createTestProduct();
        $typeId = $this->createType($this->kitId, defaultLinked: $single);
        $valueId = $this->createValue($typeId, linked: $double);

        $this->assertSame([$this->part($double, $valueId)], $this->kitParts->resolve($this->kitId, [$valueId]));
    }

    public function test_a_chosen_value_with_no_link_still_replaces_the_default_product(): void
    {
        $single = $this->createTestProduct();
        $typeId = $this->createType($this->kitId, defaultLinked: $single);
        $valueId = $this->createValue($typeId);

        $this->assertSame([], $this->kitParts->resolve($this->kitId, [$valueId]));
    }

    public function test_several_types_combine_in_type_order(): void
    {
        $gland = $this->createTestProduct();
        $battery = $this->createTestProduct();
        $this->createType($this->kitId, sort: 2, defaultLinked: $gland);
        $batteryType = $this->createType($this->kitId, sort: 1);
        $batteryValue = $this->createValue($batteryType, linked: $battery);

        $this->assertSame(
            [$this->part($battery, $batteryValue), $this->part($gland, null)],
            $this->kitParts->resolve($this->kitId, [$batteryValue]),
        );
    }

    public function test_a_dependent_type_only_counts_when_its_trigger_value_is_chosen(): void
    {
        $monitor = $this->createTestProduct();
        $batteryType = $this->createType($this->kitId, sort: 1);
        $trigger = $this->createValue($batteryType);
        $other = $this->createValue($batteryType);
        $monitorType = $this->createType($this->kitId, sort: 2, defaultLinked: $monitor);
        $this->dependOn($monitorType, $trigger);

        $this->assertSame([], $this->kitParts->resolve($this->kitId, [$other]), 'A hidden type took stock for a product the customer was never offered');
        $this->assertSame([$this->part($monitor, null)], $this->kitParts->resolve($this->kitId, [$trigger]));
    }

    public function test_values_from_another_products_types_are_ignored(): void
    {
        $battery = $this->createTestProduct();
        $otherKit = $this->createTestProduct();
        $foreignType = $this->createType($otherKit);
        $foreignValue = $this->createValue($foreignType, linked: $battery);

        $this->assertSame([], $this->kitParts->resolve($this->kitId, [$foreignValue]));
    }

    public function test_a_deleted_type_is_ignored(): void
    {
        $gland = $this->createTestProduct();
        $this->createType($this->kitId, defaultLinked: $gland, deleted: true);

        $this->assertSame([], $this->kitParts->resolve($this->kitId, []));
    }
}
