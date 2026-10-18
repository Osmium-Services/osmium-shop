<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Core\Library\StoreFinance;

/**
 * ShopTotalsService
 *
 * The single source of truth for money. The basket page, the checkout page,
 * the payment provider's order body and the stored order record all total up
 * through here — four consumers, one calculation. Any divergence between them
 * is a payment dispute.
 *
 * The order of operations is fixed and tested as such:
 *
 *   1. unit price exc VAT for the line (base price plus option premiums,
 *      already resolved by ShopPricingService — there are no quantity tiers)
 *   2. multiply by quantity, round to 2dp here
 *   3. sum line totals into the subtotal
 *   4. resolve delivery from the postcode zone
 *   5. VAT per unit, multiplied by quantity, plus VAT on delivery
 *   6. grand total
 *
 * VAT is the sum of the per-line amounts, never VAT recomputed on the rounded
 * subtotal — the two differ by a penny often enough to matter, and a payment
 * provider rejects a breakdown that does not reconcile exactly.
 *
 * Within a line, VAT is taken **per unit and multiplied**, not taken on the
 * rounded line total. PayPal requires its tax_total to equal the sum of (unit
 * tax x quantity) and refuses the order otherwise, which it demonstrated on
 * staging with TAX_TOTAL_MISMATCH. Both approaches are defensible for UK VAT,
 * so the provider's constraint decides it.
 */
class ShopTotalsService
{
    public const ZONE_MAINLAND = 'mainland';
    public const ZONE_HIGHLANDS = 'highlands';
    public const ZONE_EXCLUDED = 'excluded';
    public const ZONE_UNKNOWN = 'unknown';

    /**
     * @param float $taxRatePercent VAT rate, e.g. 20.0
     * @param float $deliveryCharge Mainland delivery charge exc VAT
     * @param float $highlandsSurcharge Added to the mainland charge for the Highlands & Islands
     * @param array $surchargePostcodes Outward-code patterns attracting the surcharge
     * @param array $excludedPostcodes Outward-code patterns not delivered to at all
     * @param float|null $freeDeliveryThreshold Subtotal exc VAT at which delivery is free — null disables it
     * @param ShopDiscountService|null $discounts Resolves active discounts — null means none apply,
     *        which is how the caller gates discounts off entirely when no payment provider is
     *        configured (see PaymentProviders)
     */
    public function __construct(
        private float $taxRatePercent = 20.0,
        private ?float $deliveryCharge = null,
        private float $highlandsSurcharge = 3.00,
        private array $surchargePostcodes = [],
        private array $excludedPostcodes = [],
        private ?float $freeDeliveryThreshold = null,
        private ?ShopDiscountService $discounts = null,
    ) {}

    /**
     * Build from the checkout config block (delivery settings) and the store's finance
     * settings, where the VAT rate lives and nowhere else.
     *
     * @param object|array $checkoutConfig The 'checkout' block from checkout.json.php
     * @param ShopDiscountService|null $discounts Pass only when the active payment
     *        provider supports discounts — see PaymentProviders::configured()
     * @return self
     */
    public static function fromConfig(
        object|array $checkoutConfig,
        StoreFinance $finance,
        ?ShopDiscountService $discounts = null,
    ): self {
        $config = (array)$checkoutConfig;

        $threshold = $config['freeDeliveryThreshold'] ?? null;

        $charge = $config['deliveryCharge'] ?? null;

        $deliveryUnconfigured = $charge === null;
        if ($deliveryUnconfigured) \error_log('Shop config carries no deliveryCharge — delivery cannot be quoted on this environment'); // shop.json.php is set per environment and never deployed, so this is a real possibility, not a theoretical one

        return new self(
            taxRatePercent: $finance->taxRatePercent(),
            deliveryCharge: $charge === null ? null : (float)$charge,
            highlandsSurcharge: (float)($config['deliverySurchargeHighlands'] ?? 0.0),
            surchargePostcodes: (array)($config['deliverySurchargePostcodes'] ?? []),
            excludedPostcodes: (array)($config['deliveryExcludedPostcodes'] ?? []),
            freeDeliveryThreshold: $threshold === null ? null : (float)$threshold,
            discounts: $discounts,
        );
    }

