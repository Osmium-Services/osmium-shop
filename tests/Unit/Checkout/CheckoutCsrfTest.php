<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\CheckoutCsrf;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../models/CheckoutCsrf.php';

/**
 * The token check in front of every basket change and every payment.
 */
class CheckoutCsrfTest extends TestCase
{
    public function test_matching_tokens_are_valid(): void
    {
        $this->assertTrue(CheckoutCsrf::isValid(submitted: 'abc123', session: 'abc123'));
    }

    public function test_different_tokens_are_invalid(): void
    {
        $this->assertFalse(CheckoutCsrf::isValid(submitted: 'abc123', session: 'abc124'));
    }

    public function test_two_empty_tokens_are_invalid(): void
    {
        $this->assertFalse(CheckoutCsrf::isValid(submitted: '', session: ''));
    }

    public function test_missing_tokens_are_invalid(): void
    {
        $this->assertFalse(CheckoutCsrf::isValid(submitted: null, session: null));
        $this->assertFalse(CheckoutCsrf::isValid(submitted: 'abc123', session: null));
        $this->assertFalse(CheckoutCsrf::isValid(submitted: null, session: 'abc123'));
    }

    public function test_a_prefix_of_the_token_is_invalid(): void
    {
        $this->assertFalse(CheckoutCsrf::isValid(submitted: 'abc', session: 'abc123'));
    }

    public function test_a_non_string_submitted_value_does_not_match_by_loose_comparison(): void
    {
        $this->assertFalse(CheckoutCsrf::isValid(submitted: 0, session: 'abc123'));
        $this->assertFalse(CheckoutCsrf::isValid(submitted: true, session: 'abc123'));
    }

    public function test_cookie_names_lists_the_names_and_never_the_values(): void
    {
        $names = CheckoutCsrf::cookieNames('PHPSESSID=secretvalue123; theme=dark; flag');

        $this->assertSame('PHPSESSID,theme,flag', $names);
        $this->assertStringNotContainsString('secretvalue123', $names);
    }

    public function test_cookie_names_says_none_when_there_is_no_cookie_header(): void
    {
        $this->assertSame('(none)', CheckoutCsrf::cookieNames(''));
        $this->assertSame('(none)', CheckoutCsrf::cookieNames(' ; '));
    }
}
