<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Rules for the admin discount form: validation messages and the parsing of
 * date and whole-number inputs. Pure; the coupon-code lookup is passed in so
 * it only runs once every other check has passed.
 */
class DiscountRules
{
    private const MAX_MIN_SPEND = 1000000;

    /**
     * @param callable(string): bool $codeInUse whether another discount already uses this coupon code
     */
    public static function validate(
        string $name,
        string $type,
        string $code,
        string $valueType,
        float $value,
        ?string $startsAt,
        ?string $endsAt,
        array $productIds,
        callable $codeInUse,
        ?float $minSpendIncTax = null,
        array $categoryIds = [],
        array $brandIds = [],
    ): ?string {
        $nameEmpty = empty($name);
        if ($nameEmpty) return 'Discount name is required';

        $validTypes = ['sitewide', 'product', 'coupon'];
        $invalidType = !\in_array($type, $validTypes, true);
        if ($invalidType) return 'Invalid discount type';

        $validValueTypes = ['percent', 'fixed'];
        $invalidValueType = !\in_array($valueType, $validValueTypes, true);
        if ($invalidValueType) return 'Invalid discount value type';

        $valueNotPositive = $value <= 0;
        if ($valueNotPositive) return 'Discount value must be greater than zero';

        $percentTooHigh = $valueType === 'percent' && $value > 100;
        if ($percentTooHigh) return 'Percentage discount cannot exceed 100%';

        $bothDatesSet = $startsAt !== null && $endsAt !== null;
        $endBeforeStart = $bothDatesSet && $endsAt < $startsAt;
        if ($endBeforeStart) return 'End date must be after the start date';

        $productTypeNeedsScope = $type === 'product' && empty($productIds) && empty($categoryIds) && empty($brandIds);
        if ($productTypeNeedsScope) return 'Select at least one product, category or brand for a per-product discount';

        $couponTypeNeedsCode = $type === 'coupon' && $code === '';
        if ($couponTypeNeedsCode) return 'A coupon code is required';

        $codeHasInvalidChars = $code !== '' && !\preg_match('/^[A-Z0-9_-]+$/', $code);
        if ($codeHasInvalidChars) return 'Coupon codes may only contain letters, numbers, hyphens and underscores';

        $minSpendTooLarge = $minSpendIncTax !== null && $minSpendIncTax > self::MAX_MIN_SPEND;
        if ($minSpendTooLarge) return 'Minimum spend must be under £' . \number_format(self::MAX_MIN_SPEND);

        $codeTaken = $type === 'coupon' && $codeInUse($code);
        if ($codeTaken) return 'That coupon code is already in use';

        return null;
    }

    public static function parseDate(string $raw): ?string
    {
        $trimmed = \trim($raw);
        if ($trimmed === '') return null;

        $timestamp = \strtotime($trimmed);
        if ($timestamp === false) return null;

        return \date('Y-m-d H:i:s', $timestamp);
    }

    /** A money amount from the form: blank, zero or negative means none, otherwise rounded to pence */
    public static function parseMoney(string $raw): ?float
    {
        $trimmed = \str_replace([',', '£', ' '], '', \trim($raw));
        if ($trimmed === '' || !\is_numeric($trimmed)) return null;

        $amount = \round((float) $trimmed, 2);

        return $amount > 0 ? $amount : null;
    }

    public static function parseInt(string $raw): ?int
    {
        $trimmed = \trim($raw);
        if ($trimmed === '') return null;

        return \max(0, (int)$trimmed);
    }
}
