<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * The session CSRF token check shared by the basket and checkout endpoints.
 */
class CheckoutCsrf
{
    /** True only when both tokens are non-empty and identical. */
    public static function isValid(mixed $submitted, mixed $session): bool
    {
        $submittedToken = (string)($submitted ?? '');
        $sessionToken = (string)($session ?? '');
        $tokensPresent = $submittedToken !== '' && $sessionToken !== '';

        return $tokensPresent && \hash_equals(known_string: $sessionToken, user_string: $submittedToken);
    }

    /** The names (never the values) of the cookies in a Cookie header, for staging diagnostics. */
    public static function cookieNames(string $cookieHeader): string
    {
        $pairs = \explode(';', $cookieHeader);
        $names = [];

        foreach ($pairs as $pair) {
            $name = \trim(\explode('=', $pair, 2)[0]);
            if ($name !== '') $names[] = $name;
        }

        $hasNames = $names !== [];

        return $hasNames ? \implode(',', $names) : '(none)';
    }
}
