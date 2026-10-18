<?php

declare(strict_types=1);

namespace Tests\Services\Shop;

use Osmium\Core\Library\StoreFinance;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StoreFinance.php';

class StoreFinanceTest extends TestCase
{
    private function uk(): StoreFinance
    {
        return new StoreFinance(
            currencyCode: 'GBP',
            currencySign: '£',
            taxName: 'VAT',
            taxRatePercent: 20.0,
            country: 'GB',
        );
    }

    private function validConfig(array $overrides = []): array
    {
        return $overrides + [
            'currency' => 'GBP',
            'currencySign' => '£',
            'taxName' => 'VAT',
            'taxRatePercent' => '20',
            'country' => 'GB',
        ];
    }

    public function test_exposes_the_values_it_was_built_with(): void
    {
        $finance = $this->uk();

        $this->assertSame(expected: 'GBP', actual: $finance->currencyCode());
        $this->assertSame(expected: '£', actual: $finance->currencySign());
        $this->assertSame(expected: 'VAT', actual: $finance->taxName());
        $this->assertSame(expected: 20.0, actual: $finance->taxRatePercent());
        $this->assertSame(expected: 'GB', actual: $finance->country());
    }

    public function test_from_config_reads_the_shop_block(): void
    {
        $finance = StoreFinance::fromConfig((object) [
            'currency' => 'eur',
            'currencySign' => '€',
            'taxName' => 'MwSt',
            'taxRatePercent' => '19',
            'country' => 'de',
        ]);

        $this->assertSame(expected: 'EUR', actual: $finance->currencyCode()); // Normalised to upper case
        $this->assertSame(expected: '€', actual: $finance->currencySign());
        $this->assertSame(expected: 'MwSt', actual: $finance->taxName());
        $this->assertSame(expected: 19.0, actual: $finance->taxRatePercent()); // Config stores it as a string
        $this->assertSame(expected: 'DE', actual: $finance->country());
    }

    public function test_from_config_accepts_an_array_and_a_numeric_rate(): void
    {
        $finance = StoreFinance::fromConfig($this->validConfig(['taxRatePercent' => 5]));

        $this->assertSame(expected: 5.0, actual: $finance->taxRatePercent());
    }

    public function test_a_zero_tax_rate_is_valid(): void
    {
        $finance = StoreFinance::fromConfig($this->validConfig(['taxRatePercent' => '0']));

        $this->assertSame(expected: 0.0, actual: $finance->taxRatePercent());
    }

    /** @dataProvider requiredKeys */
    public function test_a_missing_setting_throws_rather_than_defaulting(string $key): void
    {
        $config = $this->validConfig();
        unset($config[$key]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing '{$key}'");

        StoreFinance::fromConfig($config);
    }

    public static function requiredKeys(): array
    {
        return [['currency'], ['currencySign'], ['taxName'], ['taxRatePercent'], ['country']];
    }

    public function test_an_empty_config_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoreFinance::fromConfig([]);
    }

    public function test_an_empty_string_counts_as_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing 'taxName'");

        StoreFinance::fromConfig($this->validConfig(['taxName' => '']));
    }

    public function test_a_non_numeric_tax_rate_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a number');

        StoreFinance::fromConfig($this->validConfig(['taxRatePercent' => 'twenty']));
    }

    public function test_a_tax_rate_outside_0_to_100_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StoreFinance::fromConfig($this->validConfig(['taxRatePercent' => '120']));
    }

    public function test_a_bad_currency_code_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3-letter ISO code');

        StoreFinance::fromConfig($this->validConfig(['currency' => 'POUNDS']));
    }

    public function test_a_bad_country_code_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2-letter ISO code');

        StoreFinance::fromConfig($this->validConfig(['country' => 'GBR']));
    }

    public function test_format_adds_the_symbol_and_two_decimals(): void
    {
        $finance = $this->uk();

        $this->assertSame(expected: '£12.50', actual: $finance->format(12.5));
        $this->assertSame(expected: '£0.00', actual: $finance->format(0.0));
        $this->assertSame(expected: '£1,234.57', actual: $finance->format(1234.567));
    }

    public function test_format_puts_the_minus_before_the_symbol(): void
    {
        $this->assertSame(expected: '-£1.20', actual: $this->uk()->format(-1.2));
    }

    public function test_format_does_not_show_negative_zero(): void
    {
        $this->assertSame(expected: '£0.00', actual: $this->uk()->format(-0.001));
    }

    public function test_format_in_currency_uses_the_symbol_for_the_store_currency(): void
    {
        $finance = $this->uk();

        $this->assertSame(expected: '£12.50', actual: $finance->formatInCurrency(amount: 12.5, currencyCode: 'GBP'));
        $this->assertSame(expected: '£12.50', actual: $finance->formatInCurrency(amount: 12.5, currencyCode: 'gbp'));
    }

    public function test_format_in_currency_shows_the_code_for_any_other_currency(): void
    {
        // An old order must not pick up the store's symbol if the store currency has since changed
        $this->assertSame(expected: 'EUR 12.50', actual: $this->uk()->formatInCurrency(amount: 12.5, currencyCode: 'EUR'));
    }
}
