<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Core\Library\Input;
use Osmium\Core\Library\PaymentProviders;
use Osmium\Modules\Admin\Services\ConfigJson;
use Osmium\Modules\Admin\Services\PaymentSettingsView;

require_once __DIR__ . '/../models/ShopSettingsBase.php';

/**
 * Payment settings page (/admin/settings/payments/): which payment provider the checkout uses.
 */
class PaymentSettingsController extends ShopSettingsBase
{
    /**
     * Payments settings page.
     */
    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handlePaymentsSubmit();

        $externalProviders = PaymentProviders::external($this->osmium);
        $selectedProvider = $this->data['admin']['config']['checkout']['paymentProvider'] ?? 'none';

        $this->data['admin']['externalProviders'] = $externalProviders;
        $this->data['admin']['hostedPayments'] = PaymentProviders::hosted($this->osmium);
        $this->data['admin']['providerReadinessWarning'] = PaymentSettingsView::readinessWarning($selectedProvider, $externalProviders);
        $this->data['admin']['providerOptions'] = PaymentSettingsView::providerOptions(
            selectedProvider: $selectedProvider,
            providers: PaymentProviders::all($externalProviders),
            planned: PaymentProviders::PLANNED,
        );

        $this->setView('settings/payments.phtml');
    }

    private function handlePaymentsSubmit()
    {
        $this->validateCsrfOrRedirect('settings/payments/');

        $checkoutContent = \file_get_contents($this->checkoutConfigFilePath);
        $paymentProvider = Input::post()->stringOr(key: 'payment_provider', fallback: 'none'); // Provider switch
        $checkoutContent = ConfigJson::withValue($checkoutContent, 'paymentProvider', $paymentProvider);

        ConfigJson::save($checkoutContent, $this->checkoutConfigFilePath);

        $this->admin->model->changelog->log(
            description: 'Updated payment settings',
            recordType: 'settings',
        );

        $_SESSION['settings_saved'] = true;
        $this->redirect('settings/payments/');
    }
}
