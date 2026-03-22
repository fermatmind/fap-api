<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AttemptDataLifecycleService;
use App\Services\Storage\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DsarArtifactPurgeBlockedByHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_artifact_purge_is_blocked_by_active_legal_hold(): void
    {
        Storage::fake('local');
        config()->set('storage_rollout.retention_policy_engine_enabled', true);
        config()->set('storage_rollout.dsar_artifact_purge_enabled', true);

        DB::table('retention_policies')->insert([
            'code' => 'dsar_hold_default',
            'subject_scope' => 'attempt',
            'artifact_scope' => 'report_artifact_domain',
            'archive_after_days' => null,
            'shrink_after_days' => null,
            'purge_after_days' => 0,
            'delete_behavior' => 'purge_all',
            'delete_remote_archive' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = 920;
        $attemptId = (string) Str::uuid();
        $manifestHash = 'holdpurgehash';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => 'anon_purge_hold',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'pack_release_manifest_hash' => $manifestHash,
                ],
            ],
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20]],
            'scores_pct' => ['EI' => 50],
            'axis_states' => ['EI' => 'clear'],
            'result_json' => ['type_code' => 'INTJ-A'],
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        DB::table('legal_holds')->insert([
            'scope_type' => 'attempt',
            'scope_id' => $attemptId,
            'reason_code' => 'LEGAL_HOLD_ACTIVE',
            'placed_by' => 'phpunit',
            'active_from' => now()->subMinute(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ArtifactStore $artifactStore */
        $artifactStore = app(ArtifactStore::class);
        $reportPath = $artifactStore->reportCanonicalPath('MBTI', $attemptId);
        $pdfPath = $artifactStore->pdfCanonicalPath('MBTI', $attemptId, $manifestHash, 'free');
        Storage::disk('local')->put($reportPath, '{"artifact":"report"}');
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 artifact');

        /** @var AttemptDataLifecycleService $service */
        $service = app(AttemptDataLifecycleService::class);
        $result = $service->purgeAttempt($attemptId, $orgId, [
            'reason' => 'user_request',
            'scale_code' => 'MBTI',
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('LEGAL_HOLD_ACTIVE', (string) ($result['error'] ?? ''));
        $this->assertSame('LEGAL_HOLD_ACTIVE', (string) ($result['blocked_reason_code'] ?? ''));
        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertTrue(Storage::disk('local')->exists($pdfPath));
        $this->assertDatabaseCount('artifact_lifecycle_jobs', 0);

        $requestRow = DB::table('data_lifecycle_requests')
            ->where('request_type', 'attempt_purge')
            ->where('subject_ref', $attemptId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($requestRow);
        $this->assertSame('blocked', (string) ($requestRow->status ?? ''));
        $requestResult = json_decode((string) ($requestRow->result_json ?? '{}'), true);
        $this->assertIsArray($requestResult);
        $this->assertSame('LEGAL_HOLD_ACTIVE', (string) ($requestResult['blocked_reason_code'] ?? ''));
    }
}
