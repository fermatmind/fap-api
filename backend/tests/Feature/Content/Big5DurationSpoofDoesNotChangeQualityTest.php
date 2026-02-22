<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Big5DurationSpoofDoesNotChangeQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_spoof_does_not_change_big5_quality_when_server_duration_exists(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);

        $questions = (array) $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = (array) $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = (array) $loader->readCompiledJson('policy.compiled.json', 'v1');

        $questionIndex = [];
        foreach ((array) ($questions['question_index'] ?? []) as $qid => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $questionIndex[(int) $qid] = $meta;
        }

        $answersById = [];
        foreach (array_keys($questionIndex) as $qid) {
            $answersById[(int) $qid] = 3;
        }

        $policy = is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [];

        $baseCtx = [
            'locale' => 'zh-CN',
            'country' => 'CN_MAINLAND',
            'region' => 'CN_MAINLAND',
            'gender' => 'ALL',
            'age_band' => 'all',
            'started_at' => '2026-02-22T00:00:00Z',
            'submitted_at' => '2026-02-22T00:05:00Z',
            'server_duration_seconds' => 300,
            'validity_items' => [],
        ];

        $fastClient = $scorer->score($answersById, $questionIndex, $norms, $policy, $baseCtx + [
            'duration_ms' => 10,
        ]);
        $slowClient = $scorer->score($answersById, $questionIndex, $norms, $policy, $baseCtx + [
            'duration_ms' => 9_999_999,
        ]);

        $this->assertSame(
            (string) data_get($fastClient, 'quality.level', ''),
            (string) data_get($slowClient, 'quality.level', '')
        );
        $this->assertSame(
            (array) data_get($fastClient, 'quality.flags', []),
            (array) data_get($slowClient, 'quality.flags', [])
        );
        $this->assertSame(
            (array) data_get($fastClient, 'scores_0_100.domains_percentile', []),
            (array) data_get($slowClient, 'scores_0_100.domains_percentile', [])
        );
        $this->assertSame(
            (float) data_get($fastClient, 'quality.metrics.time_seconds_total', 0.0),
            (float) data_get($slowClient, 'quality.metrics.time_seconds_total', 0.0)
        );
    }
}
