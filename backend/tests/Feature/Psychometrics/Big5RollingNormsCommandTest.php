<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5RollingNormsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_roll_command_publishes_active_norms_versions(): void
    {
        Config::set('big5_norms.rolling.publish_thresholds.zh-CN_prod_all_18-60', 1);
        Config::set('big5_norms.rolling.publish_thresholds.en_prod_all_18-60', 1);

        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'anon_zh_1', 3.4, 3.6, 3.1, 3.3, 3.0);
        $this->insertAttemptResult('en', 'GLOBAL', 'anon_en_1', 3.7, 3.8, 3.2, 3.5, 2.9);

        $this->artisan('norms:big5:roll --window_days=365')->assertExitCode(0);

        $zh = DB::table('scale_norms_versions')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('group_id', 'zh-CN_prod_all_18-60')
            ->where('is_active', 1)
            ->first();
        $this->assertNotNull($zh);

        $en = DB::table('scale_norms_versions')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('group_id', 'en_prod_all_18-60')
            ->where('is_active', 1)
            ->first();
        $this->assertNotNull($en);

        $this->assertSame(35, DB::table('scale_norm_stats')->where('norm_version_id', (string) $zh->id)->count());
        $this->assertSame(35, DB::table('scale_norm_stats')->where('norm_version_id', (string) $en->id)->count());
    }

    private function insertAttemptResult(string $locale, string $region, string $anonId, float $o, float $c, float $e, float $a, float $n): void
    {
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
                    'level' => 'A',
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
