<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class FreemiumLocalePolicy
{
    public const PAYLOAD_KEY = 'locale_freemium_policy';

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $scaleCode, ?string $locale, ?CarbonInterface $now = null): array
    {
        $scaleCode = $this->normalizeScale($scaleCode);
        $localeRequested = $this->stringOrNull($locale);
        $locale = $this->normalizeLocale($localeRequested);
        $scaleConfig = $this->scaleConfig($scaleCode);
        $enabled = (bool) config('freemium_locale_policy.enabled', true);

        $base = [
            'schema_version' => (string) config('freemium_locale_policy.schema_version', 'freemium_locale_policy.v1'),
            'policy_source' => (string) config('freemium_locale_policy.policy_source', 'backend/config/freemium_locale_policy.php'),
            'authority' => 'backend',
            'enabled' => $enabled,
            'applies' => false,
            'scale_code' => $scaleCode,
            'locale_requested' => $localeRequested,
            'locale' => $locale,
            'locale_family' => $this->localeFamily($locale),
            'policy' => 'not_configured',
            'report_access_level' => null,
            'report_access' => [
                'free_modules' => [],
                'paid_modules' => [],
            ],
            'sku' => null,
            'upgrade_sku' => null,
            'price_cents' => null,
            'currency' => null,
            'paywall_allowed' => false,
            'order_creation_allowed' => false,
            'free_until' => null,
            'english_free_active' => false,
            'mismatch_stop_conditions' => [],
            'stop_conditions' => [],
            'frontend_contract' => (array) config('freemium_locale_policy.frontend_contract', []),
        ];

        if (! $enabled || $scaleConfig === [] || ! (bool) ($scaleConfig['enabled'] ?? true)) {
            return $base;
        }

        $base['applies'] = true;
        $base['mismatch_stop_conditions'] = array_values((array) ($scaleConfig['mismatch_stop_conditions'] ?? []));

        if ($locale === null) {
            $base['policy'] = 'locale_required';
            $base['stop_conditions'] = ['locale_missing_for_policy_scale'];

            return $base;
        }

        $localeConfig = $this->localeConfig($scaleConfig, $locale);
        if ($localeConfig === []) {
            $base['policy'] = 'unsupported_locale';
            $base['stop_conditions'] = ['unsupported_locale_for_policy_scale'];

            return $base;
        }

        $policy = array_merge($base, [
            'locale' => $this->normalizeLocaleKey((string) ($localeConfig['locale_key'] ?? $locale)),
            'locale_family' => (string) ($localeConfig['locale_family'] ?? $this->localeFamily($locale)),
            'policy' => (string) ($localeConfig['policy'] ?? 'not_configured'),
            'report_access_level' => $this->stringOrNull($localeConfig['report_access_level'] ?? null),
            'report_access' => [
                'free_modules' => $this->normalizeStringList($localeConfig['free_modules'] ?? []),
                'paid_modules' => $this->normalizeStringList($localeConfig['paid_modules'] ?? []),
            ],
            'sku' => $this->normalizeSku($localeConfig['sku'] ?? null),
            'upgrade_sku' => $this->normalizeSku($localeConfig['upgrade_sku'] ?? null),
            'price_cents' => isset($localeConfig['price_cents']) ? (int) $localeConfig['price_cents'] : null,
            'currency' => $this->normalizeCurrency($localeConfig['currency'] ?? null),
            'paywall_allowed' => (bool) ($localeConfig['paywall_allowed'] ?? false),
            'order_creation_allowed' => (bool) ($localeConfig['order_creation_allowed'] ?? false),
            'free_until' => $this->stringOrNull($localeConfig['free_until'] ?? null),
        ]);

        if ($policy['policy'] === 'free_until') {
            $freeUntil = $this->parseFreeUntil($policy['free_until']);
            $active = $freeUntil !== null && ($now ?? Carbon::now())->lessThanOrEqualTo($freeUntil->endOfDay());
            $policy['english_free_active'] = $active;
            if (! $active) {
                $policy['stop_conditions'][] = 'english_free_until_expired';
                $policy['paywall_allowed'] = false;
                $policy['order_creation_allowed'] = false;
            }
        }

        return $policy;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function filterSkuItems(array $items, string $scaleCode, ?string $locale): array
    {
        if ($this->stringOrNull($locale) === null) {
            return array_values($items);
        }

        $policy = $this->resolve($scaleCode, $locale);
        if (! (bool) ($policy['applies'] ?? false)) {
            return array_values($items);
        }

        if (! (bool) ($policy['paywall_allowed'] ?? false)) {
            return [];
        }

        $allowedSku = $this->normalizeSku($policy['sku'] ?? null);
        if ($allowedSku === null) {
            return [];
        }

        $allowedCurrency = $this->normalizeCurrency($policy['currency'] ?? null);
        $allowedPrice = isset($policy['price_cents']) ? (int) $policy['price_cents'] : null;

        return array_values(array_filter($items, function (array $item) use ($allowedSku, $allowedCurrency, $allowedPrice): bool {
            $sku = $this->normalizeSku($item['sku'] ?? null);
            if ($sku !== $allowedSku) {
                return false;
            }

            if ($allowedCurrency !== null && $this->normalizeCurrency($item['currency'] ?? null) !== $allowedCurrency) {
                return false;
            }

            return $allowedPrice === null || (int) ($item['price_cents'] ?? -1) === $allowedPrice;
        }));
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function grantsFullFree(array $policy): bool
    {
        return (bool) ($policy['applies'] ?? false)
            && (string) ($policy['policy'] ?? '') === 'free_until'
            && (bool) ($policy['english_free_active'] ?? false)
            && (string) ($policy['report_access_level'] ?? '') === 'full'
            && ! (bool) ($policy['paywall_allowed'] ?? false)
            && (array) ($policy['stop_conditions'] ?? []) === [];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function frontendPayload(array $policy): array
    {
        return [
            'schema_version' => $policy['schema_version'] ?? 'freemium_locale_policy.v1',
            'policy_source' => $policy['policy_source'] ?? 'backend/config/freemium_locale_policy.php',
            'authority' => 'backend',
            'enabled' => (bool) ($policy['enabled'] ?? false),
            'applies' => (bool) ($policy['applies'] ?? false),
            'scale_code' => $policy['scale_code'] ?? null,
            'locale_requested' => $policy['locale_requested'] ?? null,
            'locale' => $policy['locale'] ?? null,
            'locale_family' => $policy['locale_family'] ?? null,
            'policy' => $policy['policy'] ?? null,
            'report_access_level' => $policy['report_access_level'] ?? null,
            'report_access' => $policy['report_access'] ?? ['free_modules' => [], 'paid_modules' => []],
            'sku' => $policy['sku'] ?? null,
            'upgrade_sku' => $policy['upgrade_sku'] ?? null,
            'price_cents' => $policy['price_cents'] ?? null,
            'currency' => $policy['currency'] ?? null,
            'paywall_allowed' => (bool) ($policy['paywall_allowed'] ?? false),
            'order_creation_allowed' => (bool) ($policy['order_creation_allowed'] ?? false),
            'free_until' => $policy['free_until'] ?? null,
            'english_free_active' => (bool) ($policy['english_free_active'] ?? false),
            'mismatch_stop_conditions' => array_values((array) ($policy['mismatch_stop_conditions'] ?? [])),
            'stop_conditions' => array_values((array) ($policy['stop_conditions'] ?? [])),
            'frontend_contract' => $policy['frontend_contract'] ?? [],
        ];
    }

    /**
     * @return array{ok:bool,error_code:?string,message:?string,status:int,policy:array<string,mixed>}
     */
    public function validateOrderRequest(
        string $scaleCode,
        ?string $attemptLocale,
        ?string $requestedLocale,
        ?string $requestedSku,
        ?string $effectiveSku,
        ?string $currency,
        ?int $priceCents
    ): array {
        $policy = $this->resolve($scaleCode, $attemptLocale ?? $requestedLocale);
        if (! (bool) ($policy['applies'] ?? false)) {
            return $this->allow($policy);
        }

        $normalizedAttemptLocale = $this->normalizeLocale($attemptLocale);
        if ($normalizedAttemptLocale === null) {
            return $this->deny($policy, 'LOCALE_POLICY_LOCALE_REQUIRED', 'locale is required for this SKU policy.');
        }

        $normalizedRequestedLocale = $this->normalizeLocale($requestedLocale);
        if ($normalizedRequestedLocale !== null
            && $this->localeFamily($normalizedRequestedLocale) !== $this->localeFamily($normalizedAttemptLocale)) {
            return $this->deny($policy, 'LOCALE_POLICY_LOCALE_MISMATCH', 'requested locale does not match attempt locale.');
        }

        if ((array) ($policy['stop_conditions'] ?? []) !== []) {
            return $this->deny($policy, 'LOCALE_POLICY_STOP_CONDITION', 'locale policy stop condition matched.');
        }

        if (! (bool) ($policy['order_creation_allowed'] ?? false)) {
            return $this->deny($policy, 'LOCALE_POLICY_ORDER_NOT_ALLOWED', 'order creation is not allowed for this locale policy.');
        }

        $allowedSku = $this->normalizeSku($policy['sku'] ?? null);
        $allowedUpgradeSku = $this->normalizeSku($policy['upgrade_sku'] ?? null);
        $requestedSku = $this->normalizeSku($requestedSku);
        $effectiveSku = $this->normalizeSku($effectiveSku);
        $skuAllowed = in_array($requestedSku, [$allowedSku, $allowedUpgradeSku], true)
            || in_array($effectiveSku, [$allowedSku, $allowedUpgradeSku], true);
        if (! $skuAllowed) {
            return $this->deny($policy, 'LOCALE_POLICY_SKU_NOT_ALLOWED', 'SKU is not allowed for this locale policy.');
        }

        $allowedCurrency = $this->normalizeCurrency($policy['currency'] ?? null);
        if ($allowedCurrency !== null && $this->normalizeCurrency($currency) !== $allowedCurrency) {
            return $this->deny($policy, 'LOCALE_POLICY_CURRENCY_MISMATCH', 'SKU currency is not allowed for this locale policy.');
        }

        $allowedPrice = isset($policy['price_cents']) ? (int) $policy['price_cents'] : null;
        if ($allowedPrice !== null && $priceCents !== null && $priceCents !== $allowedPrice) {
            return $this->deny($policy, 'LOCALE_POLICY_PRICE_MISMATCH', 'SKU price is not allowed for this locale policy.');
        }

        return $this->allow($policy);
    }

    /**
     * @return array<string,mixed>
     */
    private function scaleConfig(string $scaleCode): array
    {
        $scales = (array) config('freemium_locale_policy.scales', []);
        $config = $scales[$scaleCode] ?? null;

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string,mixed>  $scaleConfig
     * @return array<string,mixed>
     */
    private function localeConfig(array $scaleConfig, string $locale): array
    {
        $locales = (array) ($scaleConfig['locales'] ?? []);
        $localeKey = $this->localeConfigKey($locale);
        $config = $locales[$localeKey] ?? null;
        if (! is_array($config)) {
            return [];
        }

        $config['locale_key'] = $localeKey;

        return $config;
    }

    private function normalizeScale(string $scaleCode): string
    {
        return strtoupper(trim($scaleCode));
    }

    private function normalizeLocale(mixed $locale): ?string
    {
        $value = $this->stringOrNull($locale);
        if ($value === null) {
            return null;
        }

        $lower = strtolower(str_replace('_', '-', $value));
        if (str_starts_with($lower, 'zh')) {
            return 'zh-CN';
        }
        if (str_starts_with($lower, 'en')) {
            return 'en';
        }

        return $lower;
    }

    private function normalizeLocaleKey(string $locale): string
    {
        return $this->normalizeLocale($locale) ?? $locale;
    }

    private function localeConfigKey(string $locale): string
    {
        return $this->normalizeLocale($locale) ?? $locale;
    }

    private function localeFamily(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        return str_starts_with(strtolower($locale), 'zh') ? 'zh' : (str_starts_with(strtolower($locale), 'en') ? 'en' : 'unsupported');
    }

    private function normalizeSku(mixed $sku): ?string
    {
        $value = $this->stringOrNull($sku);

        return $value !== null ? strtoupper($value) : null;
    }

    private function normalizeCurrency(mixed $currency): ?string
    {
        $value = $this->stringOrNull($currency);

        return $value !== null ? strtoupper($value) : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            $string = $this->stringOrNull($value);
            if ($string !== null) {
                $out[] = $string;
            }
        }

        return array_values(array_unique($out));
    }

    private function parseFreeUntil(?string $freeUntil): ?CarbonInterface
    {
        if ($freeUntil === null) {
            return null;
        }

        try {
            return Carbon::parse($freeUntil);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array{ok:bool,error_code:?string,message:?string,status:int,policy:array<string,mixed>}
     */
    private function allow(array $policy): array
    {
        return [
            'ok' => true,
            'error_code' => null,
            'message' => null,
            'status' => 200,
            'policy' => $this->frontendPayload($policy),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array{ok:bool,error_code:string,message:string,status:int,policy:array<string,mixed>}
     */
    private function deny(array $policy, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'status' => 422,
            'policy' => $this->frontendPayload($policy),
        ];
    }
}
