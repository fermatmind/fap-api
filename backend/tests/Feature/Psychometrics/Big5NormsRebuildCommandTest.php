<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5NormsRebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_command_publishes_target_group_with_35_metrics(): void
    {
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_zh_a', 3.4, 3.6, 3.1, 3.3, 3.0, 'A');
        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_zh_c', 3.9, 3.7, 3.4, 3.8, 3.5, 'C');

        $this->artisan('norms:big5:rebuild --locale=zh-CN --group=prod_all_18-60 --window_days=365 --min_samples=1 --only_quality=AB --activate=1')
            ->assertExitCode(0);

        $version = DB::table('scale_norms_versions')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('group_id', 'zh-CN_prod_all_18-60')
            ->where('is_active', 1)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame('CALIBRATED', (string) $version->status);
        $this->assertSame(35, DB::table('scale_norm_stats')->where('norm_version_id', (string) $version->id)->count());

        $domainN = DB::table('scale_norm_stats')
            ->where('norm_version_id', (string) $version->id)
            ->where('metric_level', 'domain')
            ->where('metric_code', 'N')
            ->value('sample_n');
        $this->assertSame(1, (int) $domainN);
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

