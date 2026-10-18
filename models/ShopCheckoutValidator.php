<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

/**
 * ShopCheckoutValidator
 *
 * Server-side validation of the checkout form. Client-side validation is a
 * convenience for the customer and is not trusted here — every rule that
 * matters runs again on this side.
 *
 * Pure: no database, no config beyond the delivery zones passed in. Errors come
 * back keyed by field name, with messages written for the customer.
 */
class ShopCheckoutValidator
{
    public const COUNTRY = 'United Kingdom';

    public function __construct(private ShopTotalsService $totals) {}

    /**
     * Validate a submitted checkout form.
     *
     * @param array $input Raw submitted fields
     * @return array Errors keyed by field name, empty when the form is good
     */
    public function validate(array $input): array
    {
        $errors = [];

        $errors += $this->validateCustomer($input);
        $errors += $this->validateAddress($input);
        $errors += $this->validateTerms($input);

        return $errors;
    }

    /**
     * The delivery postcode's zone, once it is known to be valid.
     *
     * @param array $input Submitted fields
     * @return string One of ShopTotalsService's ZONE_* constants
     */
    public function resolveZone(array $input): string
    {
        return $this->totals->resolveZone($this->field($input, 'delivery_postcode'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rules
    // ─────────────────────────────────────────────────────────────────────────

    private function validateCustomer(array $input): array
    {
        $errors = [];

        $name = $this->field($input, 'customer_name');
        $nameMissing = \mb_strlen($name) < 2;
        if ($nameMissing) $errors['customer_name'] = 'Please enter your name.';

        $nameTooLong = \mb_strlen($name) > 120;
        if ($nameTooLong) $errors['customer_name'] = 'That name is too long.';

        $email = $this->field($input, 'customer_email');
        $emailInvalid = !\filter_var(value: $email, filter: FILTER_VALIDATE_EMAIL);
        if ($emailInvalid) $errors['customer_email'] = 'Please enter a valid email address — your order confirmation goes here.';

        $emailTooLong = \mb_strlen($email) > 190;
        if ($emailTooLong) $errors['customer_email'] = 'That email address is too long.';

        $phone = $this->field($input, 'customer_phone');
        $phoneTooLong = \mb_strlen($phone) > 40;
        if ($phoneTooLong) $errors['customer_phone'] = 'That phone number is too long.'; // Optional field, so only length is checked

        return $errors;
    }

    private function validateAddress(array $input): array
    {
        $errors = [];

        $address1 = $this->field($input, 'delivery_address1');
        $addressMissing = $address1 === '';
        if ($addressMissing) $errors['delivery_address1'] = 'Please enter your address.';

        $city = $this->field($input, 'delivery_city');
        $cityMissing = $city === '';
        if ($cityMissing) $errors['delivery_city'] = 'Please enter your town or city.';

        $postcodeErrors = $this->validatePostcode($input);

        return $errors + $postcodeErrors;
    }

    /**
     * The postcode carries two separate failures, and they must read
     * differently. A malformed postcode is the customer's typo. A Channel
     * Islands postcode is correct — we simply do not deliver there, and telling
     * someone their own postcode is invalid is both wrong and infuriating.
     */
    private function validatePostcode(array $input): array
    {
        $postcode = $this->field($input, 'delivery_postcode');

        $postcodeMissing = $postcode === '';
        if ($postcodeMissing) return ['delivery_postcode' => 'Please enter your postcode.'];

        $zone = $this->totals->resolveZone($postcode);

        $postcodeUnrecognised = $zone === ShopTotalsService::ZONE_UNKNOWN;
        if ($postcodeUnrecognised) return ['delivery_postcode' => 'That does not look like a UK postcode. Please check it.'];

        $notDeliveredTo = $zone === ShopTotalsService::ZONE_EXCLUDED;
        if ($notDeliveredTo) return ['delivery_postcode' => "We're not able to deliver to the Channel Islands. Please contact us if you'd like to arrange something."];

        return [];
    }

    private function validateTerms(array $input): array
    {
        $accepted = !empty($input['terms_accepted']);

        if (!$accepted) return ['terms_accepted' => 'Please accept the terms and returns policy to continue.'];

        return [];
    }

    private function field(array $input, string $key): string
    {
        return \trim((string)($input[$key] ?? ''));
    }
}
