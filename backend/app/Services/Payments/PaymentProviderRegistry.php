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

        $providerConfig = config('payments.providers.'.$provider, []);
        $explicitEnabled = (bool) (is_array($providerConfig) ? ($providerConfig['enabled'] ?? false) : false);
        if ($explicitEnabled) {
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
