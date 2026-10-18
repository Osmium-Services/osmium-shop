<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * Decides whether a typed coupon code may be applied to the basket.
 */
class BasketCouponCheck
{
    /**
     * @param string $code The code as typed (trimmed here)
     * @param callable|null $lookup Finds an active coupon by code, or null when the payment
     *                              provider does not support discounts
     * @return string|null The message to show the customer, or null when the code may be applied
     */
    public static function error(string $code, ?callable $lookup): ?string
    {
        $noCode = \trim($code) === '';
        if ($noCode) return 'Please enter a coupon code.';

        $noDiscountsSupport = $lookup === null;
        if ($noDiscountsSupport) return 'Coupon codes are not available at the moment.';

        $codeInvalid = $lookup(\trim($code)) === null;
        if ($codeInvalid) return "That code isn't valid or has expired.";

        return null;
    }
}
