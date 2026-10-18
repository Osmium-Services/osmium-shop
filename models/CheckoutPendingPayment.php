<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * Decides whether this browser session may confirm the payment it names.
 *
 * create_order stores the order id and the provider's reference in the
 * session; confirmation must present the same reference, so one customer's
 * session cannot confirm, or probe, another customer's order.
 */
class CheckoutPendingPayment
{
    public const NO_ORDER = 'We could not find your order. Please contact us before trying again.';
    public const NO_MATCH = 'We could not match that payment to your order. Please contact us before trying again.';

    /**
     * @param mixed $sessionOrderId The pending order id held in the session
     * @param mixed $sessionRef The provider reference held in the session
     * @param string $submittedRef The reference the browser sent
     * @return string|null The customer-facing error, or null when the session may confirm
     */
    public static function error(mixed $sessionOrderId, mixed $sessionRef, string $submittedRef): ?string
    {
        $orderId = (int)($sessionOrderId ?? 0);
        $expectedRef = (string)($sessionRef ?? '');

        $noPendingOrder = $orderId < 1 || $expectedRef === '';
        if ($noPendingOrder) return self::NO_ORDER;

        $refMismatch = !\hash_equals(known_string: $expectedRef, user_string: $submittedRef);
        if ($refMismatch) return self::NO_MATCH;

        return null;
    }
}
