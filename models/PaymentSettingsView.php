<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Services;

/**
 * Display values for the Payments settings page: the Active Checkout Provider
 * dropdown options and the "your active provider isn't live" warning.
 */
class PaymentSettingsView
{
    /**
     * The Active Checkout Provider dropdown and each gateway's own Enable toggle
     * are two independent switches (the toggle also gates that provider's
     * webhook, which must keep working to reconcile in-flight orders even after
     * the dropdown is switched away from it - see PaymentProviders). That
     * independence means they can disagree, and when they do, checkout fails
     * safe with a vague "payment unavailable" message to the customer. This
     * works out why the active provider isn't actually live, so the mismatch
     * is visible on the page rather than discovered via a customer complaint.
     *
     * @param array<string, array<string, mixed>> $externalProviders  keyed by provider id; each has 'label' and 'ready'
     * @return string|null  HTML for the warning, or null when the active provider is fine (or built in)
     */
    public static function readinessWarning(string $selectedProvider, array $externalProviders): ?string
    {
        $selected = $externalProviders[$selectedProvider] ?? null;
        if (!$selected || $selected['ready']) return null;

        $label = \htmlspecialchars($selected['label']);

        return "The Active Checkout Provider is set to <strong>{$label}</strong>, but it is not ready "
            . '(switched off or missing credentials on its own settings page). Customers reaching checkout right now '
            . 'see &ldquo;Online payment isn&rsquo;t available at the moment&rdquo; and are told to contact you '
            . 'instead of paying.';
    }

    /**
     * Dropdown options: every selectable provider (value, label, selected) and the planned
     * ones, which are shown disabled.
     *
     * @param array<string, string> $providers  value => label
     * @param array<string, string> $planned   value => label
     * @return array{choices: array<int, array{value: string, label: string, selected: bool}>, planned: array<int, array{value: string, label: string}>}
     */
    public static function providerOptions(string $selectedProvider, array $providers, array $planned): array
    {
        $choices = [];
        foreach ($providers as $value => $label) {
            $choices[] = ['value' => (string) $value, 'label' => $label, 'selected' => $selectedProvider === $value];
        }

        $plannedChoices = [];
        foreach ($planned as $value => $label) {
            $plannedChoices[] = ['value' => (string) $value, 'label' => $label];
        }

        return ['choices' => $choices, 'planned' => $plannedChoices];
    }
}
