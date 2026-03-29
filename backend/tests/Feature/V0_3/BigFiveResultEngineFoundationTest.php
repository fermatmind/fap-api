<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Content\BigFivePackLoader;
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
        $resultResponse->assertJsonPath('big5_public_projection_v1.ordered_section_keys.0', 'traits.overview');
        $resultResponse->assertJsonPath('big5_public_projection_v1.trait_bands.O', 'mid');
        $resultResponse->assertJsonCount(30, 'big5_public_projection_v1.facet_vector');
        $resultResponse->assertJsonPath('big5_public_projection_v1.facet_vector.0.key', 'N1');
        $resultResponse->assertJsonPath('big5_public_projection_v1.facet_vector.0.domain', 'N');
        $resultResponse->assertJsonPath('big5_public_projection_v1.controlled_narrative_v1.version', 'controlled_narrative.v1');
        $resultResponse->assertJsonPath('big5_public_projection_v1.controlled_narrative_v1.runtime_mode', 'mock');
        $resultResponse->assertJsonPath('big5_public_projection_v1.cultural_calibration_v1.version', 'cultural_calibration.v1');
        $resultResponse->assertJsonPath('big5_public_projection_v1.cultural_calibration_v1.cultural_context', 'CN_MAINLAND.zh-CN');
        $resultResponse->assertJsonPath('big5_public_projection_v1.comparative_v1.version', 'comparative.norming.v1');
        $this->assertGreaterThan(0, (int) $resultResponse->json('big5_public_projection_v1.comparative_v1.percentile.value'));
        $this->assertNotSame('', trim((string) $resultResponse->json('big5_public_projection_v1.comparative_v1.norming_source')));

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $reportResponse->assertOk();
        $reportResponse->assertJsonPath('big5_public_projection_v1.schema_version', 'big5.public_projection.v1');
        $reportResponse->assertJsonPath('big5_public_projection_v1.ordered_section_keys.1', 'traits.why_this_profile');
        $reportResponse->assertJsonCount(30, 'big5_public_projection_v1.facet_vector');
        $reportResponse->assertJsonPath('big5_public_projection_v1.facet_vector.0.key', 'N1');
        $reportResponse->assertJsonPath('big5_public_projection_v1.facet_vector.0.domain', 'N');
        $reportResponse->assertJsonPath('report._meta.big5_public_projection_v1.schema_version', 'big5.public_projection.v1');
        $reportResponse->assertJsonCount(30, 'report._meta.big5_public_projection_v1.facet_vector');
        $reportResponse->assertJsonPath('report._meta.big5_public_projection_v1.controlled_narrative_v1.version', 'controlled_narrative.v1');
        $reportResponse->assertJsonPath('report._meta.big5_public_projection_v1.cultural_calibration_v1.version', 'cultural_calibration.v1');
        $reportResponse->assertJsonPath('report._meta.big5_public_projection_v1.comparative_v1.version', 'comparative.norming.v1');
        $reportResponse->assertJsonPath('report.sections.0.key', 'traits.overview');
        $reportResponse->assertJsonPath('report.sections.4.key', 'growth.next_actions');
        $comparativePercentile = (int) $reportResponse->json('big5_public_projection_v1.comparative_v1.percentile.value');
        $this->assertGreaterThan(0, $comparativePercentile);

        $reportEvent = DB::table('events')
            ->where('event_code', 'report_view')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($reportEvent);
        $meta = $this->decodeMeta($reportEvent->meta_json ?? null);
        $this->assertSame(['traits.overview', 'traits.why_this_profile', 'relationships.interpersonal_style', 'career.work_style', 'growth.next_actions'], array_slice((array) ($meta['ordered_section_keys'] ?? []), 0, 5));
        $this->assertArrayHasKey('trait_bands', $meta);
        $this->assertArrayHasKey('scene_fingerprint', $meta);
        $this->assertSame('controlled_narrative.v1', (string) ($meta['narrative_contract_version'] ?? ''));
        $this->assertSame('mock', (string) ($meta['narrative_runtime_mode'] ?? ''));
        $this->assertSame('zh-CN', (string) ($meta['locale_context'] ?? ''));
        $this->assertSame('CN_MAINLAND.zh-CN', (string) ($meta['cultural_context'] ?? ''));
        $this->assertSame('cultural_calibration.v1', (string) ($meta['calibration_contract_version'] ?? ''));
        $this->assertContains('traits.overview', $meta['calibrated_section_keys'] ?? []);
        $this->assertSame('comparative.norming.v1', (string) ($meta['comparative_contract_version'] ?? ''));
        $this->assertNotSame('', trim((string) ($meta['comparative_fingerprint'] ?? '')));
        $this->assertNotSame('', trim((string) ($meta['norming_version'] ?? '')));
        $this->assertNotSame('', trim((string) ($meta['norming_scope'] ?? '')));
        $this->assertNotSame('', trim((string) ($meta['norming_source'] ?? '')));
        $this->assertSame($comparativePercentile, (int) data_get($meta, 'comparative_v1.percentile.value'));
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
