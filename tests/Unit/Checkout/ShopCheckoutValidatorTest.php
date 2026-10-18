<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Checkout\Services;

use Osmium\Modules\Checkout\Services\ShopCheckoutValidator;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ShopCheckoutValidator
 *
 * Server-side validation of the checkout form. Client-side validation is a
 * convenience for the customer and is not trusted here, so every rule that
 * matters is proven again through this class directly.
 */
class ShopCheckoutValidatorTest extends TestCase
{
    /**
     * A fully configured validator, matching the shipped shop.json.php
     * defaults used elsewhere in the checkout module's tests.
     */
    private function validator(): ShopCheckoutValidator
    {
        $totals = new ShopTotalsService(
            taxRatePercent: 20.0,
            deliveryCharge: 6.95,
            highlandsSurcharge: 3.00,
            surchargePostcodes: ['AB31-AB38', 'HS', 'IV', 'KA27-KA28', 'PH15-PH26', 'ZE'],
            excludedPostcodes: ['GY', 'JE'],
        );

        return new ShopCheckoutValidator($totals);
    }

    /**
     * A complete, valid submission. Individual tests override one field at a
     * time so each test isolates a single rule.
     */
    private function validInput(array $overrides = []): array
    {
        return \array_merge([
            'customer_name' => 'Jane Smith',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '01234 567890',
            'delivery_address1' => '1 High Street',
            'delivery_city' => 'Cheltenham',
            'delivery_postcode' => 'GL20 8JH',
            'terms_accepted' => '1',
        ], $overrides);
    }

    // =========================================================================
    // A fully valid submission
    // =========================================================================

    public function test_a_complete_valid_submission_has_no_errors(): void
    {
        $errors = $this->validator()->validate($this->validInput());

        $this->assertSame(expected: [], actual: $errors);
    }

    // =========================================================================
    // Customer name
    // =========================================================================

    public function test_missing_name_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_name' => '']));

