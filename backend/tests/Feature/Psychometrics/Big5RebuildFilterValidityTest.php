<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5RebuildFilterValidityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_filters_attention_check_failed_records(): void
    {
        $this->insertAttemptResult('anon_bad', 'A', ['ATTENTION_CHECK_FAILED'], 2.2);
        $this->insertAttemptResult('anon_good', 'A', [], 3.8);

        $this->artisan('norms:big5:rebuild --locale=zh-CN --region=CN_MAINLAND --group=prod_all_18-60 --window_days=365 --min_samples=1 --only_quality=AB --activate=1')
            ->assertExitCode(0);

        $version = DB::table('scale_norms_versions')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('group_id', 'zh-CN_prod_all_18-60')
            ->where('is_active', 1)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($version);

        $sampleN = DB::table('scale_norm_stats')
            ->where('norm_version_id', (string) $version->id)
            ->where('metric_level', 'domain')
            ->where('metric_code', 'N')
            ->value('sample_n');
        $this->assertSame(1, (int) $sampleN);

        $meanN = DB::table('scale_norm_stats')
            ->where('norm_version_id', (string) $version->id)
            ->where('metric_level', 'domain')
            ->where('metric_code', 'N')
            ->value('mean');
        $this->assertGreaterThan(3.0, (float) $meanN);
    }

    /**
     * @param list<string> $flags
     */
    private function insertAttemptResult(
        string $anonId,
        string $qualityLevel,
        array $flags,
        float $baseMean
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
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(1),
        ]);

        $facets = $this->facetMeans($baseMean);

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
                        'O' => $baseMean,
                        'C' => $baseMean,
                        'E' => $baseMean,
                        'A' => $baseMean,
                        'N' => $baseMean,
                    ],
                    'facets_mean' => $facets,
                ],
                'quality' => [
                    'level' => strtoupper($qualityLevel),
                    'flags' => $flags,
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
    private function facetMeans(float $baseMean): array
    {
        return [
            'N1' => $baseMean, 'N2' => $baseMean, 'N3' => $baseMean, 'N4' => $baseMean, 'N5' => $baseMean, 'N6' => $baseMean,
            'E1' => $baseMean, 'E2' => $baseMean, 'E3' => $baseMean, 'E4' => $baseMean, 'E5' => $baseMean, 'E6' => $baseMean,
            'O1' => $baseMean, 'O2' => $baseMean, 'O3' => $baseMean, 'O4' => $baseMean, 'O5' => $baseMean, 'O6' => $baseMean,
            'A1' => $baseMean, 'A2' => $baseMean, 'A3' => $baseMean, 'A4' => $baseMean, 'A5' => $baseMean, 'A6' => $baseMean,
            'C1' => $baseMean, 'C2' => $baseMean, 'C3' => $baseMean, 'C4' => $baseMean, 'C5' => $baseMean, 'C6' => $baseMean,
        ];
    }
}
