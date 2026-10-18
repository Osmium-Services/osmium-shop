<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shop\Services;

use Osmium\Modules\Shop\Services\ShopKitAvailability;
use PHPUnit\Framework\TestCase;

/**
 * When a kit as a whole can be bought, and when it is back, worked out from the
 * stock status of the products behind each option type's choices.
 */
final class ShopKitAvailabilityTest extends TestCase
{
    private const TODAY = '2026-10-10';

    private ShopKitAvailability $kit;

    protected function setUp(): void
    {
        $this->kit = new ShopKitAvailability();
    }

    /** A default choice with no product behind it: always available. */
    private function type(array $values = [], array $default = [], array $typeLink = [], array $dependsOn = []): array
    {
        return [
            'id' => \random_int(1, 1_000_000),
            'dependsOnValueIds' => $dependsOn,
            'linkedProductId' => $typeLink['id'] ?? null,
            'linkedProductAvailable' => $typeLink['available'] ?? true,
            'linkedProductRestockDate' => $typeLink['date'] ?? null,
            'defaultLinkedProductId' => $default['id'] ?? null,
            'defaultAvailable' => $default['available'] ?? true,
            'defaultRestockDate' => $default['date'] ?? null,
            'values' => $values,
        ];
    }

    /** A choice backed by a product. */
    private function choice(bool $available, ?string $date = null, bool $linked = true): array
    {
        return [
            'linkedProductId' => $linked ? 99 : null,
            'linkedProductAvailable' => $available,
            'linkedProductRestockDate' => $date,
        ];
    }

    private function outOfStockDefault(?string $date = null): array
    {
        return ['id' => 50, 'available' => false, 'date' => $date];
    }

    public function test_a_product_with_no_options_is_available(): void
    {
        $result = $this->kit->evaluate([], self::TODAY);

        $this->assertTrue($result['available']);
        $this->assertNull($result['restockDate']);
    }

