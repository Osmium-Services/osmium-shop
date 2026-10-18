<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\CheckoutPaymentCreated;
use Osmium\Modules\Checkout\Services\ShopPaymentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/CheckoutPaymentCreated.php';

/**
 * What the checkout takes from a provider's answer to "start a payment".
 */
class CheckoutPaymentCreatedTest extends TestCase
{
    public function test_a_full_answer_is_read(): void
    {
        $result = CheckoutPaymentCreated::fromHook(
            created: ['ref' => 'pi_123', 'client_secret' => 'sec_9', 'test_mode' => true],
            provider: 'stripe',
        );

        $this->assertSame(['ref' => 'pi_123', 'client_secret' => 'sec_9', 'test_mode' => true], $result);
    }

    public function test_a_provider_with_no_client_secret_gives_null(): void
    {
        $result = CheckoutPaymentCreated::fromHook(created: ['ref' => 'ORDER-1'], provider: 'paypal');

        $this->assertNull($result['client_secret']);
        $this->assertFalse($result['test_mode']);
    }

    public function test_a_numeric_reference_becomes_a_string(): void
    {
        $result = CheckoutPaymentCreated::fromHook(created: ['ref' => 4521], provider: 'square');

        $this->assertSame('4521', $result['ref']);
    }

    public function test_no_service_answering_stops_the_checkout(): void
    {
        $this->expectException(ShopPaymentException::class);
        $this->expectExceptionMessage("No service created a payment for provider 'stripe'");

        CheckoutPaymentCreated::fromHook(created: null, provider: 'stripe');
    }

    public function test_an_answer_with_no_reference_stops_the_checkout(): void
    {
        $this->expectException(ShopPaymentException::class);
        $this->expectExceptionMessage('without a reference');

        CheckoutPaymentCreated::fromHook(created: ['client_secret' => 'sec_9'], provider: 'stripe');
    }

    public function test_an_empty_reference_stops_the_checkout(): void
    {
        $this->expectException(ShopPaymentException::class);

        CheckoutPaymentCreated::fromHook(created: ['ref' => ''], provider: 'stripe');
    }
}
