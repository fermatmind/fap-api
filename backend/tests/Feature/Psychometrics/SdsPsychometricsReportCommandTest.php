<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SdsPsychometricsReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_psychometrics_command_generates_report_row(): void
    {
        $this->seedActiveNormVersion('zh-CN');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_sds_a1', 58, 'A', false, 'mild_depression');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_sds_c1', 70, 'C', true, 'moderate_depression');

        $this->artisan('sds:psychometrics --scale=SDS_20 --norms_version=latest --locale=zh-CN --region=CN_MAINLAND --window=last_90_days --only_quality=AB --min_samples=1')
            ->assertExitCode(0);

        $report = DB::table('sds_psychometrics_reports')
            ->where('scale_code', 'SDS_20')
            ->where('locale', 'zh-CN')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($report);
        $this->assertSame(1, (int) $report->sample_n);
        $this->assertSame('2026Q1_seed', (string) $report->norms_version);

        $metrics = json_decode((string) $report->metrics_json, true);
        $this->assertIsArray($metrics);
        $this->assertSame(1, (int) ($metrics['sample_n'] ?? 0));
        $this->assertArrayHasKey('crisis_rate', $metrics);
        $this->assertArrayHasKey('quality_c_or_worse_rate', $metrics);
        $this->assertArrayHasKey('clinical_bucket_distribution', $metrics);
        $this->assertArrayHasKey('factor_distribution_summary', $metrics);
        $this->assertEqualsWithDelta(0.5, (float) ($metrics['quality_c_or_worse_rate'] ?? 0.0), 0.0001);
    }

    public function test_psychometrics_command_returns_2_when_samples_below_threshold(): void
    {
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_sds_a2', 56, 'A', false, 'mild_depression');

        $this->artisan('sds:psychometrics --scale=SDS_20 --locale=zh-CN --region=CN_MAINLAND --window=last_90_days --only_quality=AB --min_samples=2')
            ->assertExitCode(2);
    }

    private function seedActiveNormVersion(string $locale): void
    {
        DB::table('scale_norms_versions')->insert([
            'id' => (string) Str::uuid(),
            'scale_code' => 'SDS_20',
            'norm_id' => $locale.'_all_18-60',
            'region' => $locale === 'zh-CN' ? 'CN_MAINLAND' : 'GLOBAL',
            'locale' => $locale,
            'version' => '2026Q1_seed',
            'group_id' => $locale.'_all_18-60',
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'SDS_CN_SEED',
            'source_type' => 'peer_reviewed',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => now(),
            'checksum' => hash('sha256', '2026Q1_seed|'.$locale),
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttemptResult(
        string $locale,
        string $region,
        string $anonId,
        int $indexScore,
        string $quality,
        bool $crisisAlert,
        string $clinicalLevel
    ): void {
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
                    'crisis_alert' => $crisisAlert,
                ],
                'scores' => [
                    'global' => [
                        'raw' => 48,
                        'index_score' => $indexScore,
                        'clinical_level' => $clinicalLevel,
                    ],
                    'factors' => [
                        'psycho_affective' => ['score' => 4, 'max' => 8, 'severity' => 'medium'],
                        'somatic' => ['score' => 22, 'max' => 32, 'severity' => 'high'],
                        'psychomotor' => ['score' => 7, 'max' => 12, 'severity' => 'medium'],
                        'cognitive' => ['score' => 15, 'max' => 28, 'severity' => 'medium'],
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