    public function test_a_kit_whose_choices_are_all_in_stock_is_available(): void
    {
        $types = [$this->type(values: [$this->choice(true)], default: ['id' => 50, 'available' => true])];

        $this->assertTrue($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_an_out_of_stock_default_is_fine_while_another_choice_is_available(): void
    {
        $types = [$this->type(values: [$this->choice(true)], default: $this->outOfStockDefault())];

        $this->assertTrue($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_a_type_with_every_choice_out_of_stock_makes_the_kit_unavailable(): void
    {
        $types = [$this->type(values: [$this->choice(false)], default: $this->outOfStockDefault())];

        $this->assertFalse($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_one_blocked_type_is_enough_even_if_the_others_are_fine(): void
    {
        $types = [
            $this->type(values: [$this->choice(true)]),
            $this->type(values: [$this->choice(false)], default: $this->outOfStockDefault()),
        ];

        $this->assertFalse($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_a_choice_with_no_product_behind_it_counts_as_available(): void
    {
        $types = [$this->type(values: [$this->choice(false, linked: false)], default: $this->outOfStockDefault())];

        $this->assertTrue($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_a_default_with_no_product_behind_it_can_never_block(): void
    {
        $types = [$this->type(values: [$this->choice(false)])];

        $this->assertTrue($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_an_out_of_stock_type_link_disables_its_choices_but_not_the_default(): void
    {
        $types = [$this->type(values: [$this->choice(true)], typeLink: ['id' => 7, 'available' => false])];

        $this->assertTrue($this->kit->evaluate($types, self::TODAY)['available'], 'The default was available, so the kit can still be bought');
    }

    public function test_an_out_of_stock_type_link_blocks_the_kit_when_the_default_is_out_too(): void
    {
        $types = [$this->type(values: [$this->choice(true)], default: $this->outOfStockDefault(), typeLink: ['id' => 7, 'available' => false])];

        $this->assertFalse($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_a_type_that_only_shows_after_another_choice_never_blocks_the_kit(): void
    {
        $types = [$this->type(values: [$this->choice(false)], default: $this->outOfStockDefault(), dependsOn: [123])];

        $this->assertTrue($this->kit->evaluate($types, self::TODAY)['available']);
    }

    public function test_the_restock_date_is_the_earliest_among_the_blocked_types_choices(): void
    {
        $types = [$this->type(
            values: [$this->choice(false, '2026-11-05')],
            default: $this->outOfStockDefault('2026-11-01'),
        )];

        $this->assertSame('2026-11-01', $this->kit->evaluate($types, self::TODAY)['restockDate']);
    }

    public function test_with_two_blocked_types_the_kit_is_back_when_the_later_one_is(): void
    {
        $types = [
            $this->type(default: $this->outOfStockDefault('2026-11-01')),
            $this->type(default: $this->outOfStockDefault('2026-12-01')),
        ];

        $this->assertSame('2026-12-01', $this->kit->evaluate($types, self::TODAY)['restockDate']);
    }

    public function test_a_blocked_type_with_no_date_means_the_kit_has_no_date(): void
    {
        $types = [
            $this->type(default: $this->outOfStockDefault('2026-11-01')),
            $this->type(default: $this->outOfStockDefault(null)),
        ];

        $this->assertNull($this->kit->evaluate($types, self::TODAY)['restockDate']);
    }

    public function test_a_date_in_the_past_is_not_promised(): void
    {
        $types = [$this->type(default: $this->outOfStockDefault('2026-09-01'))];

        $result = $this->kit->evaluate($types, self::TODAY);

        $this->assertFalse($result['available']);
        $this->assertNull($result['restockDate']);
    }

    public function test_today_counts_as_a_usable_date(): void
    {
        $types = [$this->type(default: $this->outOfStockDefault(self::TODAY))];

        $this->assertSame(self::TODAY, $this->kit->evaluate($types, self::TODAY)['restockDate']);
    }

    public function test_a_choice_held_back_by_two_products_is_back_when_the_later_one_is(): void
    {
        $types = [$this->type(
            values: [$this->choice(false, '2026-11-01')],
            default: $this->outOfStockDefault('2026-12-15'),
            typeLink: ['id' => 7, 'available' => false, 'date' => '2026-11-20'],
        )];

        // Default back 15 Dec. The value is held back by the value link (1 Nov) and the type link (20 Nov): back 20 Nov.
        $this->assertSame('2026-11-20', $this->kit->evaluate($types, self::TODAY)['restockDate']);
    }

    public function test_a_choice_with_an_undated_blocker_is_skipped_when_another_choice_has_a_date(): void
    {
        $types = [$this->type(
            values: [$this->choice(false, null)],
            default: $this->outOfStockDefault('2026-11-10'),
        )];

        $this->assertSame('2026-11-10', $this->kit->evaluate($types, self::TODAY)['restockDate']);
    }

    public function test_it_reports_which_types_are_blocking(): void
    {
        $blocked = $this->type(default: $this->outOfStockDefault());
        $fine = $this->type(values: [$this->choice(true)]);

        $this->assertSame([$blocked['id']], $this->kit->evaluate([$fine, $blocked], self::TODAY)['blockingTypeIds']);
    }

    private function productData(int $available = 1, ?string $restockDate = null, string $schemaRef = 'InStock'): array
    {
        return ['isAvailable' => $available, 'restockDate' => $restockDate, 'schemaRef' => $schemaRef];
    }

    public function test_an_available_kit_leaves_the_product_data_alone_and_says_so(): void
    {
        $kit = ['available' => true, 'restockDate' => null, 'blockingTypeIds' => []];

        $result = $this->kit->applyTo($this->productData(), $kit);

        $this->assertSame(1, $result['isAvailable']);
        $this->assertSame('InStock', $result['schemaRef']);
        $this->assertTrue($result['kitAvailable']);
        $this->assertNull($result['kitRestockDate']);
    }

    public function test_a_blocked_kit_shows_as_out_of_stock_with_the_kits_restock_date(): void
    {
        $kit = ['available' => false, 'restockDate' => '2026-12-01', 'blockingTypeIds' => [3]];

        $result = $this->kit->applyTo($this->productData(), $kit);

        $this->assertSame(0, $result['isAvailable']);
        $this->assertSame('2026-12-01', $result['restockDate']);
        $this->assertSame('OutOfStock', $result['schemaRef']);
        $this->assertFalse($result['kitAvailable']);
        $this->assertSame('2026-12-01', $result['kitRestockDate']);
    }

    public function test_a_product_already_out_of_stock_keeps_its_own_status_and_date(): void
    {
        $kit = ['available' => false, 'restockDate' => '2026-12-01', 'blockingTypeIds' => [3]];

        $result = $this->kit->applyTo($this->productData(available: 0, restockDate: '2026-11-01', schemaRef: 'BackOrder'), $kit);

        $this->assertSame('2026-11-01', $result['restockDate']);
        $this->assertSame('BackOrder', $result['schemaRef']);
        $this->assertFalse($result['kitAvailable']);
    }
}