    /**
     * Total up a set of resolved basket lines.
     *
     * @param array $lines Lines from ShopPricingService/ShopBasketService, each
     *                     carrying unit_price_exc_tax and quantity
     * @param string|null $postcode Delivery postcode, null before one is given
     * @param string|null $couponCode Customer-entered coupon code, if any —
     *        only resolved when no sitewide discount is active (sitewide
     *        always wins, per the single-discount-per-line rule)
     * @return array Totals, with the per-line figures alongside
     */
    public function calculate(array $lines, ?string $postcode = null, ?string $couponCode = null): array
    {
        $sitewideDiscount = $this->discounts?->activeSitewideDiscount();

        $couponDiscount = $sitewideDiscount === null
            ? $this->discounts?->activeCouponByCode($couponCode)
            : null;

        // A coupon with a minimum spend only applies once the basket reaches it. The basket is measured as the
        // customer sees prices (inc VAT), before the discount and before delivery.
        $couponNotice = $this->couponBelowMinimum($couponDiscount, $lines, (string)$couponCode);
        if ($couponNotice !== null) $couponDiscount = null;

        // A running sitewide sale takes the place of any coupon, so say so rather than leave a valid code looking broken
        if ($sitewideDiscount !== null) $couponNotice = $this->couponBlockedBySale((string)$couponCode);

        // A free delivery coupon takes nothing off the goods: it waives delivery only, so it neither counts as a
        // price discount here nor stops per-product discounts applying. Its summary still goes on the order below.
        $freeDeliveryCoupon = $couponDiscount !== null && ($couponDiscount['value_type'] ?? '') === 'free_delivery' ? $couponDiscount : null;
        if ($freeDeliveryCoupon !== null) $couponDiscount = null;

        // Sitewide and coupon discounts both win over a per-product discount
        // and apply to every line, so there is no reason to resolve
        // per-product discounts once either is active.
        $noBasketWideDiscount = $sitewideDiscount === null && $couponDiscount === null;
        $productDiscounts = $noBasketWideDiscount && $this->discounts !== null
            ? $this->discounts->activeProductDiscounts(\array_column($lines, 'product_id'))
            : [];

        $basketDiscount = $sitewideDiscount ?? $couponDiscount;

        $pricedLines = [];
        $subtotalExcTax = 0.0;
        $lineTaxTotal = 0.0;

        foreach ($lines as $line) {
            $priced = $this->priceLine($line, $basketDiscount, $productDiscounts);

            $subtotalExcTax += $priced['line_total_exc_tax'];
            $lineTaxTotal += $priced['line_tax_amount'];

            $pricedLines[] = $priced;
        }

        $subtotalExcTax = \round(num: $subtotalExcTax, precision: 2);
        $lineTaxTotal = \round(num: $lineTaxTotal, precision: 2);

        $zone = $this->resolveZone($postcode);
        $deliveryConfigured = $this->deliveryCharge !== null;
        $delivery = $this->resolveDeliveryCharge(zone: $zone, subtotalExcTax: $subtotalExcTax, hasLines: !empty($pricedLines), freeDelivery: $freeDeliveryCoupon !== null);
        $deliveryTax = $this->taxOn($delivery);

        $taxAmount = \round(num: $lineTaxTotal + $deliveryTax, precision: 2);
        $totalIncTax = \round(num: $subtotalExcTax + $delivery + $taxAmount, precision: 2);

        $totalDiscountAmount = 0.0;
        foreach ($pricedLines as $line) {
            $lineSaving = ((float)$line['unit_price_before_discount_exc_tax'] - (float)$line['unit_price_exc_tax']) * (int)$line['quantity'];
            $totalDiscountAmount += $lineSaving;
        }
        $totalDiscountAmount = \round(num: $totalDiscountAmount, precision: 2);

        // Only sitewide and coupon discounts apply uniformly across the whole
        // basket, so only those two produce a single basket-level summary. A
        // per-product discount still reduces individual lines above, but with
        // no one discount "applied" to the order as a whole there is nothing
        // meaningful to summarise here.
        $discountSummary = null;
        if ($sitewideDiscount !== null) {
            $discountSummary = ['type' => 'sitewide', 'id' => (int)$sitewideDiscount['id'], 'name' => $sitewideDiscount['name'], 'code' => null];
        } elseif ($couponDiscount !== null || $freeDeliveryCoupon !== null) {
            $coupon = $couponDiscount ?? $freeDeliveryCoupon;
            $discountSummary = [
                'type' => 'coupon',
                'id' => (int)$coupon['id'],
                'name' => $coupon['name'],
                'code' => \strtoupper((string)$couponCode),
                'usage_limit_per_customer' => $coupon['usage_limit_per_customer'] !== null ? (int)$coupon['usage_limit_per_customer'] : null,
            ];
        }

        return [
            'lines' => $pricedLines,
            'item_count' => $this->countUnits($lines),
            'subtotal_exc_tax' => $subtotalExcTax,
            'delivery_zone' => $zone,
            'delivery_exc_tax' => $delivery,
            'delivery_tax_amount' => $deliveryTax,
            'free_delivery' => $freeDeliveryCoupon !== null, // A free delivery coupon is waiving the delivery charge
            'delivery_configured' => $deliveryConfigured, // False means £0.00 is "we cannot quote", not "free delivery". Checkout must refuse to take money on this.
            'tax_amount' => $taxAmount,
            'tax_rate_percent' => $this->taxRatePercent,
            'total_inc_tax' => $totalIncTax,
            'deliverable' => $zone === self::ZONE_MAINLAND || $zone === self::ZONE_HIGHLANDS,
            'discount' => $discountSummary,
            'coupon_notice' => $couponNotice, // Set when a code is held back (under its minimum spend, or a sitewide sale is running), else null
            'total_discount_amount' => $totalDiscountAmount,
        ];
    }

