<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->seedExposureData($orgId, 'PR23_STICKY_BUCKET', 'A', 10, 1, 8);

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
        $conversionMetric = is_array($metrics['conversion_rate'] ?? null) ? $metrics['conversion_rate'] : [];

        $this->assertSame(10, (int) ($conversionMetric['sample_size'] ?? 0));
        $this->assertEquals(0.1, (float) ($conversionMetric['value'] ?? -1));
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

    private function seedExposureData(
        int $orgId,
        string $experimentKey,
        string $variant,
        int $exposureCount,
        int $paidAttempts,
        int $readyReports
    ): void {
        $attemptIds = [];
        $baseTs = now()->subMinutes(10);

        for ($i = 1; $i <= $exposureCount; $i++) {
            $attemptId = 'fer2_kpi_attempt_'.$i;
            $attemptIds[] = $attemptId;

            DB::table('events')->insert([
                'id' => (string) Str::uuid(),
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'org_id' => $orgId,
                'user_id' => null,
                'anon_id' => 'fer2_kpi_anon_'.$i,
                'session_id' => null,
                'request_id' => null,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'experiments_json' => json_encode([$experimentKey => $variant], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $baseTs,
                'created_at' => $baseTs,
                'updated_at' => $baseTs,
            ]);
        }

        for ($i = 0; $i < $paidAttempts; $i++) {
            $attemptId = $attemptIds[$i] ?? null;
            if (! is_string($attemptId) || $attemptId === '') {
                continue;
            }

            $orderNo = 'FER2KPI'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

            $orderId = (string) Str::uuid();

            DB::table('orders')->insert([
                'id' => $orderId,
                'order_no' => $orderNo,
                'org_id' => $orgId,
                'user_id' => null,
                'anon_id' => 'fer2_kpi_order_'.$i,
                'sku' => 'mbti_report',
                'item_sku' => 'mbti_report',
                'quantity' => 1,
                'target_attempt_id' => $attemptId,
                'amount_cents' => 999,
                'amount_total' => 999,
                'amount_refunded' => 0,
                'currency' => 'USD',
                'status' => 'paid',
                'provider' => 'stripe',
                'external_trade_no' => null,
                'paid_at' => now()->subMinutes(8),
                'created_at' => now()->subMinutes(9),
                'updated_at' => now()->subMinutes(8),
            ]);

            DB::table('payment_events')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'provider' => 'stripe',
                'provider_event_id' => 'evt_fer2_kpi_'.$i,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'event_type' => 'checkout.paid',
                'payload_json' => '{}',
                'signature_ok' => true,
                'received_at' => now()->subMinutes(8),
                'status' => 'processed',
                'processed_at' => now()->subMinutes(8),
                'attempts' => 1,
                'last_error_code' => null,
                'last_error_message' => null,
                'reason' => null,
                'created_at' => now()->subMinutes(8),
                'updated_at' => now()->subMinutes(8),
            ]);
        }

        for ($i = 0; $i < $readyReports; $i++) {
            $attemptId = $attemptIds[$i] ?? null;
            if (! is_string($attemptId) || $attemptId === '') {
                continue;
            }

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
                'status' => 'ready',
                'last_error' => null,
                'created_at' => now()->subMinutes(7),
                'updated_at' => now()->subMinutes(7),
            ]);
        }
    }
}
