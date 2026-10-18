<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\CheckoutPendingPayment;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/CheckoutPendingPayment.php';

/**
 * Whether a browser session may confirm the payment it names: the guard that
 * stops one customer confirming another's order.
 */
class CheckoutPendingPaymentTest extends TestCase
{
    public function test_the_matching_reference_may_confirm(): void
    {
        $this->assertNull(CheckoutPendingPayment::error(sessionOrderId: 12, sessionRef: 'pi_abc', submittedRef: 'pi_abc'));
    }

    public function test_a_different_reference_is_not_matched(): void
    {
        $this->assertSame(
            CheckoutPendingPayment::NO_MATCH,
            CheckoutPendingPayment::error(sessionOrderId: 12, sessionRef: 'pi_abc', submittedRef: 'pi_xyz'),
        );
    }

    public function test_an_empty_submitted_reference_is_not_matched(): void
    {
        $this->assertSame(
            CheckoutPendingPayment::NO_MATCH,
            CheckoutPendingPayment::error(sessionOrderId: 12, sessionRef: 'pi_abc', submittedRef: ''),
        );
    }

    public function test_no_order_in_the_session_means_order_not_found(): void
    {
        $this->assertSame(CheckoutPendingPayment::NO_ORDER, CheckoutPendingPayment::error(sessionOrderId: null, sessionRef: 'pi_abc', submittedRef: 'pi_abc'));
        $this->assertSame(CheckoutPendingPayment::NO_ORDER, CheckoutPendingPayment::error(sessionOrderId: 0, sessionRef: 'pi_abc', submittedRef: 'pi_abc'));
    }

    public function test_no_reference_in_the_session_means_order_not_found_even_if_the_browser_sends_an_empty_one(): void
    {
        $this->assertSame(CheckoutPendingPayment::NO_ORDER, CheckoutPendingPayment::error(sessionOrderId: 12, sessionRef: null, submittedRef: ''));
        $this->assertSame(CheckoutPendingPayment::NO_ORDER, CheckoutPendingPayment::error(sessionOrderId: 12, sessionRef: '', submittedRef: ''));
    }

    public function test_the_reference_comparison_is_case_sensitive(): void
    {
        $this->assertSame(
            CheckoutPendingPayment::NO_MATCH,
            CheckoutPendingPayment::error(sessionOrderId: 12, sessionRef: 'pi_ABC', submittedRef: 'pi_abc'),
        );
    }

    public function test_a_numeric_string_order_id_from_the_session_is_accepted(): void
    {
        $this->assertNull(CheckoutPendingPayment::error(sessionOrderId: '12', sessionRef: 'pi_abc', submittedRef: 'pi_abc'));
    }
}
