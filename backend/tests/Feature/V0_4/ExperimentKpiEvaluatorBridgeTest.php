<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExperimentKpiEvaluatorBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpi_evaluator_bridge_computes_conversion_rate_and_triggers_auto_rollback(): void
    {
        $ownerUserId = $this->createUser('fer2_20_owner@fm.test');
        $orgId = $this->createOrg($ownerUserId, 'FER2 KPI Org');
        $rolloutId = $this->seedRollout($orgId);
        $this->seedGuardrail($orgId, $rolloutId);

        $experimentKey = 'PR23_STICKY_BUCKET';
        $variant = 'A';
        $baseTs = now()->subMinutes(30);

        for ($i = 1; $i <= 10; $i++) {
            $attemptId = 'fer2_kpi_attempt_'.$i;
            $anonId = 'fer2_kpi_anon_'.$i;
            $startedAt = (clone $baseTs)->addSeconds($i);
            $submittedAt = (clone $startedAt)->addMinute();

            $this->insertAttemptWithAssignment(
                $orgId,
                $experimentKey,
                $variant,
                $attemptId,
                $anonId,
                $startedAt,
                $submittedAt
            );

            $orderNo = 'FER2KPI'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $orderId = $this->insertOrderForAttempt(
                $orgId,
                $attemptId,
                $anonId,
                $orderNo,
                (clone $submittedAt)->addMinute(),
                $i === 1 ? (clone $submittedAt)->addMinutes(2) : null,
                $i === 1 ? 'paid' : 'pending'
            );

            if ($i === 1) {
                $this->insertPaymentEvent(
                    $orgId,
                    $orderId,
                    $orderNo,
                    'evt_fer2_kpi_'.$i,
                    (clone $submittedAt)->addMinutes(2),
                    'processed',
                    null
                );

                $this->insertReportSnapshot(
                    $orgId,
                    $attemptId,
                    (clone $submittedAt)->addMinutes(3),
                    'ready'
                );
            }
        }

        $exitCode = Artisan::call('ops:experiment-guardrails-evaluate', [
            '--org-id' => (string) $orgId,
            '--rollout-id' => $rolloutId,
            '--window-minutes' => 120,
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(1, (int) ($payload['evaluated_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['rolled_back_count'] ?? 0));

        $result = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : [];
        $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
        $funnel = is_array($result['funnel'] ?? null) ? $result['funnel'] : [];
        $stageCounts = is_array($funnel['stage_counts'] ?? null) ? $funnel['stage_counts'] : [];

        $expectedMetricKeys = [
            'conversion_rate',
            'paid_order_rate',
            'payment_success_rate',
            'report_ready_rate',
            'submission_failed_rate',
            'start_to_submit_rate',
            'submit_to_checkout_rate',
            'checkout_to_payment_rate',
            'payment_to_report_ready_rate',
        ];
        $this->assertEqualsCanonicalizing($expectedMetricKeys, array_keys($metrics));

        foreach ($expectedMetricKeys as $metricKey) {
            $metric = is_array($metrics[$metricKey] ?? null) ? $metrics[$metricKey] : [];
            $this->assertArrayHasKey('value', $metric);
            $this->assertArrayHasKey('sample_size', $metric);
            $this->assertArrayHasKey('source', $metric);
            $this->assertIsNumeric($metric['value']);
            $this->assertIsNumeric($metric['sample_size']);
            $this->assertNotSame('', trim((string) ($metric['source'] ?? '')));
        }

        $conversionMetric = is_array($metrics['conversion_rate'] ?? null) ? $metrics['conversion_rate'] : [];
        $this->assertSame(10, (int) ($conversionMetric['sample_size'] ?? 0));
        $this->assertEquals(0.1, (float) ($conversionMetric['value'] ?? -1));

        $this->assertSame(
            ['start_test', 'submit_attempt', 'checkout_start', 'payment_succeeded', 'report_ready'],
            array_keys($stageCounts)
        );
        $this->assertSame(10, (int) ($stageCounts['start_test'] ?? 0));
        $this->assertSame(10, (int) ($stageCounts['submit_attempt'] ?? 0));
        $this->assertSame(10, (int) ($stageCounts['checkout_start'] ?? 0));
        $this->assertSame(1, (int) ($stageCounts['payment_succeeded'] ?? 0));
        $this->assertSame(1, (int) ($stageCounts['report_ready'] ?? 0));
        $this->assertTrue((bool) ($result['rolled_back'] ?? false));

        $this->assertDatabaseHas('scoring_model_rollouts', [
            'id' => $rolloutId,
            'org_id' => $orgId,
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('experiment_rollout_audits', [
            'org_id' => $orgId,
            'rollout_id' => $rolloutId,
            'action' => 'auto_rollback',
            'status' => 'triggered',
        ]);
    }

    public function test_kpi_bridge_missing_submit_event_does_not_count_downstream_stages(): void
    {
        $ownerUserId = $this->createUser('fer2_35_missing_submit_owner@fm.test');
        $orgId = $this->createOrg($ownerUserId, 'FER2 KPI Missing Submit Org');
        $rolloutId = $this->seedRollout($orgId);

        $experimentKey = 'PR23_STICKY_BUCKET';
        $variant = 'A';
        $baseTs = now()->subMinutes(30);

        for ($i = 1; $i <= 3; $i++) {
            $attemptId = 'fer2_kpi_missing_submit_attempt_'.$i;
            $anonId = 'fer2_kpi_missing_submit_anon_'.$i;
            $startedAt = (clone $baseTs)->addSeconds($i);

            $this->insertAttemptWithAssignment(
                $orgId,
                $experimentKey,
                $variant,
                $attemptId,
                $anonId,
                $startedAt,
                null
            );

            $orderNo = 'FER2MISS'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $orderId = $this->insertOrderForAttempt(
                $orgId,
                $attemptId,
                $anonId,
                $orderNo,
                (clone $startedAt)->addMinutes(2),
                (clone $startedAt)->addMinutes(3),
                'paid'
            );

            $this->insertPaymentEvent(
                $orgId,
                $orderId,
                $orderNo,
                'evt_fer2_missing_'.$i,
                (clone $startedAt)->addMinutes(3),
                'processed',
                null
            );

            $this->insertReportSnapshot(
                $orgId,
                $attemptId,
                (clone $startedAt)->addMinutes(4),
                'ready'
            );
        }

        $exitCode = Artisan::call('ops:experiment-guardrails-evaluate', [
            '--org-id' => (string) $orgId,
            '--rollout-id' => $rolloutId,
            '--window-minutes' => 120,
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);

        $result = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : [];
        $funnel = is_array($result['funnel'] ?? null) ? $result['funnel'] : [];
        $stageCounts = is_array($funnel['stage_counts'] ?? null) ? $funnel['stage_counts'] : [];

        $this->assertSame(3, (int) ($stageCounts['start_test'] ?? 0));
        $this->assertSame(0, (int) ($stageCounts['submit_attempt'] ?? 0));
        $this->assertSame(0, (int) ($stageCounts['checkout_start'] ?? 0));
        $this->assertSame(0, (int) ($stageCounts['payment_succeeded'] ?? 0));
        $this->assertSame(0, (int) ($stageCounts['report_ready'] ?? 0));
    }

    public function test_kpi_bridge_out_of_order_events_are_rejected_by_stage_gating(): void
    {
        $ownerUserId = $this->createUser('fer2_35_out_of_order_owner@fm.test');
        $orgId = $this->createOrg($ownerUserId, 'FER2 KPI Out-of-Order Org');
        $rolloutId = $this->seedRollout($orgId);

        $experimentKey = 'PR23_STICKY_BUCKET';
        $variant = 'A';
        $attemptId = 'fer2_kpi_out_of_order_attempt';
        $anonId = 'fer2_kpi_out_of_order_anon';

        $startedAt = now()->subMinutes(25);
        $submittedAt = (clone $startedAt)->addMinute();
        $checkoutAt = (clone $submittedAt)->addMinutes(2);
        $paymentAt = (clone $submittedAt)->addMinute();
        $reportAt = (clone $submittedAt)->addSeconds(90);

        $this->insertAttemptWithAssignment(
            $orgId,
            $experimentKey,
            $variant,
            $attemptId,
            $anonId,
            $startedAt,
            $submittedAt
        );

        $orderNo = 'FER2OORD0001';
        $orderId = $this->insertOrderForAttempt(
            $orgId,
            $attemptId,
            $anonId,
            $orderNo,
            $checkoutAt,
            null,
            'pending'
        );

        $this->insertPaymentEvent(
            $orgId,
            $orderId,
            $orderNo,
            'evt_fer2_oorder_1',
            $paymentAt,
            'processed',
            null
        );

        $this->insertReportSnapshot(
            $orgId,
            $attemptId,
            $reportAt,
            'ready'
        );

        $exitCode = Artisan::call('ops:experiment-guardrails-evaluate', [
            '--org-id' => (string) $orgId,
            '--rollout-id' => $rolloutId,
            '--window-minutes' => 120,
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);

        $result = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : [];
        $funnel = is_array($result['funnel'] ?? null) ? $result['funnel'] : [];
        $stageCounts = is_array($funnel['stage_counts'] ?? null) ? $funnel['stage_counts'] : [];

        $this->assertSame(1, (int) ($stageCounts['start_test'] ?? 0));
        $this->assertSame(1, (int) ($stageCounts['submit_attempt'] ?? 0));
        $this->assertSame(1, (int) ($stageCounts['checkout_start'] ?? 0));
        $this->assertSame(0, (int) ($stageCounts['payment_succeeded'] ?? 0));
        $this->assertSame(0, (int) ($stageCounts['report_ready'] ?? 0));
    }

    private function createUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrg(int $ownerUserId, string $name): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'name' => $name,
            'owner_user_id' => $ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRollout(int $orgId): string
    {
        $modelKey = 'fer2_kpi_model_'.Str::lower(Str::random(6));

        DB::table('scoring_models')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'model_key' => $modelKey,
            'driver_type' => 'mbti',
            'scoring_spec_version' => 'mbti_spec_fer2_20',
            'priority' => 10,
            'is_active' => true,
            'config_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rolloutId = (string) Str::uuid();
        DB::table('scoring_model_rollouts')->insert([
            'id' => $rolloutId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'model_key' => $modelKey,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'experiment_variant' => 'A',
            'rollout_percent' => 100,
            'priority' => 10,
            'is_active' => true,
            'starts_at' => now()->subMinutes(20),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rolloutId;
    }

    private function seedGuardrail(int $orgId, string $rolloutId): void
    {
        DB::table('experiment_guardrails')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'rollout_id' => $rolloutId,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'metric_key' => 'conversion_rate',
            'operator' => 'lte',
            'threshold' => 0.2,
            'window_minutes' => 120,
            'min_sample_size' => 5,
            'auto_rollback' => true,
            'is_active' => true,
            'last_evaluated_at' => null,
            'last_triggered_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttemptWithAssignment(
        int $orgId,
        string $experimentKey,
        string $variant,
        string $attemptId,
        string $anonId,
        Carbon $startedAt,
        ?Carbon $submittedAt
    ): void {
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 4,
            'answers_summary_json' => '{}',
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'organic',
            'referrer' => null,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => 'mbti_spec_fer2_20',
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'created_at' => $startedAt,
            'updated_at' => $submittedAt ?? $startedAt,
        ]);

        DB::table('experiment_assignments')->insert([
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => null,
            'experiment_key' => $experimentKey,
            'variant' => $variant,
            'assigned_at' => $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);
    }

    private function insertOrderForAttempt(
        int $orgId,
        string $attemptId,
        string $anonId,
        string $orderNo,
        Carbon $createdAt,
        ?Carbon $paidAt,
        string $status
    ): string {
        $orderId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'mbti_report',
            'item_sku' => 'mbti_report',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 999,
            'amount_total' => 999,
            'amount_refunded' => 0,
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'stripe',
            'external_trade_no' => null,
            'paid_at' => $paidAt,
            'created_at' => $createdAt,
            'updated_at' => $paidAt ?? $createdAt,
        ]);

        return $orderId;
    }

    private function insertPaymentEvent(
        int $orgId,
        string $orderId,
        string $orderNo,
        string $providerEventId,
        Carbon $processedAt,
        string $status,
        ?string $reason
    ): void {
        DB::table('payment_events')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'event_type' => 'checkout.paid',
            'payload_json' => '{}',
            'signature_ok' => true,
            'received_at' => $processedAt,
            'status' => $status,
            'processed_at' => $processedAt,
            'attempts' => 1,
            'last_error_code' => null,
            'last_error_message' => null,
            'reason' => $reason,
            'created_at' => $processedAt,
            'updated_at' => $processedAt,
        ]);
    }

    private function insertReportSnapshot(int $orgId, string $attemptId, Carbon $updatedAt, string $status): void
    {
        DB::table('report_snapshots')->insert([
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'mbti_spec_fer2_20',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => '{}',
            'status' => $status,
            'last_error' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
