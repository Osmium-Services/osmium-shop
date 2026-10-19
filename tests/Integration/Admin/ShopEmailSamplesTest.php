<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use Osmium\Modules\Checkout\Services\ShopEmailSamples;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use PHPUnit\Framework\TestCase;

require_once 'app/core/Osmium.php';
require_once 'app/services/osmium-shop/models/ShopEmailSamples.php';

/**
 * The shop's sample emails for the admin Email settings test tool. The admin mailer is a recording stand-in, so
 * nothing is sent. (The sample order confirmation builds the real checkout mailer and is left to a live send.)
 */
class ShopEmailSamplesTest extends TestCase
{
    public function test_the_service_offers_both_order_emails_under_a_shop_heading(): void
    {
        $samples = ShopEmailSamples::samples([]);

        $this->assertSame(['order_confirmation', 'order_dispatched'], \array_column($samples, 'id'));
        $this->assertSame(['Shop'], \array_values(\array_unique(\array_column($samples, 'group'))));
        foreach ($samples as $sample) {
            $this->assertIsCallable(\explode('::', $sample['handler']), $sample['id']);
        }
    }

    public function test_the_sample_dispatch_email_uses_dummy_order_details_and_ignores_the_notification_switches(): void
    {
        $mailer = new class extends ShopOrderMailer {
            public array $calls = [];

            public function __construct() {}

            public function sendOrderDispatchedEmail(string $toEmail, string $customerName, string $orderRef, ?string $carrier, ?string $trackingRef, bool $respectSettings = true): array
            {
                $this->calls[] = [$toEmail, $customerName, $orderRef, $carrier, $trackingRef, $respectSettings];

                return ['success' => true];
            }
        };

        $result = ShopEmailSamples::orderDispatched(['toEmail' => 'to@example.test', 'osmium' => new \stdClass()], $mailer);

        $this->assertSame(['success' => true], $result);
        $this->assertSame(
            [['to@example.test', 'Jane Doe', 'SG-SAMPLE', 'Royal Mail Tracked 24', 'RM123456789GB', false]],
            $mailer->calls,
        );
    }
}
