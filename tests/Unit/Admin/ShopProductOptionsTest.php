<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopProductOptions;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopProductOptions.php';

final class ShopProductOptionsTest extends TestCase
{
    public function test_values_sit_under_their_type_and_a_type_with_no_values_is_kept(): void
    {
        $rows = [
            ['product_id' => '14', 'type_id' => '1', 'type_label' => 'Units', 'default_option_descr' => 'Single Panel', 'value_id' => '5', 'option_descr' => '5 Panel Pack', 'option_premium' => '235.8300', 'option_reference' => 'SP40-5X'],
            ['product_id' => '36', 'type_id' => '72', 'type_label' => 'Remote display', 'default_option_descr' => '', 'value_id' => null, 'option_descr' => null, 'option_premium' => null, 'option_reference' => null],
        ];

        $options = ShopProductOptions::byProduct($rows);

        $this->assertSame([['id' => 5, 'label' => '5 Panel Pack', 'premium' => 235.83, 'reference' => 'SP40-5X']], $options[14][0]['values']);
        $this->assertSame('Single Panel', $options[14][0]['defaultLabel']);
        $this->assertNull($options[36][0]['defaultLabel']);
        $this->assertSame([], $options[36][0]['values']);
    }
}