    /**
     * The notice for a valid code entered while a sitewide sale is running, or null when no code was entered or it
     * is not a live coupon (a stale or mistyped code needs no explaining).
     *
     * @return array{type: string, code: string, message: string}|null
     */
    private function couponBlockedBySale(string $couponCode): ?array
    {
        $code = \strtoupper(\trim($couponCode));
        if ($code === '' || $this->discounts?->activeCouponByCode($code) === null) return null;

        return [
            'type' => 'sitewide_sale',
            'code' => $code,
            'message' => 'A sale is already running, so coupon codes cannot be used with it.',
        ];
    }

    /**
     * Why a coupon is being held back, or null when it applies (or there is none). Only a minimum spend holds one
     * back: the amounts are inc VAT, the basket measured before the discount and before delivery.
     *
     * @param array|null $coupon The matched coupon row, if any
     * @param array $lines Resolved basket lines
     * @return array{type: string, code: string, min_spend_inc_tax: float, short_by_inc_tax: float, message: string}|null The notice when the basket is under the coupon's minimum
     */
    private function couponBelowMinimum(?array $coupon, array $lines, string $couponCode): ?array
    {
        $min = $coupon['min_spend_inc_tax'] ?? null;
        if ($coupon === null || $min === null) return null;

        $minimum = \round((float)$min, 2);
        $basketIncTax = 0.0;
        foreach ($lines as $line) {
            $unitPrice = (float)$line['unit_price_exc_tax'];
            $basketIncTax += ($unitPrice + $this->taxOn($unitPrice)) * (int)$line['quantity'];
        }
        $basketIncTax = \round($basketIncTax, 2);

        $reachesMinimum = $basketIncTax >= $minimum;
        if ($reachesMinimum) return null;

        $shortBy = \round($minimum - $basketIncTax, 2);

        return [
            'type' => 'min_spend',
            'code' => \strtoupper(\trim($couponCode)),
            'min_spend_inc_tax' => $minimum,
            'short_by_inc_tax' => $shortBy,
            'message' => 'Spend £' . \number_format($shortBy, 2) . ' more (inc. VAT) to use this code. The minimum is £' . \number_format($minimum, 2) . '.',
        ];
    }

    /**
     * The VAT rate these totals were calculated at.
     *
     * Stored on order lines, so a later rate change cannot rewrite what was
     * charged on an order already placed.
     */
    public function taxRatePercent(): float
    {
        return $this->taxRatePercent;
    }

