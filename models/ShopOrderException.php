<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * Thrown when an order cannot be created.
 *
 * The message is written for the customer and can be shown as-is. Anything
 * that should not be shown to a customer belongs in the log instead.
 */
class ShopOrderException extends \RuntimeException
{
}
