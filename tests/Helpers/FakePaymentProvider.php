<?php

declare(strict_types=1);

namespace Tests\Helpers;

/**
 * A payment provider service for checkout tests: answers the payment.create and
 * payment.confirm hooks with whatever the test has set, and records what it was asked.
 * A Throwable answer is thrown instead of returned.
 */
final class FakePaymentProvider
{
    /** @var array<int, array{hook: string, payload: array}> */
    public static array $calls = [];
    public static mixed $createAnswer = null;
    public static mixed $confirmAnswer = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$createAnswer = ['ref' => 'FAKE-REF-1', 'client_secret' => 'secret-1', 'test_mode' => true];
        self::$confirmAnswer = null;
    }

    public static function create(array $payload): ?array
    {
        return self::answer(hook: 'payment.create', payload: $payload, answer: self::$createAnswer);
    }

    public static function confirm(array $payload): ?array
    {
        return self::answer(hook: 'payment.confirm', payload: $payload, answer: self::$confirmAnswer);
    }

    private static function answer(string $hook, array $payload, mixed $answer): ?array
    {
        // Plain data only: the payload carries the request's osmium object, and holding on to it would keep
        // a test's database connection alive, then roll back the next test's transaction when it is freed
        self::$calls[] = ['hook' => $hook, 'payload' => \array_filter($payload, fn(mixed $value): bool => !\is_object($value))];
        if ($answer instanceof \Throwable) throw $answer;

        return $answer;
    }
}
