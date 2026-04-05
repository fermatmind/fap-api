<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Support\SchemaBaseline;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class AttemptReportAccessReadTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createAttempt(string $attemptId, string $anonId, string $formCode = 'mbti_144'): void
    {
        $isMbti93 = $formCode === 'mbti_93';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $isMbti93 ? 93 : 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed', 'meta' => ['form_code' => $formCode]],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => $isMbti93 ? 'MBTI-CN-v0.3-form-93' : 'MBTI-CN-v0.3',
            'content_package_version' => $isMbti93 ? 'v0.3-form-93' : 'v0.3',
            'scoring_spec_version' => $isMbti93 ? '2026.01.mbti_93' : '2026.01.mbti_144',
        ]);
    }

    private function createResult(string $attemptId, string $formCode = 'mbti_144'): void
    {
        $isMbti93 = $formCode === 'mbti_93';

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => $isMbti93 ? 'v0.3-form-93' : 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A', 'meta' => ['form_code' => $formCode]],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => $isMbti93 ? 'MBTI-CN-v0.3-form-93' : 'MBTI-CN-v0.3',
            'scoring_spec_version' => $isMbti93 ? '2026.01.mbti_93' : '2026.01.mbti_144',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    private function createActiveGrant(
        string $attemptId,
        string $anonId,
        string $benefitCode = 'MBTI_REPORT_FULL',
        ?string $orderNo = null,
        ?array $meta = null,
    ): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => $anonId,
            'benefit_code' => $benefitCode,
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => $orderNo ?? 'ORDER-'.$attemptId,
            'status' => 'active',
            'expires_at' => null,
            'benefit_ref' => $anonId,
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'meta_json' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_reads_consumer_report_access_projection_without_changing_report_contracts(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_reader';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId, 'mbti_93');
        $this->createResult($attemptId, 'mbti_93');

        DB::table('unified_access_projections')->insert([
            'attempt_id' => $attemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'ready',
            'reason_code' => 'entitlement_granted',
            'projection_version' => 1,
            'actions_json' => json_encode(['report' => true, 'pdf' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode(['attempt_id' => $attemptId, 'has_active_grant' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'produced_at' => now(),
            'refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'ready');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('pdf_state', 'ready');
        $response->assertJsonPath('reason_code', 'entitlement_granted');
        $response->assertJsonPath('projection_version', 1);
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $response->assertJsonPath('actions.pdf_href', "/api/v0.3/attempts/{$attemptId}/report.pdf");
        $response->assertJsonPath('actions.history_href', '/history/mbti');
        $response->assertJsonPath('actions.lookup_href', '/orders/lookup');
        $response->assertJsonPath('payload.has_active_grant', true);
        $response->assertJsonPath('mbti_form_v1.form_code', 'mbti_93');
        $response->assertJsonPath('mbti_form_v1.question_count', 93);
        $response->assertJsonPath('mbti_form_v1.scale_code', 'MBTI');
    }

    public function test_it_repairs_missing_ready_projection_from_active_entitlement_when_result_exists(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_repair';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $this->createResult($attemptId);
        $this->createActiveGrant($attemptId, $anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'ready');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('reason_code', 'projection_repaired_from_entitlement');
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $response->assertJsonPath('payload.has_active_grant', true);
        $response->assertJsonPath('payload.result_exists', true);
        $response->assertJsonPath('unlock_stage', 'full');
        $response->assertJsonPath('unlock_source', 'payment');
        $response->assertJsonPath('payload.unlock_stage', 'full');
        $response->assertJsonPath('payload.unlock_source', 'payment');
        $response->assertJsonPath('payload.access_level', 'full');
        $response->assertJsonPath('payload.variant', 'full');
        $this->assertDatabaseHas('unified_access_projections', [
            'attempt_id' => $attemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_it_keeps_result_ready_projection_missing_locked_when_no_active_grant_exists(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_no_grant';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $this->createResult($attemptId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'locked');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('reason_code', 'projection_missing_result_ready');
        $response->assertJsonPath('payload.result_exists', true);
        $response->assertJsonPath('unlock_stage', 'locked');
        $response->assertJsonPath('unlock_source', 'none');
        $response->assertJsonPath('payload.unlock_stage', 'locked');
        $response->assertJsonPath('payload.unlock_source', 'none');
        $response->assertJsonPath('payload.has_active_grant', null);
        $this->assertDatabaseMissing('unified_access_projections', [
            'attempt_id' => $attemptId,
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_it_keeps_projection_missing_pending_when_active_grant_exists_but_result_does_not_exist(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_no_result';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $this->createActiveGrant($attemptId, $anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'pending');
        $response->assertJsonPath('report_state', 'pending');
        $response->assertJsonPath('reason_code', 'projection_missing_result_pending');
        $response->assertJsonPath('payload.result_exists', false);
        $this->assertDatabaseMissing('unified_access_projections', [
            'attempt_id' => $attemptId,
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_it_does_not_repair_non_mbti_attempts_from_projection_missing_result_ready(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_non_mbti';
        $token = $this->issueAnonToken($anonId);

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'result-v1',
            'result_json' => ['variant' => 'free'],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => $anonId,
            'benefit_code' => 'BIG5_FULL_REPORT',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'order_no' => 'ORDER-'.$attemptId,
            'status' => 'active',
            'expires_at' => null,
            'benefit_ref' => $anonId,
            'benefit_type' => 'report_unlock',
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'locked');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('reason_code', 'projection_missing_result_ready');
        $this->assertDatabaseMissing('unified_access_projections', [
            'attempt_id' => $attemptId,
            'reason_code' => 'projection_repaired_from_entitlement',
        ]);
    }

    public function test_it_repairs_partial_projection_from_invite_partial_grant_without_escalating_to_full(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_partial_repair';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId);
        $this->createResult($attemptId);
        $this->createActiveGrant(
            $attemptId,
            $anonId,
            'MBTI_CAREER',
            '',
            [
                'granted_via' => 'invite_unlock',
                'invite_unlock_stage' => 'partial',
            ]
        );

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'ready');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('reason_code', 'projection_repaired_from_entitlement');
        $response->assertJsonPath('unlock_stage', 'partial');
        $response->assertJsonPath('unlock_source', 'invite');
        $response->assertJsonPath('payload.unlock_stage', 'partial');
        $response->assertJsonPath('payload.unlock_source', 'invite');
        $response->assertJsonPath('payload.access_level', 'partial');
        $response->assertJsonPath('payload.variant', 'partial');
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $modulesAllowed = (array) $response->json('payload.modules_allowed');
        $this->assertContains('core_free', $modulesAllowed);
        $this->assertContains('career', $modulesAllowed);
    }

    public function test_it_keeps_result_ready_report_access_available_for_mbti_144_when_projection_is_missing(): void
    {
        $this->assertReportAccessAvailableForMbtiForm('mbti_144');
    }

    public function test_it_keeps_result_ready_report_access_available_for_mbti_93_when_projection_is_missing(): void
    {
        $this->assertReportAccessAvailableForMbtiForm('mbti_93');
    }

    private function assertReportAccessAvailableForMbtiForm(string $formCode): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_form_'.$formCode;
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId, $formCode);
        $this->createResult($attemptId, $formCode);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'locked');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('reason_code', 'projection_missing_result_ready');
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $response->assertJsonPath('payload.fallback', true);
        $response->assertJsonPath('payload.result_exists', true);
        $response->assertJsonPath('mbti_form_v1.form_code', $formCode);
    }

    public function test_it_returns_conservative_report_access_fallback_when_unlock_state_resolution_fails(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_repair_fallback';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId, 'mbti_144');
        $this->createResult($attemptId, 'mbti_144');
        $this->createActiveGrant($attemptId, $anonId);

        Log::spy();
        $this->mock(EntitlementManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveAttemptUnlockState')
                ->once()
                ->andThrow(new \RuntimeException('simulated unlock-state failure'));
        });

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'locked');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('reason_code', 'projection_repair_failed_fallback_locked');
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $response->assertJsonPath('payload.fallback', true);
        $response->assertJsonPath('payload.result_exists', true);
        $response->assertJsonPath('payload.repair_fallback', true);
        $response->assertJsonPath('payload.repair_error_code', 'unlock_state_resolution_failed');
        $response->assertJsonPath('unlock_stage', 'locked');
        $response->assertJsonPath('unlock_source', 'none');
        $response->assertJsonPath('payload.access_level', 'free');
        $response->assertJsonPath('payload.variant', 'free');
        $response->assertJsonPath('mbti_form_v1.form_code', 'mbti_144');
        $this->assertDatabaseHas('unified_access_projections', [
            'attempt_id' => $attemptId,
            'reason_code' => 'projection_repair_failed_fallback_locked',
        ]);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($attemptId): bool {
                return $message === 'ATTEMPT_UNLOCK_PROJECTION_REPAIR_UNLOCK_STATE_FAILED'
                    && (string) ($context['attempt_id'] ?? '') === $attemptId;
            });
        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(function (string $message, array $context) use ($attemptId): bool {
                return $message === 'ATTEMPT_UNLOCK_PROJECTION_REPAIR_FALLBACK_APPLIED'
                    && (string) ($context['attempt_id'] ?? '') === $attemptId;
            });
    }

    public function test_it_keeps_report_access_200_when_invite_snapshot_table_is_missing(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_invite_table_missing';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId, 'mbti_144');
        $this->createResult($attemptId, 'mbti_144');

        if (Schema::hasTable('attempt_invite_unlocks')) {
            Schema::drop('attempt_invite_unlocks');
        }
        SchemaBaseline::clearCache();

        Log::spy();
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'locked');
        $response->assertJsonPath('report_state', 'ready');
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $response->assertJsonPath('payload.fallback', true);
        $response->assertJsonPath('payload.result_exists', true);
        $response->assertJsonPath('payload.invite_snapshot_fallback', true);
        $response->assertJsonPath('payload.invite_snapshot_error_code', 'invite_snapshot_table_missing');
        $response->assertJsonPath('unlock_stage', 'locked');
        $response->assertJsonPath('unlock_source', 'none');

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(function (string $message, array $context) use ($attemptId): bool {
                return $message === 'REPORT_ACCESS_INVITE_SNAPSHOT_FAILED'
                    && (string) ($context['attempt_id'] ?? '') === $attemptId
                    && (string) ($context['failure_code'] ?? '') === 'invite_snapshot_table_missing';
            });
    }

    public function test_it_keeps_report_access_200_when_projection_table_is_missing(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_access_projection_table_missing';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, $anonId, 'mbti_144');
        $this->createResult($attemptId, 'mbti_144');

        if (Schema::hasTable('unified_access_projections')) {
            Schema::drop('unified_access_projections');
        }
        SchemaBaseline::clearCache();

        Log::spy();
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(200);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('access_state', 'locked');
        $response->assertJsonPath('report_state', 'ready');
        $this->assertContains((string) $response->json('reason_code'), [
            'projection_read_failed_result_ready_fallback',
            'projection_repair_failed_result_ready_fallback',
        ]);
        $response->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $response->assertJsonPath('payload.fallback', true);
        $response->assertJsonPath('payload.result_exists', true);
        $response->assertJsonPath('payload.projection_read_fallback', true);
        $response->assertJsonPath('payload.projection_error_code', 'projection_table_missing');
        $response->assertJsonPath('unlock_stage', 'locked');
        $response->assertJsonPath('unlock_source', 'none');

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(function (string $message, array $context) use ($attemptId): bool {
                return $message === 'REPORT_ACCESS_PROJECTION_READ_FAILED'
                    && (string) ($context['attempt_id'] ?? '') === $attemptId
                    && (string) ($context['failure_code'] ?? '') === 'projection_table_missing';
            });
    }
}
