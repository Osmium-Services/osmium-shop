<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Admin\Models\ProductOptionType;
use Osmium\Modules\Admin\Models\ProductOptionValue;
use Osmium\Modules\Admin\Services\AdminShopOptions;
use Tests\Integration\DatabaseTestCase;

/**
 * The link from an option type's default choice to a real product: what the
 * configurator saves, clears and copies, so the default's stock can be checked.
 */
class AdminShopOptionsDefaultLinkTest extends DatabaseTestCase
{
    private AdminShopOptions $options;
    private int $kitId;
    private int $glandId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = new AdminShopOptions(
            new ProductOptionType($this->db, 'productoptiontypes'),
            new ProductOptionValue($this->db, 'productoptionvalues'),
        );
        $this->kitId = $this->createTestProduct();
        $this->glandId = $this->createTestProduct();
    }

    private function onlyType(int $productId): array
    {
        $types = $this->options->getProductOptions($productId);
        $this->assertCount(expectedCount: 1, haystack: $types);

        return $types[0];
    }

    public function test_a_new_type_can_link_its_default_choice_to_a_product(): void
    {
        $this->options->addOptionType($this->kitId, [
            'type_label' => 'Gland',
            'default_option_descr' => 'Single Gland',
            'default_linked_product_id' => $this->glandId,
        ]);

        $this->assertSame($this->glandId, (int) $this->onlyType($this->kitId)['default_linked_product_id']);
    }

    public function test_a_type_with_no_default_link_stores_null(): void
    {
        $this->options->addOptionType($this->kitId, ['type_label' => 'Gland']);

        $this->assertNull($this->onlyType($this->kitId)['default_linked_product_id']);
    }

    public function test_the_default_link_can_be_set_then_cleared_on_an_existing_type(): void
    {
        $typeId = $this->options->addOptionType($this->kitId, ['type_label' => 'Gland'])['id'];

        $this->options->updateOptionType($typeId, ['default_linked_product_id' => $this->glandId]);
        $this->assertSame($this->glandId, (int) $this->onlyType($this->kitId)['default_linked_product_id']);

        $this->options->updateOptionType($typeId, ['default_linked_product_id' => '']);
        $this->assertNull($this->onlyType($this->kitId)['default_linked_product_id']);
    }

    public function test_the_default_link_is_independent_of_the_whole_type_link(): void
    {
        $other = $this->createTestProduct();
        $this->options->addOptionType($this->kitId, [
            'type_label' => 'Gland',
            'linked_product_id' => $other,
            'default_linked_product_id' => $this->glandId,
        ]);

        $type = $this->onlyType($this->kitId);
        $this->assertSame($other, (int) $type['linked_product_id']);
        $this->assertSame($this->glandId, (int) $type['default_linked_product_id']);
    }

    public function test_copying_options_to_another_product_keeps_the_default_link(): void
    {
        $this->options->addOptionType($this->kitId, [
            'type_label' => 'Gland',
            'default_linked_product_id' => $this->glandId,
        ]);
        $target = $this->createTestProduct();

        $this->options->copyOptionsFromProduct($this->kitId, $target);

        $this->assertSame($this->glandId, (int) $this->onlyType($target)['default_linked_product_id']);
    }
}
