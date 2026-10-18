<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Core\Library\CustomerNotifications;
use Osmium\Core\Library\Input;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Admin\Services\ConfigJson;

require_once __DIR__ . '/../models/ShopSettingsBase.php';

/**
 * Shop settings page (/admin/settings/shop/): prices, tax, display options and the customer notification matrix.
 */
class ShopSettingsController extends ShopSettingsBase
{
    /**
     * Shop settings page.
     */
    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleShopSubmit();

        $smsProvider = $this->smsProvider();
        $this->data['admin']['notifications'] = [
            'events' => CustomerNotifications::EVENTS,
            'transports' => CustomerNotifications::TRANSPORTS,
            'settings' => CustomerNotifications::settings(),
            'smsProvider' => $smsProvider,
            'smsReady' => $smsProvider['ready'] ?? false,
        ];

        $this->setView('settings/shop.phtml');
    }

    private function handleShopSubmit()
    {
        $this->validateCsrfOrRedirect('settings/shop/');

        $configContent = \file_get_contents($this->shopConfigFilePath);

        $searchEnabled = Input::post()->has(key: 'search_enabled');
        $currency = Input::post()->stringOr(key: 'currency', fallback: $this->config['shop']['currency']);
        $currencySign = Input::post()->stringOr(key: 'currency_sign', fallback: $this->config['shop']['currencySign']);
        $taxRate = Input::post()->stringOr(key: 'tax_rate', fallback: $this->config['shop']['taxRatePercent']);
        $taxName = Input::post()->stringOr(key: 'tax_name', fallback: $this->config['shop']['taxName']);
        $currentCountry = (string)($this->config['shop']['country'] ?? '');
        $submittedCountry = Input::post()->stringOr(key: 'country', fallback: $currentCountry);
        $submittedCountry = \strtoupper($submittedCountry);
        $countryValid = (bool)\preg_match('/^[A-Z]{2}$/', $submittedCountry);
        $country = $countryValid ? $submittedCountry : $currentCountry;
        if (!$countryValid) $_SESSION['settings_error'] = 'Country must be a two-letter code such as GB - left unchanged.';
        $currentDeliveryMessage = $this->config['shop']['deliveryMessage'];
        $deliveryMessage = Input::post()->stringOr(key: 'delivery_message', fallback: $currentDeliveryMessage);
        $basketHours = Input::post()->int(key: 'basket_abandoned_hours', default: 2); // Shop settings

        $configContent = ConfigJson::withValue($configContent, 'searchEnabled', $searchEnabled);
        $configContent = ConfigJson::withValue($configContent, 'currency', $currency);
        $configContent = ConfigJson::withValue($configContent, 'currencySign', $currencySign);
        $configContent = ConfigJson::withValue($configContent, 'taxRatePercent', $taxRate);
        $configContent = ConfigJson::withValue($configContent, 'taxName', $taxName);
        $configContent = ConfigJson::withValue($configContent, 'country', $country);
        $configContent = ConfigJson::withValue($configContent, 'deliveryMessage', $deliveryMessage);
        $configContent = ConfigJson::withValue($configContent, 'basketAbandonedHours', $basketHours);

        $catShowCounts = Input::post()->has(key: 'category_show_counts');
        $catShowSummary = Input::post()->has(key: 'category_show_summary_text');
        $catShowCta = Input::post()->has(key: 'category_show_cta'); // Category display settings

        $configContent = ConfigJson::withValue($configContent, 'categoryShowCounts', $catShowCounts);
        $configContent = ConfigJson::withValue($configContent, 'categoryShowSummaryText', $catShowSummary);
        $configContent = ConfigJson::withValue($configContent, 'categoryShowCallToAction', $catShowCta);

        $prodShowSummary = Input::post()->has(key: 'product_show_summary');
        $prodShowPriceExc = Input::post()->has(key: 'product_show_price_exc_tax');
        $prodShowDelivery = Input::post()->has(key: 'product_show_delivery_message'); // Product display settings

        $configContent = ConfigJson::withValue($configContent, 'productShowSummary', $prodShowSummary);
        $configContent = ConfigJson::withValue($configContent, 'productShowPriceExcTax', $prodShowPriceExc);
        $configContent = ConfigJson::withValue($configContent, 'productShowDeliveryMessage', $prodShowDelivery);

        $configContent = ConfigJson::withValue($configContent, 'customerNotifications', $this->postedNotifications());

        $this->admin->model->changelog->log(
            description: 'Updated shop settings',
            recordType: 'settings',
        );

        $this->saveConfigAndRedirect($configContent, 'settings/shop/', $this->shopConfigFilePath);
    }

    /**
     * The event-by-transport matrix as posted. An unavailable transport
     * (SMS with no ready provider) shows greyed out, so the browser does not
     * post it; its saved ticks are kept rather than wiped.
     *
     * @return array<string, array<string, bool>>
     */
    private function postedNotifications(): array
    {
        $saved = CustomerNotifications::settings();
        $smsReady = ($this->smsProvider()['ready'] ?? false) === true;

        $matrix = [];
        foreach (\array_keys(CustomerNotifications::EVENTS) as $event) {
            foreach (\array_keys(CustomerNotifications::TRANSPORTS) as $transport) {
                $unavailable = $transport === CustomerNotifications::SMS && !$smsReady;
                $matrix[$event][$transport] = $unavailable
                    ? $saved[$event][$transport]
                    : Input::post()->has(key: "notify_{$event}_{$transport}");
            }
        }

        return $matrix;
    }

    private function smsProvider(): ?array
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));

        return CustomerNotifications::smsProvider($hooks);
    }
}
