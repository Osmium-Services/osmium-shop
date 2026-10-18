<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * Thrown when a payment provider call fails.
 *
 * This message is for the log, not the customer: it can carry provider
 * detail that means nothing to a buyer and occasionally something to an
 * attacker. The caller is expected to log this and show its own wording.
 */
class ShopPaymentException extends \RuntimeException
{
}
