<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Yansongda\Pay\Pay;

final class PaymentProviderRegistry
{
    /**
     * @return array<int, string>
     */
    public function enabledProviders(): array
    {
        $providers = [];
        $configured = config('payments.providers', []);
        if (is_array($configured)) {
            foreach ($configured as $provider => $providerConfig) {
                if (! is_string($provider)) {
                    continue;
                }

                $provider = strtolower(trim($provider));
                if ($provider === '') {
                    continue;
                }

                if ($provider === 'stub' && ! $this->isStubEnabled()) {
                    continue;
                }

                if ($this->isEnabled($provider)) {
                    $providers[] = $provider;
                }
            }
        }

        if ($providers === []) {
            $providers = ['stripe', 'billing'];
            if ($this->isStubEnabled()) {
                $providers[] = 'stub';
            }
        }

        return array_values(array_unique($providers));
    }

    public function isEnabled(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return false;
        }

        // LemonSqueezy signs only the raw body in the current provider contract.
        // Without a provider-authenticated timestamp, production cannot enforce
        // webhook freshness, so checkout and webhook entry points stay disabled.
        if ($provider === 'lemonsqueezy' && app()->environment('production')) {
            return false;
        }

        $providerConfig = config('payments.providers.'.$provider, []);
        $explicitEnabled = (bool) (is_array($providerConfig) ? ($providerConfig['enabled'] ?? false) : false);
        if ($explicitEnabled) {
            if ($provider === 'wechat_mini_virtual') {
                return $this->isWechatMiniVirtualConfigured();
            }
            if ($provider === 'apple_iap') {
                return $this->isAppleIapConfigured();
            }

            return $provider !== 'stub' || $this->isStubEnabled();
        }

        $autoEnable = (bool) (is_array($providerConfig) ? ($providerConfig['auto_enable_when_configured'] ?? false) : false);
        if (! $autoEnable) {
            return false;
        }

        return match ($provider) {
            'wechatpay' => $this->isWechatPayConfigured(),
            'alipay' => $this->isAlipayConfigured(),
            default => false,
        };
    }

    public function isWechatMiniVirtualConfigured(): bool
    {
        $config = config('payments.wechat_mini_virtual', []);
        if (! is_array($config)) {
            return false;
        }

        foreach (['app_id', 'app_secret', 'offer_id', 'app_key', 'callback_token', 'product_id'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        if (! in_array((int) ($config['environment'] ?? -1), [0, 1], true)) {
            return false;
        }

        if (trim((string) ($config['mode'] ?? '')) !== 'short_series_goods') {
            return false;
        }

        $expectedSku = strtoupper(trim((string) ($config['sku'] ?? '')));
        $rolloutSku = strtoupper(trim((string) config('report_unlock.sku_by_scale.MBTI', '')));

        return $expectedSku !== ''
            && $expectedSku === $rolloutSku
            && (int) ($config['price_cents'] ?? 0) === (int) config('report_unlock.price_cents', 199)
            && strtoupper(trim((string) config('report_unlock.currency', 'CNY'))) === 'CNY'
            && (bool) config('report_unlock.providers.wechat_mini_virtual.available', false);
    }

    public function isAppleIapConfigured(): bool
    {
        if (! $this->canAcceptWebhook('apple_iap')) {
            return false;
        }

        $config = config('payments.apple_iap', []);
        foreach (['offer_id', 'product_id'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }
        if (trim((string) ($config['mode'] ?? '')) !== 'short_series_goods') {
            return false;
        }

        $expectedSku = strtoupper(trim((string) ($config['sku'] ?? '')));
        $rolloutSku = strtoupper(trim((string) config('report_unlock.sku_by_scale.MBTI', '')));

        return $expectedSku !== ''
            && $expectedSku === $rolloutSku
            && (int) ($config['price_cents'] ?? 0) === (int) config('report_unlock.price_cents', 199)
            && (int) ($config['price_cents'] ?? 0) >= 100
            && strtoupper(trim((string) config('report_unlock.currency', 'CNY'))) === 'CNY'
            && (bool) config('report_unlock.providers.apple_iap.available', false);
    }

    public function canProcessSettlement(string $provider): bool
    {
        $provider = strtolower(trim($provider));

        return $provider === 'apple_iap'
            ? $this->canProcessAppleIapSettlement()
            : $this->isEnabled($provider);
    }

    public function canAcceptWebhook(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider !== 'apple_iap') {
            return $this->isEnabled($provider);
        }

        return $this->canProcessAppleIapSettlement()
            && trim((string) config('payments.apple_iap.callback_token', '')) !== '';
    }

    private function canProcessAppleIapSettlement(): bool
    {
        $config = config('payments.apple_iap', []);
        if (! is_array($config)) {
            return false;
        }

        foreach (['app_id', 'app_secret', 'app_key'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        return (int) ($config['environment'] ?? -1) === 0;
    }

    private function isWechatPayConfigured(): bool
    {
        if (! class_exists(Pay::class)) {
            return false;
        }

        $wechat = config('pay.wechat.default', []);
        if (! is_array($wechat)) {
            return false;
        }

        $appId = trim((string) ($wechat['mp_app_id'] ?? ''));
        if ($appId === '') {
            $appId = trim((string) ($wechat['app_id'] ?? ''));
        }
        if ($appId === '') {
            $appId = trim((string) ($wechat['mini_app_id'] ?? ''));
        }

        if ($appId === '') {
            return false;
        }

        if (trim((string) ($wechat['mch_id'] ?? '')) === '') {
            return false;
        }

        $mchSecretKey = trim((string) ($wechat['mch_secret_key'] ?? ''));
        if ($mchSecretKey === '' || strlen($mchSecretKey) !== 32) {
            return false;
        }

        return $this->isReadableCertInput($wechat['mch_secret_cert'] ?? null)
            && $this->isReadableCertInput($wechat['mch_public_cert_path'] ?? null)
            && $this->isReadableCertInput($wechat['wechat_public_cert_path'] ?? null);
    }

    private function isAlipayConfigured(): bool
    {
        if (! class_exists(Pay::class)) {
            return false;
        }

        $alipay = config('pay.alipay.default', []);
        if (! is_array($alipay)) {
            return false;
        }

        if (trim((string) ($alipay['app_id'] ?? '')) === '') {
            return false;
        }

        $hasMerchantPrivateKey = trim((string) ($alipay['merchant_private_key'] ?? '')) !== ''
            || $this->isReadableCertInput($alipay['merchant_private_key_path'] ?? null);

        if (! $hasMerchantPrivateKey) {
            return false;
        }

        return $this->isReadableCertInput($alipay['app_public_cert_path'] ?? null)
            && $this->isReadableCertInput($alipay['alipay_public_cert_path'] ?? null)
            && $this->isReadableCertInput($alipay['alipay_root_cert_path'] ?? null);
    }

    private function isReadableCertInput(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return false;
        }

        if (str_starts_with($raw, '-----BEGIN')) {
            return true;
        }

        $path = $this->resolvePath($raw);

        return is_file($path) && is_readable($path) && filesize($path) > 0;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }
}
