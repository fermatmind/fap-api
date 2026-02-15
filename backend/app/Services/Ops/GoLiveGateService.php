<?php

declare(strict_types=1);

namespace App\Services\Ops;

class GoLiveGateService
{
    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $groups = [
            'commerce_stripe' => $this->commerceStripeChecks(),
            'sre_devops' => $this->sreDevopsChecks(),
            'compliance_comm' => $this->complianceChecks(),
            'growth_observability' => $this->growthChecks(),
        ];

        $allPassed = true;
        foreach ($groups as $group) {
            foreach (($group['checks'] ?? []) as $check) {
                if (!((bool) ($check['passed'] ?? false))) {
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
    private function commerceStripeChecks(): array
    {
        $stripeSecret = trim((string) config('ops.go_live_gate.stripe_secret', ''));
        $webhookSecret = trim((string) config('ops.go_live_gate.stripe_webhook_secret', ''));

        $checks = [
            $this->check('live_key', str_starts_with($stripeSecret, 'sk_live_'), 'STRIPE_SECRET must be live key.'),
            $this->check('live_webhook', str_starts_with($webhookSecret, 'whsec_'), 'STRIPE_WEBHOOK_SECRET is required.'),
            $this->check('payment_refund_drill', (bool) config('ops.go_live_gate.payment_refund_drill_ok', false), 'Set OPS_GATE_PAYMENT_REFUND_DRILL_OK=true after drill.'),
        ];

        return [
            'label' => 'Commerce/Stripe',
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sreDevopsChecks(): array
    {
        $checks = [
            $this->check('app_debug_false', !((bool) config('app.debug', false)), 'APP_DEBUG must be false in production.'),
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
