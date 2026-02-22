<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use App\Services\Assessment\Scorers\Sds20ScorerV2FactorLogic;
use App\Services\Content\Sds20PackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Sds20GoldenCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_golden_cases_are_stable(): void
    {
        $this->artisan('content:compile --pack=SDS_20 --pack-version=v1')->assertExitCode(0);

        /** @var Sds20PackLoader $loader */
        $loader = app(Sds20PackLoader::class);
        /** @var Sds20ScorerV2FactorLogic $scorer */
        $scorer = app(Sds20ScorerV2FactorLogic::class);

        $questionIndex = $loader->loadQuestionIndex('v1');
        $policy = $loader->loadPolicy('v1');
        $golden = $loader->readCompiledJson('golden_cases.compiled.json', 'v1');

        $this->assertIsArray($golden);
        $cases = is_array($golden['cases'] ?? null) ? $golden['cases'] : [];
        $this->assertGreaterThanOrEqual(6, count($cases));

        foreach ($cases as $case) {
            $this->assertIsArray($case);

            $answersString = strtoupper(trim((string) ($case['answers'] ?? '')));
            $this->assertSame(20, strlen($answersString));

            $answers = [];
            for ($i = 1; $i <= 20; $i++) {
                $answers[$i] = substr($answersString, $i - 1, 1);
            }

            $durationMs = (int) ($case['duration_ms'] ?? 98000);
            $score = $scorer->score($answers, $questionIndex, $policy, [
                'pack_id' => 'SDS_20',
                'dir_version' => 'v1',
                'duration_ms' => $durationMs,
                'started_at' => now()->subMilliseconds($durationMs)->toISOString(),
                'submitted_at' => now()->toISOString(),
                'locale' => (string) ($case['locale'] ?? 'zh-CN'),
                'region' => 'CN_MAINLAND',
            ]);

            $caseId = (string) ($case['case_id'] ?? '');
            $this->assertSame(
                (int) ($case['expected_index_score'] ?? 0),
                (int) data_get($score, 'scores.global.index_score', -1),
                'index_score mismatch: '.$caseId
            );

            $this->assertSame(
                (string) ($case['expected_clinical_level'] ?? ''),
                (string) data_get($score, 'scores.global.clinical_level', ''),
                'clinical_level mismatch: '.$caseId
            );

            $this->assertSame(
                (bool) ($case['expected_crisis_alert'] ?? false),
                (bool) data_get($score, 'quality.crisis_alert', false),
                'crisis_alert mismatch: '.$caseId
            );

            $this->assertSame(
                (string) ($case['expected_quality_level'] ?? ''),
                (string) data_get($score, 'quality.level', ''),
                'quality level mismatch: '.$caseId
            );

            $tags = (array) data_get($score, 'report_tags', []);
            $hasMaskTag = in_array('profile:somatic_exhaustion_mask', $tags, true);
            $this->assertSame(
                (bool) ($case['expected_has_somatic_exhaustion_mask'] ?? false),
                $hasMaskTag,
                'somatic_exhaustion_mask mismatch: '.$caseId
            );
        }
    }
}