        $this->assertArrayHasKey('customer_name', $errors);
    }

    public function test_one_character_name_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_name' => 'A']));

        $this->assertArrayHasKey('customer_name', $errors);
    }

    public function test_two_character_name_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_name' => 'Al']));

        $this->assertArrayNotHasKey('customer_name', $errors);
    }

    public function test_name_over_120_characters_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_name' => \str_repeat('A', 121)]));

        $this->assertArrayHasKey('customer_name', $errors);
    }

    public function test_name_of_exactly_120_characters_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_name' => \str_repeat('A', 120)]));

        $this->assertArrayNotHasKey('customer_name', $errors);
    }

    public function test_name_is_trimmed_before_length_is_checked(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_name' => '  Al  ']));

        $this->assertArrayNotHasKey('customer_name', $errors);
    }

    // =========================================================================
    // Customer email
    // =========================================================================

    public function test_missing_email_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_email' => '']));

        $this->assertArrayHasKey('customer_email', $errors);
    }

    public function test_malformed_email_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_email' => 'not-an-email']));

        $this->assertArrayHasKey('customer_email', $errors);
    }

    public function test_well_formed_email_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_email' => 'jane.smith+order@example.co.uk']));

        $this->assertArrayNotHasKey('customer_email', $errors);
    }

    public function test_email_over_190_characters_is_an_error(): void
    {
        $localPart = \str_repeat('a', 185);
        $errors = $this->validator()->validate($this->validInput(['customer_email' => $localPart . '@example.com']));

        $this->assertArrayHasKey('customer_email', $errors);
    }

    // =========================================================================
    // Customer phone (optional)
    // =========================================================================

    public function test_missing_phone_is_not_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_phone' => '']));

        $this->assertArrayNotHasKey('customer_phone', $errors);
    }

    public function test_phone_over_40_characters_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_phone' => \str_repeat('1', 41)]));

        $this->assertArrayHasKey('customer_phone', $errors);
    }

    public function test_phone_of_exactly_40_characters_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['customer_phone' => \str_repeat('1', 40)]));

        $this->assertArrayNotHasKey('customer_phone', $errors);
    }

    // =========================================================================
    // Delivery address
    // =========================================================================

    public function test_missing_address_line_one_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_address1' => '']));

        $this->assertArrayHasKey('delivery_address1', $errors);
    }

    public function test_missing_city_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_city' => '']));

        $this->assertArrayHasKey('delivery_city', $errors);
    }

    public function test_whitespace_only_address_is_treated_as_missing(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_address1' => '   ']));

        $this->assertArrayHasKey('delivery_address1', $errors);
    }

    // =========================================================================
    // Delivery postcode
    // =========================================================================

    public function test_missing_postcode_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_postcode' => '']));

        $this->assertArrayHasKey('delivery_postcode', $errors);
        $this->assertSame(expected: 'Please enter your postcode.', actual: $errors['delivery_postcode']);
    }

    public function test_malformed_postcode_is_reported_as_invalid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_postcode' => 'NOT A POSTCODE']));

        $this->assertArrayHasKey('delivery_postcode', $errors);
        $this->assertStringContainsString('does not look like a UK postcode', $errors['delivery_postcode']);
    }

    public function test_valid_postcode_we_do_not_deliver_to_is_reported_differently_from_malformed(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_postcode' => 'JE2 3AA']));

        $this->assertArrayHasKey('delivery_postcode', $errors);
        $this->assertStringContainsString('not able to deliver', $errors['delivery_postcode']);
        $this->assertStringNotContainsString('does not look like a UK postcode', $errors['delivery_postcode']);
    }

    public function test_deliverable_mainland_postcode_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_postcode' => 'GL20 8JH']));

        $this->assertArrayNotHasKey('delivery_postcode', $errors);
    }

    public function test_deliverable_highlands_postcode_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['delivery_postcode' => 'IV3 5SN']));

        $this->assertArrayNotHasKey('delivery_postcode', $errors);
    }

    // =========================================================================
    // Terms
    // =========================================================================

    public function test_missing_terms_acceptance_is_an_error(): void
    {
        $errors = $this->validator()->validate($this->validInput(['terms_accepted' => '']));

        $this->assertArrayHasKey('terms_accepted', $errors);
    }

    public function test_terms_accepted_flag_of_zero_is_treated_as_not_accepted(): void
    {
        $errors = $this->validator()->validate($this->validInput(['terms_accepted' => '0']));

        $this->assertArrayHasKey('terms_accepted', $errors);
    }

    public function test_terms_accepted_flag_of_one_is_valid(): void
    {
        $errors = $this->validator()->validate($this->validInput(['terms_accepted' => '1']));

        $this->assertArrayNotHasKey('terms_accepted', $errors);
    }

    // =========================================================================
    // Multiple errors
    // =========================================================================

    public function test_multiple_invalid_fields_all_report_errors_together(): void
    {
        $errors = $this->validator()->validate($this->validInput([
            'customer_name' => '',
            'customer_email' => 'not-an-email',
            'delivery_postcode' => '',
        ]));

        $this->assertArrayHasKey('customer_name', $errors);
        $this->assertArrayHasKey('customer_email', $errors);
        $this->assertArrayHasKey('delivery_postcode', $errors);
    }

    // =========================================================================
    // resolveZone()
    // =========================================================================

    public function test_resolve_zone_reads_the_delivery_postcode_field(): void
    {
        $zone = $this->validator()->resolveZone($this->validInput(['delivery_postcode' => 'IV3 5SN']));

        $this->assertSame(expected: ShopTotalsService::ZONE_HIGHLANDS, actual: $zone);
    }

    public function test_resolve_zone_returns_unknown_for_a_missing_postcode(): void
    {
        $zone = $this->validator()->resolveZone($this->validInput(['delivery_postcode' => '']));

        $this->assertSame(expected: ShopTotalsService::ZONE_UNKNOWN, actual: $zone);
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function test_country_constant_is_united_kingdom(): void
    {
        $this->assertSame(expected: 'United Kingdom', actual: ShopCheckoutValidator::COUNTRY);
    }
}
