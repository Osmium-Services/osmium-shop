<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Services;

use Osmium\Modules\Admin\Services\PaymentSettingsView;
use PHPUnit\Framework\TestCase;

require_once 'app/services/osmium-shop/models/PaymentSettingsView.php';

/**
 * PaymentSettingsView prepares the Payments settings page's display values.
 */
class PaymentSettingsViewTest extends TestCase
{
    public function testNoWarningWhenTheActiveProviderIsReady(): void
    {
        $external = ['stripe' => ['label' => 'Stripe', 'ready' => true]];

        $this->assertNull(PaymentSettingsView::readinessWarning('stripe', $external));
    }

    public function testNoWarningForBuiltInOrUnknownProviders(): void
    {
        $external = ['stripe' => ['label' => 'Stripe', 'ready' => false]];

        $this->assertNull(PaymentSettingsView::readinessWarning('none', $external));
        $this->assertNull(PaymentSettingsView::readinessWarning('gone', $external));
    }

    public function testWarningNamesTheProviderThatIsNotReady(): void
    {
        $external = ['paypal' => ['label' => 'Pay<Pal>', 'ready' => false]];

        $warning = PaymentSettingsView::readinessWarning('paypal', $external);

        $this->assertStringContainsString('<strong>Pay&lt;Pal&gt;</strong>', $warning, 'the label is escaped');
        $this->assertStringContainsString('not ready', $warning);
    }

    public function testProviderOptionsMarkTheSelectedOneAndKeepPlannedSeparate(): void
    {
        $options = PaymentSettingsView::providerOptions(
            selectedProvider: 'stripe',
            providers: ['none' => 'None', 'stripe' => 'Stripe'],
            planned: ['square' => 'Square'],
        );

        $this->assertSame([
            ['value' => 'none', 'label' => 'None', 'selected' => false],
            ['value' => 'stripe', 'label' => 'Stripe', 'selected' => true],
        ], $options['choices']);
        $this->assertSame([['value' => 'square', 'label' => 'Square']], $options['planned']);
    }
}
