<?php

declare(strict_types=1);

namespace Tests\Services\Shop;

use Osmium\Core\Library\PaymentProviders;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/PaymentProviders.php';

class PaymentProvidersTest extends TestCase
{
    public function test_configured_provider_is_returned(): void
    {
        $provider = PaymentProviders::configured(['paymentProvider' => 'paypal'], ['paypal' => ['label' => 'PayPal']]);

        $this->assertSame(expected: 'paypal', actual: $provider);
    }

    public function test_missing_setting_falls_back_to_none(): void
    {
        $provider = PaymentProviders::configured([]);

        $this->assertSame(expected: PaymentProviders::NONE, actual: $provider); // The safe default is "take no money"
    }

    public function test_nonsense_provider_falls_back_to_none(): void
    {
        $provider = PaymentProviders::configured(['paymentProvider' => 'not-a-provider']);

        $this->assertSame(expected: PaymentProviders::NONE, actual: $provider);
    }

    public function test_configured_accepts_an_object_config(): void
    {
        $provider = PaymentProviders::configured((object)['paymentProvider' => 'paypal'], ['paypal' => ['label' => 'PayPal']]);

        $this->assertSame(expected: 'paypal', actual: $provider); // Config arrives as stdClass from json_decode
    }

    public function test_a_service_provider_is_accepted_when_its_service_is_installed(): void
    {
        $external = ['stripe' => ['id' => 'stripe', 'label' => 'Stripe', 'ready' => true, 'frontend' => [], 'settingsRoute' => null]];

        $provider = PaymentProviders::configured(['paymentProvider' => 'stripe'], $external);

        $this->assertSame(expected: 'stripe', actual: $provider);
    }

    public function test_a_service_provider_falls_back_to_none_when_its_service_is_gone(): void
    {
        $provider = PaymentProviders::configured(['paymentProvider' => 'stripe']);

        $this->assertSame(expected: PaymentProviders::NONE, actual: $provider); // Uninstalled service: take no money rather than a dead form
    }

    public function test_discounts_are_supported_by_every_provider_but_none(): void
    {
        $this->assertTrue(PaymentProviders::supportsDiscounts('paypal'));
        $this->assertTrue(PaymentProviders::supportsDiscounts('stripe'));
        $this->assertFalse(PaymentProviders::supportsDiscounts(PaymentProviders::NONE));
    }

    public function test_only_installed_providers_are_implemented(): void
    {
        $this->assertFalse(PaymentProviders::isImplemented('paypal')); // Service-provided: only implemented when passed in
        $this->assertFalse(PaymentProviders::isImplemented('stripe'));
        $this->assertTrue(PaymentProviders::isImplemented('paypal', ['paypal' => ['label' => 'PayPal']]));
        $this->assertTrue(PaymentProviders::isImplemented('stripe', ['stripe' => ['label' => 'Stripe']]));
        $this->assertFalse(PaymentProviders::isImplemented('worldpay'));
        $this->assertFalse(PaymentProviders::isImplemented('none'));
    }

    public function test_no_provider_is_both_planned_and_implemented(): void
    {
        $overlap = \array_intersect_key(PaymentProviders::PLANNED, PaymentProviders::IMPLEMENTED);

        $this->assertSame(expected: [], actual: $overlap);
    }

    public function test_labels_are_human_readable(): void
    {
        $this->assertSame(expected: 'PayPal', actual: PaymentProviders::label('paypal', ['paypal' => ['label' => 'PayPal']]));
        $this->assertSame(expected: 'Stripe', actual: PaymentProviders::label('stripe', ['stripe' => ['label' => 'Stripe']]));
        $this->assertSame(expected: 'Worldpay', actual: PaymentProviders::label('worldpay'));
    }

    public function test_a_full_descriptor_is_kept_as_given(): void
    {
        $descriptor = ['id' => 'stripe', 'label' => 'Stripe', 'ready' => true, 'frontend' => ['key' => 'pk_1'], 'settingsRoute' => 'admin/settings/stripe'];

        $providers = PaymentProviders::fromDescriptors([$descriptor]);

        $this->assertSame(expected: ['stripe' => $descriptor + ['logo' => null]], actual: $providers);
    }

    public function test_a_sparse_descriptor_gets_safe_defaults(): void
    {
        $providers = PaymentProviders::fromDescriptors([['id' => 'square']]);

        $this->assertSame(
            expected: ['id' => 'square', 'label' => 'Square', 'ready' => false, 'frontend' => [], 'settingsRoute' => null, 'logo' => null],
            actual: $providers['square'],
        ); // Not ready until the service says so
    }

    public function test_a_logo_under_assets_is_kept(): void
    {
        $providers = PaymentProviders::fromDescriptors([['id' => 'paypal', 'logo' => '/assets/services/paypal/logo.svg']]);

        $this->assertSame(expected: '/assets/services/paypal/logo.svg', actual: PaymentProviders::logo('paypal', $providers));
    }

    public function test_a_logo_outside_assets_is_dropped(): void
    {
        foreach (['https://evil.example/x.svg', 'javascript:alert(1)', '/assets/../secret.svg', '//evil.example/x.svg'] as $logo) {
            $providers = PaymentProviders::fromDescriptors([['id' => 'paypal', 'logo' => $logo]]);

            $this->assertNull(PaymentProviders::logo('paypal', $providers), $logo);
        }
    }

    public function test_a_provider_that_is_not_installed_has_no_logo(): void
    {
        $this->assertNull(PaymentProviders::logo('stripe', []));
    }

    public function test_descriptors_with_unusable_ids_are_dropped(): void
    {
        $providers = PaymentProviders::fromDescriptors([['id' => ''], ['label' => 'No id'], ['id' => 'none'], ['id' => 'paypal']]);

        $this->assertSame(expected: ['paypal'], actual: \array_keys($providers)); // 'none' is reserved for "take no money"
    }

    public function test_a_later_descriptor_with_the_same_id_replaces_the_earlier(): void
    {
        $providers = PaymentProviders::fromDescriptors([['id' => 'paypal', 'label' => 'Old'], ['id' => 'paypal', 'label' => 'New']]);

        $this->assertSame(expected: 'New', actual: $providers['paypal']['label']);
    }

    public function test_no_descriptors_means_no_providers(): void
    {
        $this->assertSame(expected: [], actual: PaymentProviders::fromDescriptors([]));
    }
}
