<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use App\Services\Report\ReportAccess;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60V5ReportDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_report_access_ready_delivers_v5_report_when_snapshot_missing_in_strict_mode(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_v5_delivery_missing_snapshot';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId);

        Queue::fake();

        $access = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access');

        $access->assertOk()
            ->assertJsonPath('access_state', 'ready')
            ->assertJsonPath('report_state', 'ready')
            ->assertJsonPath('payload.locked', false)
            ->assertJsonPath('payload.variant', ReportAccess::VARIANT_FULL)
            ->assertJsonPath('payload.access_level', ReportAccess::REPORT_ACCESS_FULL)
            ->assertJsonPath('payload.upgrade_sku', null)
            ->assertJsonPath('payload.offers', [])
            ->assertJsonPath('payload.view_policy.blur_others', false);

        $this->assertFalse(DB::table('report_snapshots')->where('attempt_id', $attemptId)->exists());

        $report = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $report->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('generating', false)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('variant', ReportAccess::VARIANT_FULL)
            ->assertJsonPath('access_level', ReportAccess::REPORT_ACCESS_FULL)
            ->assertJsonPath('upgrade_sku', null)
            ->assertJsonPath('offers', [])
            ->assertJsonPath('report.eq_report_mode', 'self_report')
            ->assertJsonPath('report.measurement_type', 'self_report_trait_mixed_ei')
            ->assertJsonPath('report.access.all_results_free', true)
            ->assertJsonPath('report.access.locked', false)
            ->assertJsonPath('report.access.blur', false)
            ->assertJsonPath('report.access.paywall', false)
            ->assertJsonPath('report.next_module.available', false)
            ->assertJsonPath('report.next_module.status', 'planned');

        $this->assertIsArray($report->json('report.scores.global'));
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $this->assertIsArray($report->json('report.scores.dimensions.'.$code));
        }
        $this->assertCount(4, (array) $report->json('report.dimension_summary'));
        $this->assertNotSame('', (string) $report->json('report.quality.confidence_label'));
        $this->assertNotSame('', (string) $report->json('report.interpretation.core_formulation_id'));
        $this->assertNotEmpty((array) $report->json('report.asset_refs'));
        $this->assertNotEmpty((array) $report->json('report.assets'));

        $json = json_encode($report->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString('SKU_EQ_60_FULL_299', $json);
        $this->assertStringNotContainsString('EQ_60_FULL', $json);
        $this->assertStringNotContainsString('"locked":true', $json);
        $this->assertStringNotContainsString('"paywall":true', $json);
        $this->assertStringNotContainsString('"blur_others":true', $json);

        $snapshot = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('ready', (string) ($snapshot->status ?? ''));

        Queue::assertNotPushed(GenerateReportSnapshotJob::class);
    }

    public function test_eq60_pending_snapshot_does_not_block_v5_report_delivery(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_v5_delivery_pending_snapshot';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId);
        $this->insertPendingSnapshot($attemptId, 'EQ_60');

        $report = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $report->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('generating', false)
            ->assertJsonPath('locked', false)
            ->assertJsonPath('report.eq_report_mode', 'self_report')
            ->assertJsonPath('report.measurement_type', 'self_report_trait_mixed_ei');

        $this->assertIsArray($report->json('report.scores.dimensions.SA'));
        $this->assertNotEmpty((array) $report->json('report.assets'));
        $this->assertSame('ready', (string) DB::table('report_snapshots')->where('attempt_id', $attemptId)->value('status'));
    }

    public function test_eq60_report_query_locale_resolves_matching_v16_assets_without_overwriting_attempt_locale_snapshot(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_v16_locale_report_delivery';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $english = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report?locale=en');

        $english->assertOk()
            ->assertJsonPath('report.locale', 'en')
            ->assertJsonPath('report.scores.global.label', 'Emotional & Relational Functioning Index');

        $snapshot = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('ready', (string) ($snapshot->status ?? ''));
        $snapshotReport = json_decode((string) ($snapshot->report_full_json ?? ''), true);
        $this->assertIsArray($snapshotReport);
        $this->assertSame('en', (string) ($snapshotReport['locale'] ?? ''));

        $chinese = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report?locale=zh-CN');

        $chinese->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('generating', false)
            ->assertJsonPath('report.locale', 'zh-CN')
            ->assertJsonPath('report.scores.global.label', '情绪与关系综合指数');

        $this->assertMatchesRegularExpression(
            '/[\x{4e00}-\x{9fff}]/u',
            (string) $chinese->json('report.assets.core_formulation.title')
        );
        $this->assertStringNotContainsString(
            'Emotional & Relational Functioning Index',
            json_encode($chinese->json('report.assets'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        $snapshotAfter = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($snapshotAfter);
        $snapshotAfterReport = json_decode((string) ($snapshotAfter->report_full_json ?? ''), true);
        $this->assertIsArray($snapshotAfterReport);
        $this->assertSame('en', (string) ($snapshotAfterReport['locale'] ?? ''));
    }

    public function test_strict_snapshot_behavior_for_mbti_is_unchanged(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $anonId = 'anon_mbti_strict_still_snapshot_bound';
        $token = $this->issueAuthToken($anonId);
        $attemptId = $this->createMbtiAttemptWithResult($anonId);

        Queue::fake();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('generating', true)
            ->assertJsonPath('snapshot_error', false)
            ->assertJsonPath('variant', ReportAccess::VARIANT_FREE)
            ->assertJsonPath('locked', true);

        $this->assertSame([], (array) $response->json('report'));
        $this->assertSame('pending', (string) DB::table('report_snapshots')->where('attempt_id', $attemptId)->value('status'));

        Queue::assertPushed(GenerateReportSnapshotJob::class, function (GenerateReportSnapshotJob $job) use ($attemptId): bool {
            return $job->orgId === 0
                && $job->attemptId === $attemptId
                && $job->triggerSource === 'report_api';
        });
    }

    private function prepareEqContent(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();
    }

    private function issueFmToken(string $anonId): string
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

    private function issueAuthToken(string $anonId): string
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

    private function createEqAttemptWithResult(string $anonId, string $locale = 'zh-CN'): string
    {
        $locale = str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(8),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        $score = $this->scoreEq60([
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'locale' => $locale,
            'region' => 'CN_MAINLAND',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'EQ_60',
                'quality' => $score['quality'] ?? [],
                'norms' => $score['norms'] ?? [],
                'scores' => $score['scores'] ?? [],
                'report_tags' => $score['report_tags'] ?? [],
                'version_snapshot' => $score['version_snapshot'] ?? [],
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createMbtiAttemptWithResult(string $anonId): string
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

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function scoreEq60(array $ctx = []): array
    {
        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = 'C';
        }

        return $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            array_merge([
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => 420,
            ], $ctx)
        );
    }

    private function insertPendingSnapshot(string $attemptId, string $scaleCode): void
    {
        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => $scaleCode,
            'pack_id' => $scaleCode === 'EQ_60' ? 'EQ_60' : (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => $scaleCode === 'EQ_60' ? 'v1' : (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => $scaleCode === 'EQ_60' ? 'eq60_spec_2026_v2' : '2026.01',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => '{}',
            'report_free_json' => '{}',
            'report_full_json' => '{}',
            'status' => 'pending',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
