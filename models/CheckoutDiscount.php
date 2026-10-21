<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Models;

class CheckoutDiscount extends CheckoutModel
{
    public function activeSitewide(): ?array
    {
        return $this->fetchOne('discount-active-sitewide.sql');
    }

    /**
     * Active per-product discounts covering these products, whether linked to the product itself or to its brand or a
     * category it is in. A product can appear more than once; the caller keeps the best.
     *
     * @param array<int> $productIds non-empty, already de-duplicated
     */
    public function activeForProducts(array $productIds): array
    {
        $placeholders = \implode(',', \array_fill(0, \count($productIds), '?'));
        $bindings = [];
        foreach (\array_values($productIds) as $index => $productId) {
            $bindings[$index + 1] = $productId;
        }

        $replacements = ['PLACEHOLDERS' => $placeholders];
        $linkedToProduct = $this->fetchAll('discount-active-for-products.sql', $bindings, $replacements);
        $coveredByCategoryOrBrand = $this->fetchAll('discount-active-for-product-scopes.sql', $bindings, $replacements);

        return \array_merge($linkedToProduct, $coveredByCategoryOrBrand);
    }

    public function activeCouponByCode(string $code): ?array
    {
        return $this->fetchOne('discount-active-coupon-by-code.sql', [':code' => $code]);
    }

    public function customerRedemptionCount(int $discountId, string $customerEmail): int
    {
        $row = $this->fetchOne('discount-customer-redemption-count.sql', [
            ':discount_id' => $discountId,
            ':customer_email' => $customerEmail,
        ]);

        return (int)($row['redemption_count'] ?? 0);
    }

    /**
     * Record that a paid order used a coupon.
     *
     * The use is always recorded, because the customer has paid, but the counter only moves while the coupon is under its
     * overall limit. So concurrent checkouts can never push times_used past usage_limit.
     *
     * @return bool false when the coupon was already at its limit by the time this order was paid
     */
    public function recordRedemption(int $discountId, int $orderId, ?string $customerEmail): bool
    {
        $this->run('discount-redemption-insert.sql', [
            ':discount_id' => $discountId,
            ':order_id' => $orderId,
            ':customer_email' => $customerEmail,
        ]);
        $this->run('discount-increment-times-used.sql', [':id' => $discountId]);

        return $this->database->rowCount() > 0;
    }
}
