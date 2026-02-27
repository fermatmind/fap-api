<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Services\Ops\GoLiveGateService;
use Tests\TestCase;

final class GoLiveGateServiceTest extends TestCase
{
    public function test_only_lemonsqueezy_enabled_and_configured_passes_payment_group_checks(): void
    {
        $this->setPaymentProviders(['lemonsqueezy']);
        config([
            'ops.go_live_gate.payment_policy' => 'enabled_only',
            'ops.go_live_gate.payment_refund_drill_ok' => true,
            'services.lemonsqueezy.api_key' => 'ls_test_key',
            'services.lemonsqueezy.store_id' => 'store_001',
            'services.lemonsqueezy.variant_id' => 'variant_001',
            'services.lemonsqueezy.webhook_secret' => 'ls_whsec_001',
        ]);

        $checks = $this->paymentChecksByKey();
        $this->assertTrue((bool) ($checks['provider_lemonsqueezy_configured']['passed'] ?? false));
        $this->assertTrue((bool) ($checks['region_cn_provider_available']['passed'] ?? false));
        $this->assertTrue((bool) ($checks['region_us_provider_available']['passed'] ?? false));
        $this->assertTrue((bool) ($checks['region_eu_provider_available']['passed'] ?? false));
    }

    public function test_wechatpay_enabled_with_missing_cert_files_fails_payment_group_check(): void
    {
        $this->setPaymentProviders(['wechatpay']);
        config([
            'ops.go_live_gate.payment_policy' => 'enabled_only',
            'ops.go_live_gate.payment_refund_drill_ok' => true,
            'pay.wechat.default.app_id' => 'wx_app_001',
            'pay.wechat.default.mch_id' => '1900000109',
            'pay.wechat.default.mch_secret_key' => '12345678901234567890123456789012',
            'pay.wechat.default.mch_secret_cert' => '/tmp/not-exists-wechat-key.pem',
            'pay.wechat.default.mch_public_cert_path' => '/tmp/not-exists-wechat-cert.pem',
            'pay.wechat.default.wechat_public_cert_path' => '/tmp/not-exists-wechat-platform.pem',
            'pay.wechat.default.notify_url' => 'https://api.fermatmind.com/api/v0.3/webhooks/payment/wechatpay',
        ]);

        $checks = $this->paymentChecksByKey();
        $this->assertFalse((bool) ($checks['provider_wechatpay_configured']['passed'] ?? true));
        $this->assertStringContainsString(
            'cert invalid or unreadable',
            (string) ($checks['provider_wechatpay_configured']['message'] ?? '')
        );
    }

    public function test_alipay_notify_url_with_query_string_fails_payment_group_check(): void
    {
        $this->setPaymentProviders(['alipay']);
        $files = $this->createTempCertFiles(4);

        try {
            config([
                'ops.go_live_gate.payment_policy' => 'enabled_only',
                'ops.go_live_gate.payment_refund_drill_ok' => true,
                'pay.alipay.default.app_id' => 'alipay_app_001',
                'pay.alipay.default.merchant_private_key_path' => $files[0],
                'pay.alipay.default.app_public_cert_path' => $files[1],
                'pay.alipay.default.alipay_public_cert_path' => $files[2],
                'pay.alipay.default.alipay_root_cert_path' => $files[3],
                'pay.alipay.default.notify_url' => 'https://api.fermatmind.com/api/v0.3/webhooks/payment/alipay?foo=1',
            ]);

            $checks = $this->paymentChecksByKey();
            $this->assertFalse((bool) ($checks['provider_alipay_configured']['passed'] ?? true));
            $this->assertStringContainsString(
                'notify_url',
                (string) ($checks['provider_alipay_configured']['message'] ?? '')
            );
        } finally {
            $this->cleanupTempFiles($files);
        }
    }

    public function test_stripe_disabled_does_not_block_payment_group_checks(): void
    {
        $this->setPaymentProviders(['lemonsqueezy']);
        config([
            'ops.go_live_gate.payment_policy' => 'enabled_only',
            'ops.go_live_gate.payment_refund_drill_ok' => true,
            'ops.go_live_gate.stripe_secret' => '',
            'ops.go_live_gate.stripe_webhook_secret' => '',
            'services.lemonsqueezy.api_key' => 'ls_test_key',
            'services.lemonsqueezy.store_id' => 'store_001',
            'services.lemonsqueezy.variant_id' => 'variant_001',
            'services.lemonsqueezy.webhook_secret' => 'ls_whsec_001',
        ]);

        $checks = $this->paymentChecksByKey();
        $this->assertArrayNotHasKey('provider_stripe_configured', $checks);
        $this->assertTrue((bool) ($checks['provider_lemonsqueezy_configured']['passed'] ?? false));
    }

    public function test_region_availability_checks_fail_when_no_provider_is_enabled(): void
    {
        $this->setPaymentProviders([]);
        config([
            'ops.go_live_gate.payment_policy' => 'enabled_only',
            'ops.go_live_gate.payment_refund_drill_ok' => true,
        ]);

        $checks = $this->paymentChecksByKey();
        $this->assertFalse((bool) ($checks['region_cn_provider_available']['passed'] ?? true));
        $this->assertFalse((bool) ($checks['region_us_provider_available']['passed'] ?? true));
        $this->assertFalse((bool) ($checks['region_eu_provider_available']['passed'] ?? true));
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function paymentChecksByKey(): array
    {
        $snapshot = app(GoLiveGateService::class)->snapshot();
        $groups = $snapshot['groups'] ?? [];
        $this->assertIsArray($groups);
        $this->assertArrayHasKey('commerce_payments', $groups);

        $paymentGroup = $groups['commerce_payments'];
        $this->assertIsArray($paymentGroup);
        $checks = $paymentGroup['checks'] ?? [];
        $this->assertIsArray($checks);

        $mapped = [];
        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $key = trim((string) ($check['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $mapped[$key] = $check;
        }

        return $mapped;
    }

    /**
     * @param  array<int, string>  $enabled
     */
    private function setPaymentProviders(array $enabled): void
    {
        $enabled = array_values(array_unique(array_map(
            static fn (string $provider): string => strtolower(trim($provider)),
            $enabled
        )));

        $providers = config('payments.providers', []);
        if (! is_array($providers)) {
            $providers = [];
        }

        foreach ($providers as $provider => $providerConfig) {
            if (! is_string($provider)) {
                continue;
            }

            config([
                'payments.providers.'.$provider.'.enabled' => in_array(strtolower(trim($provider)), $enabled, true),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function createTempCertFiles(int $count): array
    {
        $files = [];
        for ($index = 0; $index < $count; $index++) {
            $path = tempnam(sys_get_temp_dir(), 'golive_cert_');
            if (! is_string($path) || trim($path) === '') {
                self::fail('failed to create temporary cert file.');
            }

            file_put_contents($path, 'dummy-cert-'.$index);
            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $files
     */
    private function cleanupTempFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
