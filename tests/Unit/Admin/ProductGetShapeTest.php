<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ProductMcp;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ProductMcp.php';

final class ProductGetShapeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(array $over = []): array
    {
        return $over + [
            'id' => '9', 'reference' => 'AB-1', 'model_name' => '100W Panel', 'brand_name' => 'Acme', 'status_name' => 'In stock',
            'available' => '1', 'restocking' => '0', 'restock_at' => null, 'price_exc_tax' => '120.000', 'rrp_exc_tax' => '150.00',
            'descr_length' => '320', 'descr2_length' => '0', 'descr3_length' => '0',
            'descr_image_set' => '1', 'descr2_image_set' => '0', 'descr3_image_set' => '0',
            'faq_length' => '0', 'techspec_length' => '80', 'video_set' => '0', 'barcode_set' => '1',
            'features' => '3,4', 'accessories' => '', 'alternatives' => ' 7 ',
            'created_at' => '2026-10-01 10:00:00', 'updated_at' => null,
        ];
    }

    public function test_the_name_status_price_and_flags_are_shaped(): void
    {
        $out = ProductMcp::shapeAudit($this->row(), [['id' => '2', 'label' => 'Panels']], ['url' => '/shop/acme/100w-panel/', 'title' => 'T', 'description' => 'D', 'robots' => 'index,follow'], ['large' => true, 'medium' => true, 'thumb' => false, 'webp' => false, 'avif' => false]);

        $this->assertSame('Acme 100W Panel', $out['name']);
        $this->assertSame(120.0, $out['price_exc_tax']);
        $this->assertFalse($out['price_is_zero']);
        $this->assertTrue($out['available']);
        $this->assertSame([['id' => 2, 'label' => 'Panels']], $out['categories']);
        $this->assertSame('T', $out['page']['seo_title']);
        $this->assertSame([320, 0, 0], $out['content']['description_lengths']);
        $this->assertSame([true, false, false], $out['content']['description_images_set']);
        $this->assertTrue($out['main_image']['large']);
    }

    public function test_linked_ids_are_counted_and_blanks_are_ignored(): void
    {
        $content = ProductMcp::shapeAudit($this->row(), [], null, null)['content'];

        $this->assertSame(2, $content['features_linked']);
        $this->assertSame(0, $content['accessories_linked']);
        $this->assertSame(1, $content['alternatives_linked']);
    }

    public function test_a_zero_price_is_flagged_and_a_missing_page_or_image_check_is_null(): void
    {
        $out = ProductMcp::shapeAudit($this->row(['price_exc_tax' => '0.000']), [], null, null);

        $this->assertTrue($out['price_is_zero']);
        $this->assertNull($out['page']);
        $this->assertNull($out['main_image']);
    }
}