    /**
     * Resolve a postcode to a delivery zone.
     *
     * Excluded is checked first, deliberately. The Channel Islands sit outside
     * the UK VAT area, so a GY or JE postcode must never fall through to a
     * mainland rate and get charged 20% VAT it does not owe.
     *
     * @param string|null $postcode Customer-entered postcode
     * @return string One of the ZONE_* constants
     */
    public function resolveZone(?string $postcode): string
    {
        $noPostcode = $postcode === null || \trim($postcode) === '';
        if ($noPostcode) return self::ZONE_UNKNOWN;

        $outward = $this->outwardCode($postcode);

        $invalidPostcode = $outward === null;
        if ($invalidPostcode) return self::ZONE_UNKNOWN;

        $isExcluded = $this->matchesAny(outward: $outward, patterns: $this->excludedPostcodes);
        if ($isExcluded) return self::ZONE_EXCLUDED;

        $isSurcharged = $this->matchesAny(outward: $outward, patterns: $this->surchargePostcodes);
        if ($isSurcharged) return self::ZONE_HIGHLANDS;

        return self::ZONE_MAINLAND;
    }

    /**
     * Extract the outward code from a postcode, validating UK format.
     *
     * @param string $postcode Raw customer input
     * @return string|null Outward code, or null when the postcode is not valid
     */
    public function outwardCode(string $postcode): ?string
    {
        $stripped = \preg_replace(pattern: '/[^A-Z0-9]/', replacement: '', subject: \strtoupper($postcode));

        $isUkFormat = \preg_match(
            pattern: '/^([A-Z]{1,2}[0-9][A-Z0-9]?)[0-9][A-Z]{2}$/',
            subject: (string)$stripped,
            matches: $matches,
        );

        if (!$isUkFormat) return null;

        return $matches[1];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Price one line: discount applied first, then quantity, rounded, VAT
     * taken on the rounded total.
     *
     * @param array $line Resolved line
     * @param array|null $basketDiscount The active sitewide or coupon discount, if any — applies to every line
     * @param array $productDiscounts Active per-product discounts, keyed by product_id
     * @return array The line with its money figures added
     */
    private function priceLine(array $line, ?array $basketDiscount, array $productDiscounts): array
    {
        $unitPrice = (float)$line['unit_price_exc_tax'];
        $quantity = (int)$line['quantity'];
        $productId = (int)($line['product_id'] ?? 0);

        $discount = $basketDiscount ?? ($productDiscounts[$productId] ?? null);
        $discountPerUnit = $discount !== null ? $this->discountPerUnit(unitPrice: $unitPrice, discount: $discount) : 0.0;
        $discountedUnitPrice = \max(0.0, \round(num: $unitPrice - $discountPerUnit, precision: 2));

        $lineTotal = \round(num: $discountedUnitPrice * $quantity, precision: 2);

        // VAT is taken per unit and multiplied, not taken on the line total.
        // Both are defensible for UK VAT, but only this one survives contact
        // with PayPal: it requires tax_total to equal the sum of (unit tax x
        // quantity) exactly, and rounding the line total first drifts by a
        // penny on quantities that do not divide evenly. Getting this wrong
        // fails the order at PayPal rather than quietly, so it is worth the
        // arithmetic being led by their constraint.
        $unitTax = $this->taxOn($discountedUnitPrice);
        $lineTax = \round(num: $unitTax * $quantity, precision: 2);

        $priced = $line;
        $priced['unit_price_exc_tax'] = $discountedUnitPrice; // The price actually charged — what gets stored on the order
        $priced['unit_price_before_discount_exc_tax'] = $unitPrice;
        $priced['discount_id'] = $discount['id'] ?? null;
        $priced['discount_name'] = $discount['name'] ?? null;
        $priced['line_total_exc_tax'] = $lineTotal;
        $priced['line_tax_amount'] = $lineTax;
        $priced['line_total_inc_tax'] = \round(num: $lineTotal + $lineTax, precision: 2);
        $priced['unit_price_inc_tax'] = \round(num: $discountedUnitPrice + $unitTax, precision: 2);

        return $priced;
    }

    private function discountPerUnit(float $unitPrice, array $discount): float
    {
        $isPercent = $discount['value_type'] === 'percent';
        if ($isPercent) return \round(num: $unitPrice * ((float)$discount['value'] / 100), precision: 2);

        return \round(num: (float)$discount['value'], precision: 2);
    }

    private function taxOn(float $amountExcTax): float
    {
        return \round(num: $amountExcTax * ($this->taxRatePercent / 100), precision: 2);
    }

    /**
     * Delivery charge for a zone, before VAT.
     *
     * @param string $zone Resolved delivery zone
     * @param float $subtotalExcTax Basket subtotal, for the threshold test
     * @param bool $hasLines Whether the basket has anything in it
     * @param bool $freeDelivery Whether a free delivery coupon applies
     * @return float Charge exc VAT
     */
    private function resolveDeliveryCharge(string $zone, float $subtotalExcTax, bool $hasLines, bool $freeDelivery = false): float
    {
        $nothingToDeliver = !$hasLines;
        if ($nothingToDeliver) return 0.0;

        $notConfigured = $this->deliveryCharge === null;
        if ($notConfigured) return 0.0; // Reported through delivery_configured rather than quoted as free

        $cannotDeliver = $zone === self::ZONE_EXCLUDED;
        if ($cannotDeliver) return 0.0;

        $thresholdEnabled = $this->freeDeliveryThreshold !== null;
        $qualifiesForFreeDelivery = $freeDelivery || ($thresholdEnabled && $subtotalExcTax >= $this->freeDeliveryThreshold);
        if ($qualifiesForFreeDelivery) return 0.0; // Built but disabled by config — see PRD §4.2

        $charge = $this->deliveryCharge;

        $isHighlands = $zone === self::ZONE_HIGHLANDS;
        if ($isHighlands) $charge += $this->highlandsSurcharge;

        return \round(num: $charge, precision: 2);
    }

    /**
     * Does an outward code match any pattern in a list?
     *
     * Patterns are either an area on its own ('HS', 'IV'), a single district
     * ('KW1'), or an inclusive district range ('AB31-AB38').
     *
     * @param string $outward Outward code, already normalised
     * @param array $patterns Config patterns
     */
    private function matchesAny(string $outward, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $matched = $this->matchesPattern(outward: $outward, pattern: (string)$pattern);
            if ($matched) return true;
        }

        return false;
    }

