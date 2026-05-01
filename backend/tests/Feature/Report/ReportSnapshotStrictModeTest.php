<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportSnapshotStrictModeTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_report_returns_generating_and_queues_snapshot_when_strict_mode_enabled(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->seedScales();

        $anonId = 'anon_report_strict';
        $attemptId = $this->createAttemptWithResult($anonId);
        $token = $this->issueAnonToken($anonId);

        Queue::fake();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'generating' => true,
            'snapshot_error' => false,
            'variant' => 'free',
            'locked' => true,
        ]);
        $this->assertSame([], (array) $response->json('report'));

        $snapshot = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('pending', (string) ($snapshot->status ?? ''));

        Queue::assertPushed(GenerateReportSnapshotJob::class, function (GenerateReportSnapshotJob $job) use ($attemptId): bool {
            return $job->orgId === 0
                && $job->attemptId === $attemptId
                && $job->triggerSource === 'report_api';
        });
    }

    public function test_partial_unlock_does_not_read_full_snapshot_in_strict_mode(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->seedScales();

        $anonId = 'anon_report_strict_partial';
        $attemptId = $this->createAttemptWithResult($anonId);
        $token = $this->issueAnonToken($anonId);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'MBTI_CAREER',
            $attemptId,
            null,
            'attempt',
            null,
            [ReportAccess::MODULE_CAREER]
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'MBTI',
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['leaked_full_snapshot_marker' => true], JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode(['free_snapshot_marker' => true], JSON_UNESCAPED_SLASHES),
            'report_full_json' => json_encode(['leaked_full_snapshot_marker' => true], JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'generating' => false,
            'snapshot_error' => false,
            'variant' => 'partial',
            'access_level' => 'partial',
            'unlock_stage' => 'partial',
        ]);

        $report = (array) $response->json('report');
        $this->assertNotSame([], $report);
        $this->assertStringNotContainsString('leaked_full_snapshot_marker', json_encode($report, JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertSame(1, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
    }

    public function test_unknown_snapshot_status_returns_snapshot_error_and_observability_signal(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->seedScales();

        $anonId = 'anon_report_snapshot_unknown';
        $attemptId = $this->createAttemptWithResult($anonId);
        $token = $this->issueAnonToken($anonId);

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'MBTI',
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => '{}',
            'report_free_json' => '{}',
            'report_full_json' => '{}',
            'status' => 'mystery',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::spy();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'generating' => false,
            'snapshot_error' => true,
        ]);
        $this->assertSame([], (array) $response->json('report'));
        $this->assertSame('mystery', (string) $response->json('meta.snapshot_status'));
        $this->assertTrue((bool) $response->json('meta.snapshot_status_unknown'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($attemptId): bool {
                return $message === '[REPORT] snapshot_status_unknown'
                    && (int) ($context['org_id'] ?? -1) === 0
                    && (string) ($context['attempt_id'] ?? '') === $attemptId
                    && (string) ($context['status'] ?? '') === 'mystery'
                    && (string) ($context['source'] ?? '') === 'report_gatekeeper';
            });
    }
}
