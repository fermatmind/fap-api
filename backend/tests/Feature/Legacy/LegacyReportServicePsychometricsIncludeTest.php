<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\DTO\Legacy\LegacyRequestContext;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Legacy\LegacyReportService;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyReportServicePsychometricsIncludeTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_report_service_includes_psychometrics_snapshot_when_requested(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
        Config::set('storage_rollout.legacy_drain_enabled', false);

        $anonId = 'legacy-psychometrics-anon';
        $attemptId = (string) Str::uuid();

        $attempt = Attempt::create([
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
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(4),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => [
                'version' => 'legacy.mbti.psychometrics.v1',
                'quality' => [
                    'level' => 'B',
                    'grade' => 'B',
                    'checks' => [
                        ['id' => 'reverse_pair_mismatch', 'status' => 'pass'],
                    ],
                ],
            ],
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20]],
            'scores_pct' => ['EI' => 50],
            'axis_states' => ['EI' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'type_code' => 'INTJ-A',
                'quality' => [
                    'level' => 'A',
                    'grade' => 'A',
                    'checks' => [
                        ['id' => 'current_truth', 'status' => 'pass'],
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now()->subMinutes(3),
        ]);

        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'status' => 'success',
            'tries' => 1,
            'available_at' => now()->subMinutes(3),
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(2),
            'failed_at' => null,
            'last_error' => null,
            'last_error_trace' => null,
            'report_json' => json_encode([
                'profile' => ['type_code' => 'INTJ-A'],
                'tags' => [],
                'sections' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta' => null,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(2),
        ]);

        app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            null
        );

        $context = new LegacyRequestContext(
            orgId: 0,
            userId: null,
            anonId: $anonId,
            requestId: 'legacy-psychometrics-include',
            sessionId: null,
            headers: [],
            query: ['include' => 'psychometrics'],
            input: [],
            attributes: []
        );

        /** @var LegacyReportService $service */
        $service = app(LegacyReportService::class);
        $payload = $service->getReportPayload($attempt->fresh(), $context);

        $this->assertSame(200, (int) ($payload['status'] ?? 0));
        $this->assertSame(
            'legacy.mbti.psychometrics.v1',
            (string) data_get($payload, 'body.report.psychometrics.version')
        );
        $this->assertSame(
            'A',
            (string) data_get($payload, 'body.report.psychometrics.quality.level')
        );
        $this->assertSame(
            'A',
            (string) data_get($payload, 'body.report.psychometrics.quality.grade')
        );
        $this->assertSame(
            ['version', 'quality'],
            array_keys((array) data_get($payload, 'body.report.psychometrics', []))
        );
    }
}
