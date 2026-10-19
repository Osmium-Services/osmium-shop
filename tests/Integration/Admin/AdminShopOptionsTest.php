<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminShopOptions;
use Tests\Helpers\ShopDatabaseTestCase;

require_once 'app/services/osmium-shop/models/AdminShopOptions.php';

/**
 * Product options as the configurator saves them: types and their choices (what is stored, slugged, ordered and
 * cleared), the dependency links between a type and the choices that reveal it, deleting, reordering, and copying a
 * whole option set to another product, which replaces what the target had. Real models, rolled back.
 */
class AdminShopOptionsTest extends ShopDatabaseTestCase
{
    private AdminShopOptions $options;
    private int $productId;
    private string $runTag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runTag = \strtolower('zq' . \substr(\uniqid(), -6));
        $this->options = new AdminShopOptions(
            new ProductOptionType($this->db, 'productoptiontypes'),
            new ProductOptionValue($this->db, 'productoptionvalues'),
        );
        $this->brandId = $this->makeBrand();
        $this->productId = $this->product();
    }

    private int $brandId;

    private function makeBrand(): int
    {
        $this->db->insert(tableName: $this->db->tablePrefix() . 'productbrands', data: ['name' => "Brand {$this->runTag}"]);

        return (int) $this->db->lastInsertId();
    }

    private function product(string $model = ''): int
    {
        return $this->createTestProduct(['brand_id' => $this->brandId, 'model_name' => $model ?: "Model {$this->runTag} " . \uniqid()]);
    }

    /** @return array<int, array> the product's option types, by label */
    private function typesByLabel(int $productId): array
    {
        $byLabel = [];
        foreach ($this->options->getProductOptions($productId) as $type) {
            $byLabel[$type['type_label']] = $type;
        }

        return $byLabel;
    }

    private function table(string $name): string
    {
        return $this->db->tablePrefix() . $name;
    }

    private function rows(string $table, string $where, array $bindings = []): int
    {
        return (int) $this->db->prepare(sql: "SELECT COUNT(*) c FROM {$this->table($table)} WHERE {$where}", bindings: $bindings)->single()['c'];
    }

    private function expectMessage(callable $action, string $message): void
    {
        try {
            $action();
        } catch (\Exception $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail("Expected '{$message}'");
    }

    // ---- reading ------------------------------------------------------------------

    public function test_a_product_id_is_required_to_read_or_add(): void
    {
        $this->expectMessage(fn () => $this->options->getProductOptions(0), 'Product ID is required');
        $this->expectMessage(fn () => $this->options->addOptionType(0, ['type_label' => 'X']), 'Product ID is required');
    }

    public function test_options_come_back_grouped_by_type_in_order_without_the_deleted_ones(): void
    {
        $colour = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];
        $size = $this->options->addOptionType($this->productId, ['type_label' => 'Size'])['id'];
        $gone = $this->options->addOptionType($this->productId, ['type_label' => 'Gone'])['id'];
        $red = $this->options->addOptionValue($colour, ['option_descr' => 'Red']);
        $this->options->addOptionValue($colour, ['option_descr' => 'Blue']);
        $this->options->addOptionValue($size, ['option_descr' => 'Large']);
        $binned = $this->options->addOptionValue($colour, ['option_descr' => 'Binned']);
        $this->options->addOptionValue($gone, ['option_descr' => 'Hidden']);
        $this->options->deleteOptionValue($binned);
        $this->options->deleteOptionType($gone);

        $types = $this->options->getProductOptions($this->productId);

        $this->assertSame(['Colour', 'Size'], \array_column($types, 'type_label'));
        $this->assertSame(['Red', 'Blue'], \array_column($types[0]['values'], 'option_descr'), 'in the order they were added, the deleted one gone');
        $this->assertSame($red, (int) $types[0]['values'][0]['id']);
        $this->assertArrayNotHasKey('option_type_id', $types[0]['values'][0]);
        $this->assertSame(['Large'], \array_column($types[1]['values'], 'option_descr'));
        $this->assertSame([], $types[0]['depends_on_value_ids']);
    }

    public function test_another_products_options_never_show(): void
    {
        $other = $this->product();
        $this->options->addOptionType($other, ['type_label' => 'Theirs']);

        $this->assertSame([], $this->options->getProductOptions($this->productId));
    }

    // ---- option types -------------------------------------------------------------

    public function test_a_type_is_stored_with_a_url_safe_slug_and_the_next_sort_number(): void
    {
        $first = $this->options->addOptionType($this->productId, ['type_label' => '  Cable Length / Size!  ']);
        $second = $this->options->addOptionType($this->productId, ['type_label' => 'Second']);

        $this->assertSame('cable-length-size', $first['type_slug']);
        $types = $this->typesByLabel($this->productId);
        $this->assertSame(0, (int) $types['Cable Length / Size!']['sort']);
        $this->assertSame(1, (int) $types['Second']['sort']);
        $this->assertSame('cable-length-size', $types['Cable Length / Size!']['type_slug']);
        $this->assertGreaterThan($first['id'], $second['id']);
    }

    public function test_a_type_needs_a_label(): void
    {
        $this->expectMessage(fn () => $this->options->addOptionType($this->productId, ['type_label' => '   ']), 'Type label is required');
        $this->expectMessage(fn () => $this->options->addOptionType($this->productId, []), 'Type label is required');
    }

    public function test_a_type_keeps_its_links_to_products_and_image_slots_or_stores_null(): void
    {
        $linked = $this->product();

        $this->options->addOptionType($this->productId, ['type_label' => 'Linked', 'linked_product_id' => (string) $linked, 'linked_product_descr' => ' Pick one ', 'linked_image_slot' => ' extra2 ', 'default_option_descr' => ' Standard ']);
        $this->options->addOptionType($this->productId, ['type_label' => 'Plain', 'linked_product_id' => '0', 'linked_product_descr' => '  ', 'linked_image_slot' => '']);

        $types = $this->typesByLabel($this->productId);
        $this->assertSame($linked, (int) $types['Linked']['linked_product_id']);
        $this->assertSame('Pick one', $types['Linked']['linked_product_descr']);
        $this->assertSame('extra2', $types['Linked']['linked_image_slot']);
        $this->assertSame('Standard', $types['Linked']['default_option_descr']);
        $this->assertNull($types['Plain']['linked_product_id']);
        $this->assertNull($types['Plain']['linked_product_descr']);
        $this->assertNull($types['Plain']['linked_image_slot']);
    }

    public function test_a_type_can_be_renamed_and_its_slug_follows(): void
    {
        $id = $this->options->addOptionType($this->productId, ['type_label' => 'Old Name'])['id'];

        $this->options->updateOptionType($id, ['type_label' => ' New Name! ', 'default_option_descr' => ' Standard ', 'sort' => '7']);

        $type = $this->typesByLabel($this->productId)['New Name!'];
        $this->assertSame('new-name', $type['type_slug']);
        $this->assertSame('Standard', $type['default_option_descr']);
        $this->assertSame(7, (int) $type['sort']);
    }

    public function test_an_update_changes_only_what_it_is_given_and_a_blank_clears_a_link(): void
    {
        $linked = $this->product();
        $id = $this->options->addOptionType($this->productId, ['type_label' => 'Keep', 'linked_product_id' => $linked, 'linked_product_descr' => 'Words', 'linked_image_slot' => 'extra3'])['id'];

        $this->options->updateOptionType($id, ['default_option_descr' => 'Changed']);
        $kept = $this->typesByLabel($this->productId)['Keep'];
        $this->assertSame($linked, (int) $kept['linked_product_id'], 'not mentioned, not touched');
        $this->assertSame('Words', $kept['linked_product_descr']);

        $this->options->updateOptionType($id, ['linked_product_id' => '', 'linked_product_descr' => '  ', 'linked_image_slot' => null]);
        $cleared = $this->typesByLabel($this->productId)['Keep'];
        $this->assertNull($cleared['linked_product_id']);
        $this->assertNull($cleared['linked_product_descr']);
        $this->assertNull($cleared['linked_image_slot']);
        $this->assertSame('Changed', $cleared['default_option_descr']);
    }

    public function test_an_update_needs_an_id_and_something_to_change(): void
    {
        $id = $this->options->addOptionType($this->productId, ['type_label' => 'X'])['id'];

        $this->expectMessage(fn () => $this->options->updateOptionType(0, ['type_label' => 'Y']), 'Type ID is required');
        $this->expectMessage(fn () => $this->options->updateOptionType($id, []), 'No updates provided');
        $this->expectMessage(fn () => $this->options->updateOptionType($id, ['unknown' => 'x']), 'No updates provided');
    }

    // ---- dependencies -------------------------------------------------------------

    public function test_a_type_can_depend_on_choices_and_the_dependencies_are_replaced_wholesale(): void
    {
        $base = $this->options->addOptionType($this->productId, ['type_label' => 'Base'])['id'];
        $one = $this->options->addOptionValue($base, ['option_descr' => 'One']);
        $two = $this->options->addOptionValue($base, ['option_descr' => 'Two']);
        $three = $this->options->addOptionValue($base, ['option_descr' => 'Three']);

        $extra = $this->options->addOptionType($this->productId, ['type_label' => 'Extra', 'depends_on_value_ids' => [$one, (string) $two]])['id'];
        $this->assertEqualsCanonicalizing([$one, $two], $this->typesByLabel($this->productId)['Extra']['depends_on_value_ids']);

        $this->options->updateOptionType($extra, ['depends_on_value_ids' => [$three]]);
        $this->assertSame([$three], $this->typesByLabel($this->productId)['Extra']['depends_on_value_ids']);

        $this->options->updateOptionType($extra, ['default_option_descr' => 'X']);
        $this->assertSame([$three], $this->typesByLabel($this->productId)['Extra']['depends_on_value_ids'], 'an update that does not mention them leaves them');

        $this->options->updateOptionType($extra, ['depends_on_value_ids' => []]);
        $this->assertSame([], $this->typesByLabel($this->productId)['Extra']['depends_on_value_ids'], 'an empty list removes them');
    }

    public function test_deleting_a_type_removes_it_its_choices_and_every_dependency_on_them(): void
    {
        $base = $this->options->addOptionType($this->productId, ['type_label' => 'Base'])['id'];
        $value = $this->options->addOptionValue($base, ['option_descr' => 'Trigger']);
        $other = $this->options->addOptionType($this->productId, ['type_label' => 'Other', 'depends_on_value_ids' => [$value]])['id'];
        $dependent = $this->options->addOptionType($this->productId, ['type_label' => 'Dependent'])['id'];
        $keep = $this->options->addOptionValue($dependent, ['option_descr' => 'Keep']);
        $this->options->updateOptionType($other, ['depends_on_value_ids' => [$value, $keep]]);

        $this->options->deleteOptionType($base);

        $types = $this->typesByLabel($this->productId);
        $this->assertArrayNotHasKey('Base', $types);
        $this->assertSame([$keep], $types['Other']['depends_on_value_ids'], 'nothing is left waiting on a choice that no longer exists');
        $this->assertSame(1, $this->rows('productoptionvalues', 'option_type_id = :t AND deleted_at IS NOT NULL', [':t' => $base]), 'the choices are binned, not lost');
        $this->options->deleteOptionType($other);
        $this->assertSame(0, $this->rows('productoptiontype_dependencies', 'option_type_id = :t', [':t' => $other]));
    }

    public function test_deleting_a_single_choice_removes_the_dependencies_on_it(): void
    {
        $base = $this->options->addOptionType($this->productId, ['type_label' => 'Base'])['id'];
        $a = $this->options->addOptionValue($base, ['option_descr' => 'A']);
        $b = $this->options->addOptionValue($base, ['option_descr' => 'B']);
        $this->options->addOptionType($this->productId, ['type_label' => 'Needs', 'depends_on_value_ids' => [$a, $b]]);

        $this->options->deleteOptionValue($a);

        $this->assertSame([$b], $this->typesByLabel($this->productId)['Needs']['depends_on_value_ids']);
        $this->assertSame(['B'], \array_column($this->typesByLabel($this->productId)['Base']['values'], 'option_descr'));
    }

    public function test_deleting_needs_an_id(): void
    {
        $this->expectMessage(fn () => $this->options->deleteOptionType(0), 'Type ID is required');
        $this->expectMessage(fn () => $this->options->deleteOptionValue(0), 'Value ID is required');
    }

    // ---- option values ------------------------------------------------------------

    public function test_a_choice_is_stored_with_its_price_weight_and_next_sort_number(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];
        $linked = $this->product();

        $first = $this->options->addOptionValue($type, ['option_descr' => ' Red ', 'option_premium' => '12.50', 'option_rrp' => '20', 'option_packed_weight' => '1.25', 'option_reference' => ' RED-1 ', 'linked_product_id' => $linked, 'linked_image_slot' => ' extra4 ']);
        $this->options->addOptionValue($type, ['option_descr' => 'Plain']);

        $values = $this->typesByLabel($this->productId)['Colour']['values'];
        $this->assertSame($first, (int) $values[0]['id']);
        $this->assertSame('Red', $values[0]['option_descr']);
        $this->assertEqualsWithDelta(12.5, (float) $values[0]['option_premium'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $values[0]['option_rrp'], 0.001);
        $this->assertEqualsWithDelta(1.25, (float) $values[0]['option_packed_weight'], 0.001);
        $this->assertSame('RED-1', $values[0]['option_reference']);
        $this->assertSame($linked, (int) $values[0]['linked_product_id']);
        $this->assertSame('extra4', $values[0]['linked_image_slot']);
        $this->assertSame([0, 1], \array_map(fn ($v) => (int) $v['sort'], $values));
        $this->assertEqualsWithDelta(0.0, (float) $values[1]['option_premium'], 0.001, 'no price means no extra cost');
        $this->assertNull($values[1]['linked_product_id']);
        $this->assertNull($values[1]['linked_image_slot']);
    }

    public function test_a_choice_needs_a_type_and_a_description(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];

        $this->expectMessage(fn () => $this->options->addOptionValue(0, ['option_descr' => 'X']), 'Type ID is required');
        $this->expectMessage(fn () => $this->options->addOptionValue($type, ['option_descr' => '  ']), 'Option description is required');
        $this->expectMessage(fn () => $this->options->addOptionValue($type, []), 'Option description is required');
    }

    public function test_a_choice_update_changes_only_the_fields_sent_and_can_clear_its_links(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];
        $linked = $this->product();
        $id = $this->options->addOptionValue($type, ['option_descr' => 'Red', 'option_premium' => 5, 'option_packed_weight' => 2, 'linked_product_id' => $linked, 'linked_image_slot' => 'main']);

        $this->options->updateOptionValue($id, ['option_descr' => ' Crimson ', 'option_premium' => '7.5', 'option_rrp' => '9', 'option_reference' => ' C-1 ', 'sort' => '4']);
        $value = $this->typesByLabel($this->productId)['Colour']['values'][0];
        $this->assertSame('Crimson', $value['option_descr']);
        $this->assertEqualsWithDelta(7.5, (float) $value['option_premium'], 0.001);
        $this->assertEqualsWithDelta(9.0, (float) $value['option_rrp'], 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $value['option_packed_weight'], 0.001, 'not sent, not changed');
        $this->assertSame('C-1', $value['option_reference']);
        $this->assertSame(4, (int) $value['sort']);
        $this->assertSame($linked, (int) $value['linked_product_id']);

        $this->options->updateOptionValue($id, ['linked_product_id' => '', 'linked_image_slot' => ' ']);
        $cleared = $this->typesByLabel($this->productId)['Colour']['values'][0];
        $this->assertNull($cleared['linked_product_id']);
        $this->assertNull($cleared['linked_image_slot']);
    }

    public function test_an_unknown_image_slot_is_refused_on_types_and_choices(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];
        $id = $this->options->addOptionValue($type, ['option_descr' => 'Red']);

        foreach ([
            fn () => $this->options->addOptionType($this->productId, ['type_label' => 'X', 'linked_image_slot' => '"><script>']),
            fn () => $this->options->updateOptionType($type, ['linked_image_slot' => 'nope']),
            fn () => $this->options->addOptionValue($type, ['option_descr' => 'Blue', 'linked_image_slot' => '1" onload="x']),
            fn () => $this->options->updateOptionValue($id, ['linked_image_slot' => 'extra9']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('an unknown slot should be refused');
            } catch (\Exception $e) {
                $this->assertStringContainsString('image slot', \strtolower($e->getMessage()) . ' image slot');
            }
        }
    }

    public function test_a_reference_with_markup_characters_is_refused(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];
        $id = $this->options->addOptionValue($type, ['option_descr' => 'Red', 'option_reference' => 'R-1']);

        $this->expectException(\Exception::class);
        $this->options->updateOptionValue($id, ['option_reference' => '<img src=x onerror=alert(1)>']);
    }

    public function test_adding_a_choice_with_a_markup_reference_is_refused(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];

        $this->expectException(\Exception::class);
        $this->options->addOptionValue($type, ['option_descr' => 'Red', 'option_reference' => 'a"b']);
    }

    public function test_a_choice_update_needs_an_id_and_something_to_change(): void
    {
        $type = $this->options->addOptionType($this->productId, ['type_label' => 'Colour'])['id'];
        $id = $this->options->addOptionValue($type, ['option_descr' => 'Red']);

        $this->expectMessage(fn () => $this->options->updateOptionValue(0, ['option_descr' => 'X']), 'Value ID is required');
        $this->expectMessage(fn () => $this->options->updateOptionValue($id, []), 'No updates provided');
    }

    // ---- ordering -----------------------------------------------------------------

    public function test_types_and_choices_can_be_reordered(): void
    {
        $a = $this->options->addOptionType($this->productId, ['type_label' => 'A'])['id'];
        $b = $this->options->addOptionType($this->productId, ['type_label' => 'B'])['id'];
        $x = $this->options->addOptionValue($a, ['option_descr' => 'X']);
        $y = $this->options->addOptionValue($a, ['option_descr' => 'Y']);

        $this->options->reorderOptionTypes([['id' => $a, 'sort' => 1], ['id' => (string) $b, 'sort' => '0']]);
        $this->options->reorderOptionValues([['id' => $x, 'sort' => 1], ['id' => $y, 'sort' => 0]]);

        $types = $this->options->getProductOptions($this->productId);
        $this->assertSame(['B', 'A'], \array_column($types, 'type_label'));
        $this->assertSame(['Y', 'X'], \array_column($types[1]['values'], 'option_descr'));
    }

    public function test_reordering_with_nothing_to_reorder_is_refused(): void
    {
        $this->expectMessage(fn () => $this->options->reorderOptionTypes([]), 'No items provided');
        $this->expectMessage(fn () => $this->options->reorderOptionValues([]), 'No items provided');
    }

    // ---- copying ------------------------------------------------------------------

    public function test_the_copy_list_has_products_with_live_options_only(): void
    {
        $withOptions = $this->product('With');
        $onlyDeleted = $this->product('Deleted type');
        $binnedProduct = $this->product('Binned product');
        $this->options->addOptionType($withOptions, ['type_label' => 'Colour']);
        $deletedType = $this->options->addOptionType($onlyDeleted, ['type_label' => 'Gone'])['id'];
        $this->options->deleteOptionType($deletedType);
        $this->options->addOptionType($binnedProduct, ['type_label' => 'Colour']);
        $this->db->prepare(sql: "UPDATE {$this->table('products')} SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $binnedProduct])->execute();

        $ids = \array_map(fn ($p) => (int) $p['id'], $this->options->getProductsWithOptions());

        $this->assertContains($withOptions, $ids);
        $this->assertNotContains($onlyDeleted, $ids);
        $this->assertNotContains($binnedProduct, $ids);
    }

    public function test_copying_brings_over_types_choices_and_dependencies_pointing_at_the_new_choices(): void
    {
        $source = $this->product('Source');
        $target = $this->product('Target');
        $colour = $this->options->addOptionType($source, ['type_label' => 'Colour', 'default_option_descr' => 'Red', 'linked_image_slot' => 'extra2'])['id'];
        $red = $this->options->addOptionValue($colour, ['option_descr' => 'Red', 'option_premium' => 3, 'option_rrp' => 4, 'option_packed_weight' => 0.5, 'option_reference' => 'R']);
        $blue = $this->options->addOptionValue($colour, ['option_descr' => 'Blue']);
        $extra = $this->options->addOptionType($source, ['type_label' => 'Extra', 'depends_on_value_ids' => [$red, $blue]])['id'];
        $this->options->addOptionValue($extra, ['option_descr' => 'Gold']);
        $binned = $this->options->addOptionValue($colour, ['option_descr' => 'Binned']);
        $this->options->deleteOptionValue($binned);

        $result = $this->options->copyOptionsFromProduct($source, $target);

        $this->assertSame(['types_copied' => 2, 'values_copied' => 3], $result, 'the binned choice is not copied');
        $copied = $this->typesByLabel($target);
        $this->assertSame('Red', $copied['Colour']['default_option_descr']);
        $this->assertSame('extra2', $copied['Colour']['linked_image_slot']);
        $this->assertSame(['Red', 'Blue'], \array_column($copied['Colour']['values'], 'option_descr'));
        $this->assertEqualsWithDelta(3.0, (float) $copied['Colour']['values'][0]['option_premium'], 0.001);
        $this->assertSame('R', $copied['Colour']['values'][0]['option_reference']);
        $newIds = \array_map(fn ($v) => (int) $v['id'], $copied['Colour']['values']);
        $this->assertEqualsCanonicalizing($newIds, $copied['Extra']['depends_on_value_ids'], 'the copy depends on its own choices');
        $this->assertNotContains($red, $copied['Extra']['depends_on_value_ids']);
        $this->assertCount(2, $this->options->getProductOptions($source), 'the source is untouched');
        $this->assertEqualsCanonicalizing([$red, $blue], $this->typesByLabel($source)['Extra']['depends_on_value_ids']);
    }

    public function test_copying_replaces_what_the_target_had_including_its_dependencies(): void
    {
        $source = $this->product('Source');
        $target = $this->product('Target');
        $this->options->addOptionValue($this->options->addOptionType($source, ['type_label' => 'New'])['id'], ['option_descr' => 'Fresh']);
        $oldBase = $this->options->addOptionType($target, ['type_label' => 'Old'])['id'];
        $oldValue = $this->options->addOptionValue($oldBase, ['option_descr' => 'Stale']);
        $oldDependent = $this->options->addOptionType($target, ['type_label' => 'OldDep', 'depends_on_value_ids' => [$oldValue]])['id'];

        $this->options->copyOptionsFromProduct($source, $target);

        $this->assertSame(['New'], \array_keys($this->typesByLabel($target)));
        $this->assertSame(0, $this->rows('productoptiontypes', 'product_id = :p AND type_label IN (\'Old\', \'OldDep\')', [':p' => $target]), 'replaced for good, not binned');
        $this->assertSame(0, $this->rows('productoptionvalues', 'id = :v', [':v' => $oldValue]));
        $this->assertSame(0, $this->rows('productoptiontype_dependencies', 'option_type_id = :t', [':t' => $oldDependent]));
    }

    public function test_copying_needs_two_different_products(): void
    {
        $a = $this->product();

        $this->expectMessage(fn () => $this->options->copyOptionsFromProduct(0, $a), 'Source product ID required');
        $this->expectMessage(fn () => $this->options->copyOptionsFromProduct($a, 0), 'Target product ID required');
        $this->expectMessage(fn () => $this->options->copyOptionsFromProduct($a, $a), 'Cannot copy to same product');
    }

    public function test_copying_from_a_product_with_no_options_empties_the_target(): void
    {
        $source = $this->product('Empty source');
        $target = $this->product('Target');
        $this->options->addOptionType($target, ['type_label' => 'Existing']);

        $result = $this->options->copyOptionsFromProduct($source, $target);

        $this->assertSame(['types_copied' => 0, 'values_copied' => 0], $result);
        $this->assertSame([], $this->options->getProductOptions($target), 'the target is cleared before the source is read');
    }
}
