<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * Decides what a provider's confirmation of a payment means for an order.
 *
 * Provider services answer payment.confirm in one flat shape (amount,
 * currency, status); this compares that answer with what the order says is
 * owed. The order of the checks matters: a wrong amount is a mismatch even
 * if the provider also says pending, because money that does not match the
 * order needs a human before anyone is told "paid" or "try again".
 */
class ShopCaptureCheck
{
    public const MISMATCH = 'mismatch';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const COMPLETED = 'completed';

    /** Amounts are money to the penny; anything under half a penny is float noise. */
    private const AMOUNT_TOLERANCE = 0.005;

    /**
     * @param array $order The stored order (total_inc_tax, currency)
     * @param array $payment The provider's normalised answer (amount, currency, status)
     * @return string One of the class constants
     */
    public static function outcome(array $order, array $payment): string
    {
        if (self::amountOrCurrencyDiffers($order, $payment)) return self::MISMATCH;

        $status = (string)($payment['status'] ?? '');

        // Pending is not a failure: card payments sit there often and usually
        // complete moments later, when the webhook marks the order paid.
        // Reporting it as a failure would invite a second payment.
        if ($status === 'pending') return self::PENDING;

        $completed = $status === 'completed';

        return $completed ? self::COMPLETED : self::FAILED;
    }

    /** True when the captured money is not the order's total in the order's currency. */
    public static function amountOrCurrencyDiffers(array $order, array $payment): bool
    {
        $amountDiffers = \abs((float)($payment['amount'] ?? 0) - (float)($order['total_inc_tax'] ?? 0)) >= self::AMOUNT_TOLERANCE;
        $currencyDiffers = (string)($payment['currency'] ?? '') !== (string)($order['currency'] ?? '');

        return $amountDiffers || $currencyDiffers;
    }
}
