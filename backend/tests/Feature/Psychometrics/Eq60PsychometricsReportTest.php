<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60PsychometricsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_psychometrics_command_generates_report_row(): void
    {
        $this->seedActiveNormVersion('zh-CN');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_eq_a1', 'A', 112, 110, 104, 108, 111, ['profile:emotion_leader']);
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_eq_c1', 'C', 96, 95, 90, 92, 94, ['data_quality:warning']);

        $this->artisan('eq60:psychometrics --scale=EQ_60 --norms_version=latest --locale=zh-CN --region=CN_MAINLAND --window=last_90_days --only_quality=AB --min_samples=1')
            ->assertExitCode(0);

        $report = DB::table('eq60_psychometrics_reports')
            ->where('scale_code', 'EQ_60')
            ->where('locale', 'zh-CN')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($report);
        $this->assertSame(1, (int) $report->sample_n);
        $this->assertSame('bootstrap_v1', (string) $report->norms_version);

        $metrics = json_decode((string) $report->metrics_json, true);
        $this->assertIsArray($metrics);
        $this->assertSame(1, (int) ($metrics['sample_n'] ?? 0));
        $this->assertArrayHasKey('global_std_mean', $metrics);
        $this->assertArrayHasKey('dimension_std_summary', $metrics);
        $this->assertArrayHasKey('quality_flag_rates', $metrics);
        $this->assertArrayHasKey('top_report_tags', $metrics);
        $this->assertEqualsWithDelta(0.5, (float) ($metrics['quality_c_or_worse_rate'] ?? 0.0), 0.0001);
    }

    public function test_psychometrics_command_returns_2_when_samples_below_threshold(): void
    {
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_eq_a2', 'A', 108, 106, 104, 105, 107, []);

        $this->artisan('eq60:psychometrics --scale=EQ_60 --locale=zh-CN --region=CN_MAINLAND --window=last_90_days --only_quality=AB --min_samples=2')
            ->assertExitCode(2);
    }

    private function seedActiveNormVersion(string $locale): void
    {
        DB::table('scale_norms_versions')->insert([
            'id' => (string) Str::uuid(),
            'scale_code' => 'EQ_60',
            'norm_id' => $locale.'_all_18-60',
            'region' => $locale === 'zh-CN' ? 'CN_MAINLAND' : 'GLOBAL',
            'locale' => $locale,
            'version' => 'bootstrap_v1',
            'group_id' => $locale.'_all_18-60',
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'FERMATMIND_EQ60_BOOTSTRAP',
            'source_type' => 'bootstrap',
            'status' => 'PROVISIONAL',
            'is_active' => 1,
            'published_at' => now(),
            'checksum' => hash('sha256', 'bootstrap_v1|'.$locale),
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $tags
     */
    private function insertAttemptResult(
        string $locale,
        string $region,
        string $anonId,
        string $qualityLevel,
        int $globalStd,
        int $saStd,
        int $erStd,
        int $emStd,
        int $rmStd,
        array $tags
    ): void {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'anon_id' => $anonId,
            'user_id' => null,
            'org_id' => 0,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v1',
            'question_count' => 60,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'test',
            'client_version' => '1.0',
            'channel' => 'ci',
            'referrer' => 'unit',
            'region' => $region,
            'locale' => $locale,
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v1',
            'type_code' => 'EQ60',
            'scores_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'profile_version' => null,
            'content_package_version' => 'v1',
            'result_json' => json_encode([
                'quality' => [
                    'level' => strtoupper($qualityLevel),
                    'flags' => $qualityLevel === 'C' ? ['SPEEDING'] : [],
                ],
                'scores' => [
                    'global' => ['std_score' => $globalStd],
                    'SA' => ['std_score' => $saStd],
                    'ER' => ['std_score' => $erStd],
                    'EM' => ['std_score' => $emStd],
                    'RM' => ['std_score' => $rmStd],
                ],
                'report_tags' => $tags,
            ], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
