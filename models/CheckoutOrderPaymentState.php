<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * What a stored order's status means for a customer pressing "confirm payment".
 *
 * Only an order that really was paid may be answered "already paid, success";
 * telling someone whose order failed, was cancelled or refunded that it
 * succeeded would send them to a thank-you page for money never taken.
 */
class CheckoutOrderPaymentState
{
    public const AWAITING = 'awaiting';
    public const PAID = 'paid';
    public const CLOSED = 'closed';

    public const CLOSED_MESSAGE = 'This order can no longer be paid. Please contact us if you need help.';

    public static function of(string $status): string
    {
        if ($status === 'pending') return self::AWAITING;

        $paid = $status === 'paid' || $status === 'dispatched';

        return $paid ? self::PAID : self::CLOSED;
    }
}
