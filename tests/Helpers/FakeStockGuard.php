<?php

declare(strict_types=1);

namespace Tests\Helpers;

/**
 * A stock-checking service for checkout tests: answers the basket.line.check and
 * order.check hooks, records what it was asked, and throws whatever the test has set.
 */
final class FakeStockGuard
{
    /** @var array<int, array{hook: string, payload: array}> */
    public static array $calls = [];
    public static ?\Throwable $throw = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
    }

    public static function basketLine(array $payload): ?array
    {
        return self::answer(hook: 'basket.line.check', payload: $payload);
    }

    public static function order(array $payload): ?array
    {
        return self::answer(hook: 'order.check', payload: $payload);
    }

    private static function answer(string $hook, array $payload): ?array
    {
        self::$calls[] = ['hook' => $hook, 'payload' => $payload];
        if (self::$throw !== null) throw self::$throw;

        return null; // A guard that is happy claims nothing
    }

    /** @return array<int, array> payloads the guard received for one hook */
    public static function payloads(string $hook): array
    {
        return \array_values(\array_map(
            fn(array $call): array => $call['payload'],
            \array_filter(self::$calls, fn(array $call): bool => $call['hook'] === $hook),
        ));
    }
}
