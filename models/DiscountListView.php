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
            default => '£' . \number_format((float)$discount['value'], 2),
        };

        if (!empty($discount['free_delivery'])) $valueLabel .= ' + free delivery';

        $windowLabel = self::formatDateWindow(startsAt: $discount['starts_at'], endsAt: $discount['ends_at']);

        $statusBadge = self::buildStatusBadge(discount: $discount);

        $productCount = (int)$discount['product_count'];
        $scopeLabel = self::buildScopeLabel($discount);

        return [
            'id' => $discount['id'],
            'name' => $discount['name'],
            'type' => $discount['type'],
            'valueType' => $discount['value_type'],
            'value' => (float)$discount['value'],
            'freeDelivery' => !empty($discount['free_delivery']),
            'isLive' => self::isLive($discount),
            'typeLabel' => $typeLabel,
            'code' => $discount['code'],
            'valueLabel' => $valueLabel,
            'windowLabel' => $windowLabel,
            'usageLabel' => $usageLabel,
            'statusBadge' => $statusBadge,
            'productCount' => $productCount,
            'scopeLabel' => $scopeLabel,
            'ordersLabel' => self::buildOrdersLabel($discount),
            'givenLabel' => self::buildGivenLabel($discount),
            'created_at' => $discount['created_at'],
        ];
    }

    /** Paid orders that used the discount; a dash when none. */
    private static function buildOrdersLabel(array $discount): string
    {
        $orders = (int)($discount['orders_count'] ?? 0);

        return $orders > 0 ? (string)$orders : '-';
    }

    /** What the discount has taken off paid orders, before VAT; a dash when nothing. */
    private static function buildGivenLabel(array $discount): string
    {
        $given = (float)($discount['discount_given'] ?? 0);

        return $given > 0 ? '£' . \number_format($given, 2) : '-';
    }

    /** Whether the basket would apply it right now: switched on, inside its window and, for a coupon, not used up. */
    private static function isLive(array $discount): bool
    {
        if (!$discount['active']) return false;

        $now = new \DateTimeImmutable();
        $notYetStarted = $discount['starts_at'] && $now < new \DateTimeImmutable($discount['starts_at']);
        if ($notYetStarted) return false;

        $hasExpired = $discount['ends_at'] && $now > new \DateTimeImmutable($discount['ends_at']);
        if ($hasExpired) return false;

        $usedUp = $discount['type'] === 'coupon' && $discount['usage_limit'] !== null && (int)$discount['times_used'] >= (int)$discount['usage_limit'];

        return !$usedUp;
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

    /** The status pill is also the on/off switch: clicking it posts toggle_active for the row. */
    private static function buildStatusBadge(array $discount): string
    {
        [$class, $label] = self::statusPill($discount);
        $title = $discount['active'] ? 'Click to disable' : 'Click to enable';

        return '<a href="#" class="badge ' . $class . '" data-list-action="toggle_active" data-confirm="" title="' . $title . '">' . $label . '</a>';
    }

    /** @return array{0: string, 1: string} badge class and label */
    private static function statusPill(array $discount): array
    {
        $notActive = !$discount['active'];
        if ($notActive) return ['bg-secondary', 'Disabled'];

        $now = new \DateTimeImmutable();
        $starts = $discount['starts_at'] ? new \DateTimeImmutable($discount['starts_at']) : null;
        $ends = $discount['ends_at'] ? new \DateTimeImmutable($discount['ends_at']) : null;

        $notYetStarted = $starts && $now < $starts;
        if ($notYetStarted) return ['bg-label-info', 'Scheduled'];

        $hasExpired = $ends && $now > $ends;
        if ($hasExpired) return ['bg-label-secondary', 'Expired'];

        return ['bg-success', 'Live'];
    }
}
