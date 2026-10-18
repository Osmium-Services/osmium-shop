<?php

declare(strict_types=1);

namespace Osmium\Core\Library;

use Osmium\Core\Models\ServiceModel;

/**
 * PaymentProviders
 *
 * A registry of which payment providers exist and which one is switched on.
 * Not a payment abstraction — deliberately: it holds no interface, no shared
 * lifecycle, no knowledge of how anybody takes money. It answers two config
 * questions only:
 *
 *   - which providers does this codebase actually implement?
 *   - which one has been chosen in admin?
 *
 * Defaults to `none` — an unset or unreadable config value must mean "take
 * no money", not "start taking payment through a module nobody has finished
 * wiring up".
 *
 * No provider is built in. Each is an installed service answering the
 * `payment.providers` hook (see ServiceHooks) — core never names one. Their
 * ids, labels and readiness arrive as the `$external` map, from external().
 */
class PaymentProviders
{
    public const NONE = 'none';

    /**
     * Providers built into this codebase, as value => label. Empty: every
     * provider comes from an installed service, added by all().
     */
    public const IMPLEMENTED = [];

    /**
     * Providers that are documented but not built, shown in admin as unavailable.
     */
    public const PLANNED = [];

    /** @var array<string, array>|null per-request memo of external() */
    private static ?array $externalMemo = null;

    /**
     * Payment providers supplied by installed services.
     *
     * Each `payment.providers` handler returns one descriptor:
     *   id (string), label (string), ready (bool: switched on and usable),
     *   frontend (array: whatever the checkout page needs, e.g. a publishable
     *   key), settingsRoute (?string: admin path of its settings page).
     * Best-effort: a broken handler is skipped and its provider is simply
     * absent, which fails safe to "no payment provider".
     *
     * @return array<string, array{id: string, label: string, ready: bool, frontend: array, settingsRoute: ?string}> keyed by id
     */
    public static function external(object $osmium): array
    {
        if (self::$externalMemo !== null) return self::$externalMemo;

        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($osmium->dataSource)));
        $descriptors = $hooks->collect('payment.providers', ['osmium' => $osmium]);

        self::$externalMemo = self::fromDescriptors($descriptors);

        return self::$externalMemo;
    }

    /**
     * Services that take payment on their own hosted page (e.g. a hosted cart), so
     * the site has no checkout of its own to choose a provider for.
     *
     * Each `payment.hosted` handler returns {label: string, settingsRoute: ?string}.
     * Best-effort, like external(): a broken handler is skipped.
     *
     * @return array<int, array{label: string, settingsRoute: ?string}>
     */
    public static function hosted(object $osmium): array
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($osmium->dataSource)));

        $hosted = [];
        foreach ($hooks->collect('payment.hosted', ['osmium' => $osmium]) as $descriptor) {
            $label = (string)($descriptor['label'] ?? '');
            if ($label === '') continue;

            $hosted[] = ['label' => $label, 'settingsRoute' => $descriptor['settingsRoute'] ?? null];
        }

        return $hosted;
    }

    /**
     * Turn the descriptors the payment.providers handlers returned into the
     * providers map, dropping any with an unusable id.
     *
     * @param array $descriptors One entry per handler
     * @return array<string, array{id: string, label: string, ready: bool, frontend: array, settingsRoute: ?string}> keyed by id
     */
    public static function fromDescriptors(array $descriptors): array
    {
        $providers = [];
        foreach ($descriptors as $descriptor) {
            $id = (string)($descriptor['id'] ?? '');
            $usable = $id !== '' && $id !== self::NONE && !\array_key_exists($id, self::IMPLEMENTED);
            if (!$usable) {
                \error_log("Ignoring payment provider descriptor with unusable id '{$id}'");
                continue;
            }

            $providers[$id] = [
                'id' => $id,
                'label' => (string)($descriptor['label'] ?? \ucfirst($id)),
                'ready' => (bool)($descriptor['ready'] ?? false),
                'frontend' => (array)($descriptor['frontend'] ?? []),
                'settingsRoute' => $descriptor['settingsRoute'] ?? null,
            ];
        }

        return $providers;
    }

    /**
     * Every provider that can be chosen in admin, as value => label.
     *
     * @param array<string, array> $external From external()
     * @return array<string, string>
     */
    public static function all(array $external = []): array
    {
        $labels = self::IMPLEMENTED;
        foreach ($external as $id => $descriptor) {
            $labels[$id] = $descriptor['label'];
        }

        return $labels;
    }

    /**
     * The provider chosen in admin.
     *
     * Falls back to `none`, which is always a safe, working state — the
     * in-house checkout simply takes no money until a provider is chosen and
     * configured. That includes a chosen service provider whose service has
     * since been uninstalled or disabled.
     *
     * @param object|array $checkoutConfig The 'checkout' block from checkout.json.php
     * @param array<string, array> $external From external()
     */
    public static function configured(object|array $checkoutConfig, array $external = []): string
    {
        $config = (array)$checkoutConfig;
        $chosen = (string)($config['paymentProvider'] ?? self::NONE);

        $isImplemented = \array_key_exists(key: $chosen, array: self::all($external));
        if (!$isImplemented) {
            if ($chosen !== self::NONE) {
                \error_log("Checkout config names payment provider '{$chosen}', which is not implemented — falling back to no payment provider");
            }
            return self::NONE;
        }

        return $chosen;
    }

    /**
     * Does choosing this provider switch on discounts? Every working provider
     * prices from the same server-side totals, so all do; `none` takes no money.
     */
    public static function supportsDiscounts(string $provider): bool
    {
        return $provider !== self::NONE;
    }

    /**
     * Is this a provider we can take money with?
     *
     * @param array<string, array> $external From external()
     */
    public static function isImplemented(string $provider, array $external = []): bool
    {
        return \array_key_exists(key: $provider, array: self::all($external));
    }

    /**
     * Human label for a provider value, for admin and order screens.
     *
     * @param array<string, array> $external From external()
     */
    public static function label(string $provider, array $external = []): string
    {
        return self::all($external)[$provider] ?? self::PLANNED[$provider] ?? \ucfirst($provider);
    }
}
