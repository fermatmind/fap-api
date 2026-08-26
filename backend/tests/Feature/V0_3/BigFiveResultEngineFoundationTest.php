<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\ScaleRegistryV2;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\BigFivePackLoader;
use App\Services\Report\ReportAccess;
use App\Services\Scale\ScaleRegistryWriter;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveResultEngineFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_result_and_report_read_paths_expose_projection_foundation_and_telemetry_meta(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.narrative.enabled', true);
        config()->set('ai.narrative.provider', 'mock');
        config()->set('ai.breaker_enabled', false);

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_big5_foundation';
        $attemptId = $this->seedAttempt($anonId);
        $this->seedResult($attemptId);
        $token = $this->issueAnonToken($anonId);

        $resultResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $resultResponse->assertOk();
        $resultResponse->assertJsonPath('big5_public_projection_v1.schema_version', 'big5.public_projection.v1');
        $resultResponse->assertJsonPath('big5_public_projection_v1.ordered_section_keys.0', 'hero_summary');
        $resultResponse->assertJsonPath('big5_public_projection_v1.trait_bands.O', 'mid');
        $resultResponse->assertJsonCount(30, 'big5_public_projection_v1.facet_vector');
        $resultResponse->assertJsonPath('big5_private_result_authority.mode', 'canonical');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $resultResponse->json('big5_private_result_authority.source_hash'));

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $reportResponse->assertOk();
        $reportResponse->assertJsonPath('locked', false);
        $reportResponse->assertJsonPath('variant', 'full');
        $reportResponse->assertJsonPath('access_level', 'full');
        $reportResponse->assertJsonPath('big5_public_projection_v1.schema_version', 'big5.public_projection.v1');
        $reportResponse->assertJsonPath('big5_public_projection_v1.ordered_section_keys.1', 'domains_overview');
        $reportResponse->assertJsonCount(30, 'big5_public_projection_v1.facet_vector');
        $reportResponse->assertJsonPath('big5_public_projection_v1.facet_vector.0.key', 'N1');
        $reportResponse->assertJsonPath('big5_public_projection_v1.facet_vector.0.domain', 'N');
        $reportResponse->assertJsonPath('report._meta.big5_public_projection_v1.schema_version', 'big5.public_projection.v1');
        $reportResponse->assertJsonCount(30, 'report._meta.big5_public_projection_v1.facet_vector');
        $reportResponse->assertJsonPath('report._meta.big5_private_result_authority.mode', 'canonical');
        $reportResponse->assertJsonPath('report.sections.0.section_key', 'hero_summary');
        $reportResponse->assertJsonPath('report.sections.6.section_key', 'action_plan');

        $reportEvent = DB::table('events')
            ->where('event_code', 'report_view')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($reportEvent);
        $meta = $this->decodeMeta($reportEvent->meta_json ?? null);
        $this->assertSame(['hero_summary', 'domains_overview', 'domain_deep_dive', 'facet_details', 'core_portrait'], array_slice((array) ($meta['ordered_section_keys'] ?? []), 0, 5));
        $this->assertArrayHasKey('trait_bands', $meta);
    }

    public function test_big5_paid_mode_locked_report_redacts_projection_until_entitled(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.narrative.enabled', true);
        config()->set('ai.narrative.provider', 'mock');
        config()->set('ai.breaker_enabled', false);

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();
        $this->configureBigFivePaidReports();

        $anonId = 'anon_big5_paid_redaction';
        $attemptId = $this->seedAttempt($anonId);
        $this->enableBigFiveCommerceForAttempt($attemptId);
        $this->seedResult($attemptId);
        $token = $this->issueAnonToken($anonId);

        $locked = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $locked->assertOk();
        $locked->assertJsonPath('locked', true);
        $locked->assertJsonPath('variant', 'free');
        $locked->assertJsonPath('access_level', 'free');
        $locked->assertJsonCount(0, 'big5_public_projection_v1.facet_vector');
        $locked->assertJsonMissingPath('big5_public_projection_v1.trait_vector.0.percentile');
        $locked->assertJsonMissingPath('big5_public_projection_v1.trait_vector.0.mean');
        $locked->assertJsonMissingPath('big5_public_projection_v1.controlled_narrative_v1');
        $locked->assertJsonMissingPath('big5_public_projection_v1.cultural_calibration_v1');
        $locked->assertJsonMissingPath('big5_public_projection_v1.comparative_v1');
        $this->assertSame('locked', $locked->json('report.sections.2.status'));
        $this->assertSame([], $locked->json('report.sections.2.blocks'));

        app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'BIG5_FULL_REPORT',
            $attemptId,
            null,
            null,
            null,
            [
                ReportAccess::MODULE_BIG5_CORE,
                ReportAccess::MODULE_BIG5_FULL,
                ReportAccess::MODULE_BIG5_ACTION_PLAN,
            ]
        );

        $full = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $full->assertOk();
        $full->assertJsonPath('locked', false);
        $full->assertJsonPath('variant', 'full');
        $full->assertJsonPath('access_level', 'full');
        $full->assertJsonCount(30, 'big5_public_projection_v1.facet_vector');
        $full->assertJsonPath('big5_public_projection_v1.facet_vector.0.key', 'N1');
        $full->assertJsonMissingPath('big5_public_projection_v1.controlled_narrative_v1');
        $this->assertNotEmpty($full->json('report.sections.2.blocks'));
    }

    private function seedAttempt(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinutes(8),
            'submitted_at' => now()->subMinutes(1),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        return $attemptId;
    }

    private function seedResult(string $attemptId): void
    {
        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);

        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = $loader->readCompiledJson('policy.compiled.json', 'v1');

        $questionIndex = [];
        foreach ((array) ($questions['question_index'] ?? []) as $qid => $row) {
            if (is_array($row)) {
                $questionIndex[(int) $qid] = $row;
            }
        }

        $answersById = [];
        for ($i = 1; $i <= 120; $i++) {
            $answersById[$i] = 3;
        }

        $score = $scorer->score(
            $answersById,
            $questionIndex,
            is_array($norms) ? $norms : [],
            is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [],
            [
                'locale' => 'zh-CN',
                'country' => 'CN_MAINLAND',
                'region' => 'CN_MAINLAND',
                'gender' => 'ALL',
                'age_band' => 'all',
                'time_seconds_total' => 480.0,
                'duration_ms' => 480000,
            ]
        );

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'type_code' => 'BIG5',
            'scores_json' => json_encode(['domains_mean' => $score['raw_scores']['domains_mean'] ?? []], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode($score['scores_0_100']['domains_percentile'] ?? [], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'content_package_version' => 'v1',
            'result_json' => json_encode([
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => [
                    'score_result' => $score,
                    'scores_pct' => $score['scores_0_100']['domains_percentile'] ?? [],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function configureBigFivePaidReports(): void
    {
        $scale = ScaleRegistryV2::queryByOrgWhitelist([0])
            ->where('org_id', 0)
            ->where('code', 'BIG5_OCEAN')
            ->firstOrFail();

        $capabilities = (array) $scale->capabilities_json;
        $capabilities['paywall_mode'] = 'full';

        $payload = $scale->only($scale->getFillable());
        $payload['capabilities_json'] = $capabilities;
        $payload['view_policy_json'] = [
            'free_sections' => ['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'],
            'blur_others' => true,
            'teaser_percent' => 0.0,
            'upgrade_sku' => 'SKU_BIG5_FULL_REPORT_499',
        ];
        $payload['commercial_json'] = [
            'price_tier' => 'PAID',
            'report_benefit_code' => 'BIG5_FULL_REPORT',
            'credit_benefit_code' => 'BIG5_FULL_REPORT',
            'report_unlock_sku' => 'SKU_BIG5_FULL_REPORT_499',
        ];

        app(ScaleRegistryWriter::class)->upsertScale($payload);
    }

    private function enableBigFiveCommerceForAttempt(string $attemptId): void
    {
        config()->set('report_unlock.rollout_scales', ['MBTI', 'BIG5_OCEAN']);
        config()->set('report_unlock.big5_rollout', [
            'mode' => 'allowlist_only',
            'percentage' => 0,
            'max_percentage' => 10,
            'emergency_disabled' => false,
            'allowed_attempt_ids' => [$attemptId],
            'allowed_user_ids' => [],
            'allowed_anon_ids' => [],
            'allowed_org_ids' => [],
            'allowed_tenant_ids' => ['0'],
            'allowed_form_codes' => ['big5_90', 'big5_120'],
            'allowed_locales' => ['zh-CN'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
