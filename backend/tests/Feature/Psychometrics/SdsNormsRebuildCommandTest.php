<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SdsNormsRebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_command_publishes_sds_group_with_index_metric(): void
    {
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_sds_a', 60, 'A');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_sds_c', 70, 'C');

        $this->artisan('norms:sds:rebuild --locale=zh-CN --group=all_18-60 --window_days=365 --min_samples=1 --only_quality=AB --activate=1')
            ->assertExitCode(0);

        $version = DB::table('scale_norms_versions')
            ->where('scale_code', 'SDS_20')
            ->where('group_id', 'zh-CN_all_18-60')
            ->where('is_active', 1)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame('CALIBRATED', (string) $version->status);

        $metric = DB::table('scale_norm_stats')
            ->where('norm_version_id', (string) $version->id)
            ->where('metric_level', 'global')
            ->where('metric_code', 'INDEX_SCORE')
            ->first();

        $this->assertNotNull($metric);
        $this->assertSame(1, (int) $metric->sample_n);
        $this->assertEqualsWithDelta(60.0, (float) $metric->mean, 0.001);
    }

    private function insertAttemptResult(string $locale, string $region, string $anonId, int $indexScore, string $quality): void
    {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'anon_id' => $anonId,
            'user_id' => null,
            'org_id' => 0,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v1',
            'question_count' => 20,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'test',
            'client_version' => '1.0',
            'channel' => 'ci',
            'referrer' => 'unit',
            'region' => $region,
            'locale' => $locale,
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(1),
        ]);

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v1',
            'type_code' => 'SDS20',
            'scores_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'profile_version' => null,
            'content_package_version' => 'v1',
            'result_json' => json_encode([
                'quality' => [
                    'level' => strtoupper($quality),
                    'flags' => [],
                    'crisis_alert' => false,
                ],
                'scores' => [
                    'global' => [
                        'raw' => 48,
                        'index_score' => $indexScore,
                        'clinical_level' => 'mild_depression',
                    ],
                    'factors' => [
                        'psycho_affective' => ['score' => 4, 'max' => 8, 'severity' => 'medium'],
                        'somatic' => ['score' => 20, 'max' => 32, 'severity' => 'medium'],
                        'psychomotor' => ['score' => 7, 'max' => 12, 'severity' => 'medium'],
                        'cognitive' => ['score' => 14, 'max' => 28, 'severity' => 'medium'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
