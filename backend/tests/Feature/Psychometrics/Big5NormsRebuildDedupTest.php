<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use App\Models\Attempt;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5NormsRebuildDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_dedups_same_identity_within_30_days(): void
    {
        $anonId = 'anon_big5_norms_dedup';

        $this->seedAttemptWithScore($anonId, now()->subDays(1));
        $this->seedAttemptWithScore($anonId, now()->subDays(10)); // should be deduped out
        $this->seedAttemptWithScore($anonId, now()->subDays(40)); // should be kept

        $this->artisan(
            'norms:big5:rebuild --locale=zh-CN --region=CN_MAINLAND --group=prod_all_18-60 --min_samples=1 --window_days=365 --dry-run=1'
        )
            ->expectsOutputToContain('sample_n=2')
            ->assertExitCode(0);
    }

    private function seedAttemptWithScore(string $anonId, \DateTimeInterface $submittedAt): void
    {
        $attemptId = (string) Str::uuid();
        Attempt::query()->create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'started_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'answers_summary_json' => ['stage' => 'seed'],
        ]);

        Result::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => ['domains_mean' => $this->domainsMean()],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => $this->scorePayload(),
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => $submittedAt,
        ]);
    }

    /**
     * @return array<string,float>
     */
    private function domainsMean(): array
    {
        return [
            'O' => 3.0,
            'C' => 3.0,
            'E' => 3.0,
            'A' => 3.0,
            'N' => 3.0,
        ];
    }

    /**
     * @return array<string,float>
     */
    private function facetsMean(): array
    {
        $facets = [
            'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
            'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
            'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
            'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
            'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
        ];

        $out = [];
        foreach ($facets as $facet) {
            $out[$facet] = 3.0;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function scorePayload(): array
    {
        return [
            'raw_scores' => [
                'domains_mean' => $this->domainsMean(),
                'facets_mean' => $this->facetsMean(),
            ],
            'quality' => [
                'level' => 'A',
                'flags' => [],
            ],
        ];
    }
}

