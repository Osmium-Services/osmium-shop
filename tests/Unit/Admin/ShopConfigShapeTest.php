<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\ShopReportsMcp;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/ShopReportsMcp.php';

final class ShopConfigShapeTest extends TestCase
{
    private const SHOP = [
        'currency' => 'GBP', 'taxRatePercent' => '20', 'taxName' => 'VAT', 'country' => 'GB', 'searchEnabled' => true,
        'basketAbandonedHours' => 2, 'orderRefPrefix' => 'ORD-', 'deliveryMessage' => 'TBD_DELIVERY_MESSAGE',
        'orderConfirmationNote' => 'Thanks for ordering, secret text',
        'customerNotifications' => ['order_paid' => ['email' => true, 'sms' => false]],
    ];
    private const CHECKOUT = [
        'deliveryCharge' => 4.5, 'freeDeliveryThreshold' => 100, 'deliverySurchargeHighlands' => 10.0,
        'deliverySurchargePostcodes' => ['IV', 'KW', 'PH'], 'deliveryExcludedPostcodes' => 'TBD_UK_MAINLAND_ONLY_POSTCODE_LIST',
        'paymentProvider' => 'stripe',
    ];

    public function test_lists_become_counts_and_placeholders_count_as_not_set(): void
    {
        $out = ShopReportsMcp::shapeConfig(self::SHOP, self::CHECKOUT, [], 'none');

        $this->assertSame(3, $out['delivery']['surcharge_postcodes']);
        $this->assertSame(0, $out['delivery']['excluded_postcodes']);
        $this->assertFalse($out['shop']['delivery_message_set']);
        $this->assertTrue($out['shop']['order_confirmation_note_set']);
        $this->assertSame(100.0, $out['delivery']['free_delivery_threshold']);
    }

    public function test_a_comma_list_of_postcodes_is_counted(): void
    {
        $out = ShopReportsMcp::shapeConfig(self::SHOP, ['deliveryExcludedPostcodes' => 'BT, GY ,JE,'] + self::CHECKOUT, [], 'none');

        $this->assertSame(3, $out['delivery']['excluded_postcodes']);
    }

    public function test_the_chosen_provider_is_ready_only_when_the_service_says_so(): void
    {
        $offered = ['stripe' => ['id' => 'stripe', 'label' => 'Stripe', 'ready' => true, 'frontend' => ['secret' => 'sk_live_x']]];

        $this->assertTrue(ShopReportsMcp::shapeConfig(self::SHOP, self::CHECKOUT, $offered, 'stripe')['payment']['chosen_ready']);
        $this->assertFalse(ShopReportsMcp::shapeConfig(self::SHOP, self::CHECKOUT, $offered, 'none')['payment']['chosen_ready']);
        $offered['stripe']['ready'] = false;
        $this->assertFalse(ShopReportsMcp::shapeConfig(self::SHOP, self::CHECKOUT, $offered, 'stripe')['payment']['chosen_ready']);
    }

    public function test_no_free_text_credential_or_frontend_data_reaches_the_answer(): void
    {
        $offered = ['stripe' => ['id' => 'stripe', 'label' => 'Stripe', 'ready' => true, 'frontend' => ['secret' => 'sk_live_x'], 'settingsRoute' => '/admin/x']];
        $json = \json_encode(ShopReportsMcp::shapeConfig(self::SHOP, self::CHECKOUT, $offered, 'stripe', [['label' => 'Hosted Cart', 'settingsRoute' => '/y']]));

        foreach (['secret', 'sk_live_x', '/admin/x', 'IV', 'TBD_'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertStringContainsString('Hosted Cart', $json);
    }

    public function test_notification_switches_are_reported_per_event(): void
    {
        $out = ShopReportsMcp::shapeConfig(self::SHOP, self::CHECKOUT, [], 'none');

        $this->assertSame(['order_paid' => ['email' => true, 'sms' => false]], $out['order_notifications']);
    }
}
