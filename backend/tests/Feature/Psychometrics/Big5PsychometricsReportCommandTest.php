<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5PsychometricsReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_psychometrics_command_generates_report_row(): void
    {
        $this->seedActiveNormVersion('zh-CN');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_a_1', 3.8, 3.4, 3.2, 3.6, 2.9, 'A');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_c_1', 3.1, 3.0, 3.0, 3.2, 3.2, 'C');

        $this->artisan('big5:psychometrics --scale=BIG5_OCEAN --norms_version=latest --locale=zh-CN --region=CN_MAINLAND --window=last_90_days --only_quality=AB --min_samples=1')
            ->assertExitCode(0);

        $report = DB::table('big5_psychometrics_reports')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('locale', 'zh-CN')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($report);
        $this->assertSame(1, (int) $report->sample_n);
        $this->assertSame('2026Q1_v1', (string) $report->norms_version);

        $metrics = json_decode((string) $report->metrics_json, true);
        $this->assertIsArray($metrics);
        $this->assertSame(1, (int) ($metrics['sample_n'] ?? 0));
        $this->assertArrayHasKey('domain_alpha', $metrics);
        $this->assertArrayHasKey('domain_stats', $metrics);
        $this->assertArrayHasKey('facet_item_total_corr', $metrics);
        $this->assertArrayHasKey('O', (array) ($metrics['domain_alpha'] ?? []));
        $this->assertArrayHasKey('N1', (array) ($metrics['facet_item_total_corr'] ?? []));
    }

    public function test_psychometrics_command_returns_2_when_samples_below_threshold(): void
    {
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_a_2', 3.8, 3.4, 3.2, 3.6, 2.9, 'A');

        $this->artisan('big5:psychometrics --scale=BIG5_OCEAN --locale=zh-CN --region=CN_MAINLAND --window=last_90_days --only_quality=AB --min_samples=2')
            ->assertExitCode(2);
    }

    private function seedActiveNormVersion(string $locale): void
    {
        DB::table('scale_norms_versions')->insert([
            'id' => (string) Str::uuid(),
            'scale_code' => 'BIG5_OCEAN',
            'norm_id' => $locale . '_prod_all_18-60',
            'region' => $locale === 'zh-CN' ? 'CN_MAINLAND' : 'GLOBAL',
            'locale' => $locale,
            'version' => '2026Q1_v1',
            'group_id' => $locale . '_prod_all_18-60',
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'FERMATMIND_PROD_ROLLING',
            'source_type' => 'internal_prod',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => now(),
            'checksum' => hash('sha256', '2026Q1_v1|' . $locale),
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttemptResult(
        string $locale,
        string $region,
        string $anonId,
        float $o,
        float $c,
        float $e,
        float $a,
        float $n,
        string $qualityLevel
    ): void {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'anon_id' => $anonId,
            'user_id' => null,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'question_count' => 120,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'test',
            'client_version' => '1.0',
            'channel' => 'ci',
            'referrer' => 'unit',
            'region' => $region,
            'locale' => $locale,
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(1),
        ]);

        $facets = $this->facetMeans($o, $c, $e, $a, $n);

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'type_code' => 'BIG5',
            'scores_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'profile_version' => null,
            'content_package_version' => 'v1',
            'result_json' => json_encode([
                'raw_scores' => [
                    'domains_mean' => [
                        'O' => $o,
                        'C' => $c,
                        'E' => $e,
                        'A' => $a,
                        'N' => $n,
                    ],
                    'facets_mean' => $facets,
                ],
                'quality' => [
                    'level' => strtoupper($qualityLevel),
                    'flags' => [],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return array<string,float>
     */
    private function facetMeans(float $o, float $c, float $e, float $a, float $n): array
    {
        return [
            'N1' => $n, 'N2' => $n, 'N3' => $n, 'N4' => $n, 'N5' => $n, 'N6' => $n,
            'E1' => $e, 'E2' => $e, 'E3' => $e, 'E4' => $e, 'E5' => $e, 'E6' => $e,
            'O1' => $o, 'O2' => $o, 'O3' => $o, 'O4' => $o, 'O5' => $o, 'O6' => $o,
            'A1' => $a, 'A2' => $a, 'A3' => $a, 'A4' => $a, 'A5' => $a, 'A6' => $a,
            'C1' => $c, 'C2' => $c, 'C3' => $c, 'C4' => $c, 'C5' => $c, 'C6' => $c,
        ];
    }
}
