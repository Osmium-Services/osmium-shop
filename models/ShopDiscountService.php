<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Models\CheckoutDiscount;

/**
 * ShopDiscountService
 *
 * Resolves which sitewide, per-product and coupon discounts are currently
 * active, for ShopTotalsService to apply, and records coupon redemptions once
 * an order carrying one is actually paid.
 *
 * Callers gate this entirely by only constructing it when the active payment
 * provider is an installed payment service — see PaymentProviders. When no
 * provider is configured, this service is never wired in, and
 * ShopTotalsService treats a missing service the same as "no discounts
 * running".
 */
class ShopDiscountService
{
    public function __construct(private CheckoutDiscount $discount) {}

    /**
     * The single active sitewide discount with the largest value, or null.
     *
     * Sitewide always wins over a per-product discount, so ShopTotalsService
     * never needs to resolve per-product discounts once this returns one.
     */
    public function activeSitewideDiscount(): ?array
    {
        return $this->discount->activeSitewide();
    }

    /**
     * The best active product-specific discount for each product, keyed by
     * product_id. A product covered by more than one active discount takes
     * whichever is worth more.
     *
     * @param array<int> $productIds
     * @return array<int, array>
     */
    public function activeProductDiscounts(array $productIds): array
    {
        $productIds = \array_values(\array_unique(\array_filter(\array_map('intval', $productIds))));

        $noProducts = empty($productIds);
        if ($noProducts) return [];

        $rows = $this->discount->activeForProducts($productIds);

        $byProductId = [];
        foreach ($rows as $row) {
            $productId = (int)$row['product_id'];

            $alreadyHasBetterDiscount = isset($byProductId[$productId]) && (float)$byProductId[$productId]['value'] >= (float)$row['value'];
            if ($alreadyHasBetterDiscount) continue;

            $byProductId[$productId] = $row;
        }

        return $byProductId;
    }

    /**
     * The active coupon discount matching a code, or null.
     *
     * Already excludes a coupon whose overall usage_limit is used up. A
     * customer-specific limit (usage_limit_per_customer) cannot be checked
     * here — no email is known at basket stage — so callers check that
     * separately once the customer's email is available (checkout).
     *
     * @param string|null $code Customer-entered code, null/blank means none applied
     */
    public function activeCouponByCode(?string $code): ?array
    {
        $code = \trim((string)$code);
        if ($code === '') return null;

        return $this->discount->activeCouponByCode(\strtoupper($code));
    }

    /**
     * How many times a customer has already redeemed a given discount.
     *
     * Checked against usage_limit_per_customer before an order carrying a
     * coupon is created.
     */
    public function customerRedemptionCount(int $discountId, string $customerEmail): int
    {
        return $this->discount->customerRedemptionCount($discountId, $customerEmail);
    }

    /**
     * Record that a coupon was redeemed on a paid order.
     *
     * Called only from ShopOrderService::markPaid(), never at order creation
     * — a pending, never-paid order must not consume a limited coupon's uses.
     */
    public function recordRedemption(int $discountId, int $orderId, ?string $customerEmail): void
    {
        $withinLimit = $this->discount->recordRedemption($discountId, $orderId, $customerEmail);
        if (!$withinLimit) \error_log("Coupon {$discountId} was already at its usage limit when order {$orderId} was paid; the order stands and the counter was not raised");
    }
}
