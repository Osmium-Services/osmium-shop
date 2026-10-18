<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

require_once __DIR__ . '/ShopPaymentException.php';

/**
 * Reads a provider service's answer to payment.create.
 *
 * The reference it returns is what confirmation is later matched against, so
 * an answer without one must stop the checkout rather than be stored as an
 * empty reference that nothing could ever confirm.
 */
class CheckoutPaymentCreated
{
    /**
     * @param array|null $created The hook result (ref, optional client_secret, optional test_mode)
     * @return array{ref: string, client_secret: ?string, test_mode: bool}
     * @throws ShopPaymentException When no service answered, or the answer has no reference
     */
    public static function fromHook(?array $created, string $provider): array
    {
        $noHandler = $created === null;
        if ($noHandler) throw new ShopPaymentException("No service created a payment for provider '{$provider}'");

        $ref = (string)($created['ref'] ?? '');

        $noRef = $ref === '';
        if ($noRef) throw new ShopPaymentException("Provider '{$provider}' created a payment without a reference");

        $secret = $created['client_secret'] ?? null;

        return [
            'ref' => $ref,
            'client_secret' => $secret === null ? null : (string)$secret,
            'test_mode' => (bool)($created['test_mode'] ?? false),
        ];
    }
}
