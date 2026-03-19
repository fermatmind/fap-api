<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentRouter;

class GoLiveGateService
{
    public function __construct(
        private PaymentRouter $paymentRouter,
        private PaymentProviderRegistry $paymentProviders,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $groups = [
            'commerce_payments' => $this->commercePaymentsChecks(),
            'sre_devops' => $this->sreDevopsChecks(),
            'compliance_comm' => $this->complianceChecks(),
            'growth_observability' => $this->growthChecks(),
        ];

        $allPassed = true;
        foreach ($groups as $group) {
            foreach (($group['checks'] ?? []) as $check) {
                if (! ((bool) ($check['passed'] ?? false))) {
                    $allPassed = false;
                    break 2;
                }
            }
        }

        return [
            'status' => $allPassed ? 'PASS' : 'STOP-SHIP',
            'generated_at' => now()->toISOString(),
            'groups' => $groups,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['ran_at'] = now()->toISOString();

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    private function commercePaymentsChecks(): array
    {
        $enabledProviders = $this->enabledPaymentProviders();
        $policy = $this->paymentPolicy();
        $checks = [
            $this->check(
                'region_cn_provider_available',
                $this->hasRegionProvider('CN_MAINLAND', $enabledProviders),
                'At least one enabled payment provider must be routable for CN_MAINLAND.'
            ),
            $this->check(
                'region_us_provider_available',
                $this->hasRegionProvider('US', $enabledProviders),
                'At least one enabled payment provider must be routable for US.'
            ),
            $this->check(
                'region_eu_provider_available',
                $this->hasRegionProvider('EU', $enabledProviders),
                'At least one enabled payment provider must be routable for EU.'
            ),
        ];

        foreach (['lemonsqueezy', 'wechatpay', 'alipay', 'stripe', 'billing'] as $provider) {
            if (! $this->shouldCheckProvider($provider, $policy, $enabledProviders)) {
                continue;
            }

            [$passed, $message] = $this->providerConfiguration($provider);
            $checks[] = $this->check('provider_'.$provider.'_configured', $passed, $message);
        }

        $checks[] = $this->check(
            'payment_refund_drill',
            (bool) config('ops.go_live_gate.payment_refund_drill_ok', false),
            'Set OPS_GATE_PAYMENT_REFUND_DRILL_OK=true after drill.'
        );

        return [
            'label' => 'Commerce/Payments',
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<int, string>  $enabledProviders
     */
    private function hasRegionProvider(string $region, array $enabledProviders): bool
    {
        if ($enabledProviders === []) {
            return false;
        }

        $methods = $this->paymentRouter->methodsForRegion($region);
        if (! is_array($methods) || $methods === []) {
            return false;
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (! is_string($method)) {
                continue;
            }

            $method = strtolower(trim($method));
            if ($method !== '') {
                $normalized[] = $method;
            }
        }

        return count(array_intersect(array_values(array_unique($normalized)), $enabledProviders)) > 0;
    }

    /**
     * @param  array<int, string>  $enabledProviders
     */
    private function shouldCheckProvider(string $provider, string $policy, array $enabledProviders): bool
    {
        if ($policy === 'all') {
            return true;
        }

        return in_array($provider, $enabledProviders, true);
    }

    /**
     * @return array<int, string>
     */
    private function enabledPaymentProviders(): array
    {
        return $this->paymentProviders->enabledProviders();
    }

    private function paymentPolicy(): string
    {
        $policy = strtolower(trim((string) config('ops.go_live_gate.payment_policy', 'enabled_only')));

        return in_array($policy, ['enabled_only', 'all'], true) ? $policy : 'enabled_only';
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function providerConfiguration(string $provider): array
    {
        return match ($provider) {
            'lemonsqueezy' => $this->lemonsqueezyConfiguration(),
            'wechatpay' => $this->wechatpayConfiguration(),
            'alipay' => $this->alipayConfiguration(),
            'stripe' => $this->stripeConfiguration(),
            'billing' => $this->billingConfiguration(),
            default => [false, 'unsupported provider'],
        };
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function lemonsqueezyConfiguration(): array
    {
        $fields = [
            'api_key' => trim((string) config('services.lemonsqueezy.api_key', '')),
            'store_id' => trim((string) config('services.lemonsqueezy.store_id', '')),
            'variant_id' => trim((string) config('services.lemonsqueezy.variant_id', '')),
            'webhook_secret' => trim((string) config('services.lemonsqueezy.webhook_secret', '')),
        ];

        $missing = [];
        foreach ($fields as $field => $value) {
            if ($value === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return [false, 'Lemon Squeezy config missing: '.implode(', ', $missing).'.'];
        }

        return [true, 'Lemon Squeezy config is ready.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function wechatpayConfiguration(): array
    {
        $wechat = config('pay.wechat.default', []);
        if (! is_array($wechat)) {
            return [false, 'WeChat Pay config missing.'];
        }

        $missing = [];
        foreach (['app_id', 'mch_id', 'mch_secret_key', 'notify_url'] as $field) {
            if (trim((string) ($wechat[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return [false, 'WeChat Pay config missing: '.implode(', ', $missing).'.'];
        }

        $mchSecretKey = trim((string) ($wechat['mch_secret_key'] ?? ''));
        if (strlen($mchSecretKey) !== 32) {
            return [false, 'WeChat Pay mch_secret_key must be 32 chars.'];
        }

        $notifyUrl = trim((string) ($wechat['notify_url'] ?? ''));
        if (! $this->isNotifyUrlValid($notifyUrl)) {
            return [false, 'WeChat Pay notify_url must be http(s) without query string.'];
        }

        $certFailures = [];
        if (! $this->isReadableCertInput($wechat['mch_secret_cert'] ?? null)) {
            $certFailures[] = 'mch_secret_cert';
        }
        if (! $this->isReadableCertInput($wechat['mch_public_cert_path'] ?? null)) {
            $certFailures[] = 'mch_public_cert_path';
        }
        if (! $this->isReadableCertInput($wechat['wechat_public_cert_path'] ?? null)) {
            $certFailures[] = 'wechat_public_cert_path';
        }

        if ($certFailures !== []) {
            return [false, 'WeChat Pay cert invalid or unreadable: '.implode(', ', $certFailures).'.'];
        }

        return [true, 'WeChat Pay config is ready.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function alipayConfiguration(): array
    {
        $alipay = config('pay.alipay.default', []);
        if (! is_array($alipay)) {
            return [false, 'Alipay config missing.'];
        }

        if (trim((string) ($alipay['app_id'] ?? '')) === '') {
            return [false, 'Alipay app_id is required.'];
        }

        $notifyUrl = trim((string) ($alipay['notify_url'] ?? ''));
        if (! $this->isNotifyUrlValid($notifyUrl)) {
            return [false, 'Alipay notify_url must be http(s) without query string.'];
        }

        $certFailures = [];
        $hasMerchantPrivateKey = trim((string) ($alipay['merchant_private_key'] ?? '')) !== ''
            || $this->isReadableCertInput($alipay['merchant_private_key_path'] ?? null);
        if (! $hasMerchantPrivateKey) {
            $certFailures[] = 'merchant_private_key_or_path';
        }
        if (! $this->isReadableCertInput($alipay['app_public_cert_path'] ?? null)) {
            $certFailures[] = 'app_public_cert_path';
        }
        if (! $this->isReadableCertInput($alipay['alipay_public_cert_path'] ?? null)) {
            $certFailures[] = 'alipay_public_cert_path';
        }
        if (! $this->isReadableCertInput($alipay['alipay_root_cert_path'] ?? null)) {
            $certFailures[] = 'alipay_root_cert_path';
        }

        if ($certFailures !== []) {
            return [false, 'Alipay cert invalid or unreadable: '.implode(', ', $certFailures).'.'];
        }

        return [true, 'Alipay config is ready.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function stripeConfiguration(): array
    {
        $requireStripeLive = (bool) config('ops.go_live_gate.require_stripe_live', true);
        $stripeSecret = trim((string) config('ops.go_live_gate.stripe_secret', ''));
        $webhookSecret = trim((string) config('ops.go_live_gate.stripe_webhook_secret', ''));

        if ($requireStripeLive) {
            if (! str_starts_with($stripeSecret, 'sk_live_')) {
                return [false, 'STRIPE_SECRET must be a live key when stripe is enabled.'];
            }
            if (! str_starts_with($webhookSecret, 'whsec_')) {
                return [false, 'STRIPE_WEBHOOK_SECRET is required when stripe is enabled.'];
            }
        } else {
            if ($stripeSecret === '' || $webhookSecret === '') {
                return [false, 'STRIPE_SECRET and STRIPE_WEBHOOK_SECRET are required when stripe is enabled.'];
            }
        }

        return [true, 'Stripe config is ready.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function billingConfiguration(): array
    {
        $secret = trim((string) config('services.billing.webhook_secret', ''));
        if ($secret !== '') {
            return [true, 'Billing webhook secret is configured.'];
        }

        $optionalEnvs = config('services.billing.webhook_secret_optional_envs', []);
        $optionalEnvs = is_array($optionalEnvs) ? $optionalEnvs : [];
        if (app()->environment($optionalEnvs)) {
            return [true, 'Billing webhook secret optional in current environment.'];
        }

        return [false, 'BILLING_WEBHOOK_SECRET is required when billing is enabled.'];
    }

    private function isNotifyUrlValid(string $notifyUrl): bool
    {
        if ($notifyUrl === '' || str_contains($notifyUrl, '?')) {
            return false;
        }

        $parts = parse_url($notifyUrl);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''));

        return $host !== '';
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

    /**
     * @return array<string,mixed>
     */
    private function sreDevopsChecks(): array
    {
        $checks = [
            $this->check('app_debug_false', ! ((bool) config('app.debug', false)), 'APP_DEBUG must be false in production.'),
            $this->check('queue_worker', (string) config('queue.default', 'sync') !== 'sync', 'Queue driver cannot be sync for go-live.'),
            $this->check('backup_restore_drill', (bool) config('ops.go_live_gate.db_restore_drill_ok', false), 'Set OPS_GATE_DB_RESTORE_DRILL_OK=true after drill.'),
            $this->check('log_rotation', (bool) config('ops.go_live_gate.log_rotation_ok', false), 'Set OPS_GATE_LOG_ROTATION_OK=true after verification.'),
        ];

        return [
            'label' => 'SRE/DevOps',
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function complianceChecks(): array
    {
        $checks = [
            $this->check('smtp_ready', trim((string) config('mail.mailers.smtp.host', '')) !== '', 'MAIL_HOST must be configured.'),
            $this->check('spf_dkim_dmarc', (bool) config('ops.go_live_gate.spf_dkim_dmarc_ok', false), 'Set OPS_GATE_SPF_DKIM_DMARC_OK=true after DNS verification.'),
            $this->check('legal_pages', (bool) config('ops.go_live_gate.legal_pages_ok', false), 'Set OPS_GATE_LEGAL_PAGES_OK=true after legal review.'),
            $this->check('compliance_skeleton', \App\Support\SchemaBaseline::hasTable('audit_logs') && \App\Support\SchemaBaseline::hasTable('data_lifecycle_requests'), 'audit_logs and data_lifecycle_requests are required.'),
        ];

        return [
            'label' => 'Compliance/Comm',
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function growthChecks(): array
    {
        $checks = [
            $this->check('sentry_backend', trim((string) config('ops.go_live_gate.backend_sentry_dsn', '')) !== '', 'Backend Sentry DSN required.'),
            $this->check('sentry_frontend', trim((string) config('ops.go_live_gate.frontend_sentry_dsn', '')) !== '', 'Frontend Sentry DSN required.'),
            $this->check('conversion_tracking', (bool) config('ops.go_live_gate.conversion_tracking_ok', false), 'Set OPS_GATE_CONVERSION_TRACKING_OK=true after validation.'),
            $this->check('gsc_sitemap_robots', (bool) config('ops.go_live_gate.gsc_sitemap_ok', false), 'Set OPS_GATE_GSC_SITEMAP_OK=true after GSC submission.'),
        ];

        return [
            'label' => 'Growth/Observability',
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $key, bool $passed, string $message): array
    {
        return [
            'key' => $key,
            'passed' => $passed,
            'message' => $message,
        ];
    }
}
