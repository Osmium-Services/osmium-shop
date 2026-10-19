<?php

declare(strict_types=1);

namespace Osmium\Core\Library;

/**
 * The store's financial settings in one place: currency, tax and country.
 *
 * Everything that prices, labels or charges (orders, the order PDF, checkout
 * totals, payment providers, accounting services, themes) reads these here
 * instead of keeping its own copy or hard-coding GBP / VAT / GB. The values come
 * from Shop settings (shop.json.php) and nothing is defaulted: a missing or
 * invalid value throws, so a misconfigured shop fails loudly instead of quietly
 * charging the wrong currency or tax.
 *
 * Osmium serves UK markets only for now. This class is the single place to
 * change when that stops being true, for example to resolve the currency and
 * tax for an order or a delivery country, without touching any consumer.
 * Get the site's one with StoreFinance::forOsmium($osmium): built once per request from Shop settings.
 */
final class StoreFinance
{
    private const REQUIRED_CONFIG_KEYS = ['currency', 'currencySign', 'taxName', 'taxRatePercent', 'country'];

    private string $currencyCode;
    private string $country;

    /**
     * @throws \InvalidArgumentException if any value is unusable
     */
    public function __construct(
        string $currencyCode,
        private string $currencySign,
        private string $taxName,
        private float $taxRatePercent,
        string $country,
    ) {
        $this->currencyCode = \strtoupper($currencyCode);
        $this->country = \strtoupper($country);

        $currencyValid = (bool) \preg_match('/^[A-Z]{3}$/', $this->currencyCode);
        if (!$currencyValid) throw new \InvalidArgumentException("Shop currency must be a 3-letter ISO code, got '{$currencyCode}'");

        $countryValid = (bool) \preg_match('/^[A-Z]{2}$/', $this->country);
        if (!$countryValid) throw new \InvalidArgumentException("Shop country must be a 2-letter ISO code, got '{$country}'");

        $signMissing = \trim($currencySign) === '';
        if ($signMissing) throw new \InvalidArgumentException('Shop currencySign must not be empty');

        $taxNameMissing = \trim($taxName) === '';
        if ($taxNameMissing) throw new \InvalidArgumentException('Shop taxName must not be empty');

        $rateValid = $taxRatePercent >= 0 && $taxRatePercent <= 100;
        if (!$rateValid) throw new \InvalidArgumentException("Shop taxRatePercent must be between 0 and 100, got {$taxRatePercent}");
    }

    /** @var \WeakMap<object, self>|null the finance built for each framework instance */
    private static ?\WeakMap $perOsmium = null;

    /**
     * The store's currency, tax and country for this site, built once from Shop settings. Throws if a setting is
     * missing or invalid, so a misconfigured shop fails loudly.
     *
     * @param object $osmium the framework instance (its config holds the 'shop' block)
     */
    public static function forOsmium(object $osmium): self
    {
        self::$perOsmium ??= new \WeakMap();
        self::$perOsmium[$osmium] ??= self::fromConfig($osmium->config->shop ?? []);

        return self::$perOsmium[$osmium];
    }

    /** For tests: the finance this framework instance hands out */
    public static function useFor(object $osmium, self $finance): void
    {
        self::$perOsmium ??= new \WeakMap();
        self::$perOsmium[$osmium] = $finance;
    }

    /**
     * @param object|array $shopConfig The 'shop' block from shop.json.php
     * @throws \InvalidArgumentException if a financial setting is missing or invalid
     */
    public static function fromConfig(object|array $shopConfig): self
    {
        $config = (array) $shopConfig;

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $isSet = isset($config[$key]) && $config[$key] !== '';
            if (!$isSet) throw new \InvalidArgumentException("Shop config is missing '{$key}'");
        }

        $rateNumeric = \is_numeric($config['taxRatePercent']);
        if (!$rateNumeric) throw new \InvalidArgumentException("Shop config 'taxRatePercent' must be a number");

        return new self(
            currencyCode: (string) $config['currency'],
            currencySign: (string) $config['currencySign'],
            taxName: (string) $config['taxName'],
            taxRatePercent: (float) $config['taxRatePercent'],
            country: (string) $config['country'],
        );
    }

    /** ISO 4217, e.g. GBP. Upper case; Stripe wants it lower case, so lower it there. */
    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    /** Display symbol, e.g. £. */
    public function currencySign(): string
    {
        return $this->currencySign;
    }

    /** What the tax is called on screen and on paper, e.g. VAT. */
    public function taxName(): string
    {
        return $this->taxName;
    }

    /** e.g. 20.0 */
    public function taxRatePercent(): float
    {
        return $this->taxRatePercent;
    }

    /** ISO 3166-1 alpha-2 home / delivery country, e.g. GB. */
    public function country(): string
    {
        return $this->country;
    }

    /** "£12.50", or "-£1.20" for a negative amount (a discount line). */
    public function format(float $amount): string
    {
        $rounded = \round(num: $amount, precision: 2); // So -0.001 is "£0.00", not "-£0.00"
        $sign = $rounded < 0 ? '-' : '';
        $magnitude = \abs($rounded);
        $number = \number_format(num: $magnitude, decimals: 2);

        return $sign . $this->currencySign . $number;
    }

    /**
     * An amount in a given currency, for something stored with its own currency (an order).
     * The store's currency gets the symbol; any other is shown with its code ("EUR 12.50"), so
     * an old order never shows the wrong symbol after the store currency changes.
     */
    public function formatInCurrency(float $amount, string $currencyCode): string
    {
        $isStoreCurrency = \strtoupper($currencyCode) === $this->currencyCode;
        if ($isStoreCurrency) return $this->format($amount);

        $rounded = \round(num: $amount, precision: 2);
        $number = \number_format(num: $rounded, decimals: 2);

        return \strtoupper($currencyCode) . ' ' . $number;
    }
}
