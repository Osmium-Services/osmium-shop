<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Display values for the admin discounts list (type and value labels, date
 * window, usage, status badge) so the view only echoes. Pure given its inputs.
 */
class DiscountListView
{
    public static function row(array $discount): array
    {
        $typeLabels = [
            'sitewide' => 'Sitewide',
            'product' => 'Per-Product',
            'coupon' => 'Coupon',
        ];
        $typeLabel = $typeLabels[$discount['type']] ?? \ucfirst($discount['type']);

        $usageLabel = self::buildUsageLabel($discount);

        $valueLabel = match ($discount['value_type']) {
            'percent' => \rtrim(\rtrim(\number_format((float)$discount['value'], 2), '0'), '.') . '%',
            'free_delivery' => 'Free delivery',
            default => '£' . \number_format((float)$discount['value'], 2),
        };

        $windowLabel = self::formatDateWindow(startsAt: $discount['starts_at'], endsAt: $discount['ends_at']);

        $statusBadge = self::buildStatusBadge(discount: $discount);

        $productCount = (int)$discount['product_count'];
        $scopeLabel = self::buildScopeLabel($discount);

        return [
            'id' => $discount['id'],
            'name' => $discount['name'],
            'typeLabel' => $typeLabel,
            'code' => $discount['code'],
            'valueLabel' => $valueLabel,
            'windowLabel' => $windowLabel,
            'usageLabel' => $usageLabel,
            'statusBadge' => $statusBadge,
            'productCount' => $productCount,
            'scopeLabel' => $scopeLabel,
            'created_at' => $discount['created_at'],
        ];
    }

    /** What a per-product discount covers, such as "3 products, 1 category"; a dash when nothing is chosen. */
    private static function buildScopeLabel(array $discount): string
    {
        $parts = [];
        $counts = [
            ['product_count', 'product', 'products'],
            ['category_count', 'category', 'categories'],
            ['brand_count', 'brand', 'brands'],
        ];
        foreach ($counts as [$key, $singular, $plural]) {
            $count = (int)($discount[$key] ?? 0);
            if ($count > 0) $parts[] = $count . ' ' . ($count === 1 ? $singular : $plural);
        }

        return $parts === [] ? '-' : \implode(', ', $parts);
    }

    private static function buildUsageLabel(array $discount): ?string
    {
        $isCoupon = $discount['type'] === 'coupon';
        if (!$isCoupon) return null;

        $timesUsed = (int)$discount['times_used'];
        $limit = $discount['usage_limit'];

        $hasLimit = $limit !== null;
        if ($hasLimit) return "{$timesUsed} / {$limit} used";

        return "{$timesUsed} used";
    }

    private static function formatDateWindow(?string $startsAt, ?string $endsAt): string
    {
        $noWindow = !$startsAt && !$endsAt;
        if ($noWindow) return 'Always on';

        $startLabel = $startsAt ? \date('d M Y', \strtotime($startsAt)) : 'now';
        $endLabel = $endsAt ? \date('d M Y', \strtotime($endsAt)) : 'ongoing';

        return "{$startLabel} \u{2192} {$endLabel}";
    }

    private static function buildStatusBadge(array $discount): string
    {
        $notActive = !$discount['active'];
        if ($notActive) return '<span class="badge bg-secondary">Disabled</span>';

        $now = new \DateTimeImmutable();
        $starts = $discount['starts_at'] ? new \DateTimeImmutable($discount['starts_at']) : null;
        $ends = $discount['ends_at'] ? new \DateTimeImmutable($discount['ends_at']) : null;

        $notYetStarted = $starts && $now < $starts;
        if ($notYetStarted) return '<span class="badge bg-label-info">Scheduled</span>';

        $hasExpired = $ends && $now > $ends;
        if ($hasExpired) return '<span class="badge bg-label-secondary">Expired</span>';

        return '<span class="badge bg-success">Live</span>';
    }
}