    private function matchesPattern(string $outward, string $pattern): bool
    {
        $pattern = \strtoupper(\trim($pattern));

        $isRange = \str_contains(haystack: $pattern, needle: '-');
        if ($isRange) return $this->matchesRange(outward: $outward, pattern: $pattern);

        $patternArea = $this->postcodeArea($pattern);
        $outwardArea = $this->postcodeArea($outward);

        $differentArea = $patternArea !== $outwardArea;
        if ($differentArea) return false;

        $patternDistrict = $this->postcodeDistrict($pattern);

        $areaOnlyPattern = $patternDistrict === null;
        if ($areaOnlyPattern) return true; // 'HS' covers every HS district

        return $patternDistrict === $this->postcodeDistrict($outward);
    }

    /**
     * Match an inclusive district range such as 'AB31-AB38'.
     */
    private function matchesRange(string $outward, string $pattern): bool
    {
        [$from, $to] = \explode(separator: '-', string: $pattern, limit: 2);

        $area = $this->postcodeArea($from);

        $differentArea = $area !== $this->postcodeArea($outward);
        if ($differentArea) return false;

        $district = $this->postcodeDistrict($outward);

        $noDistrict = $district === null;
        if ($noDistrict) return false;

        $fromDistrict = $this->postcodeDistrict($from);
        $toDistrict = $this->postcodeDistrict($to);

        $rangeIsMalformed = $fromDistrict === null || $toDistrict === null;
        if ($rangeIsMalformed) return false;

        return $district >= $fromDistrict && $district <= $toDistrict;
    }

    /**
     * The letters at the start of an outward code: 'AB31' → 'AB'.
     */
    private function postcodeArea(string $outward): string
    {
        \preg_match(pattern: '/^[A-Z]+/', subject: $outward, matches: $matches);

        return $matches[0] ?? '';
    }

    /**
     * The district number of an outward code: 'AB31' → 31, 'EC1A' → 1, 'HS' → null.
     */
    private function postcodeDistrict(string $outward): ?int
    {
        $hasDistrict = \preg_match(pattern: '/^[A-Z]+([0-9]+)/', subject: $outward, matches: $matches);

        if (!$hasDistrict) return null;

        return (int)$matches[1];
    }

    private function countUnits(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            $count += (int)$line['quantity'];
        }

        return $count;
    }
}
