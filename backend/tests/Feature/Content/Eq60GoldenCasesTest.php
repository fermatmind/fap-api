<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use Tests\TestCase;

final class Eq60GoldenCasesTest extends TestCase
{
    public function test_compiled_golden_cases_match_scorer_output(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $questionIndex = $loader->loadQuestionIndex('v1');
        $options = $loader->loadOptions('v1');
        $policy = $loader->loadPolicy('v1');
        $cases = $loader->loadGoldenCases('v1');
        $manifestHash = $loader->resolveManifestHash('v1');

        $this->assertNotEmpty($cases);
        $this->assertNotEmpty($questionIndex);
        $this->assertNotEmpty((array) ($options['score_map'] ?? []));

        foreach ($cases as $case) {
            $caseId = trim((string) ($case['case_id'] ?? ''));
            $answerString = strtoupper(trim((string) ($case['answers'] ?? '')));
            $answers = [];
            for ($qid = 1; $qid <= 60; $qid++) {
                $answers[$qid] = substr($answerString, $qid - 1, 1);
            }

            $payload = $scorer->score(
                $answers,
                $questionIndex,
                $policy,
                [
                    'score_map' => (array) ($options['score_map'] ?? []),
                    'duration_ms' => 420000,
                    'started_at' => now()->subSeconds(420)->toISOString(),
                    'submitted_at' => now()->toISOString(),
                    'locale' => (string) ($case['locale'] ?? 'zh-CN'),
                    'region' => 'CN_MAINLAND',
                    'pack_id' => Eq60PackLoader::PACK_ID,
                    'dir_version' => 'v1',
                    'content_manifest_hash' => $manifestHash,
                ]
            );

            $this->assertSame(
                (int) ($case['expected_total'] ?? 0),
                (int) data_get($payload, 'scores.global.raw_sum', -1),
                'golden case total mismatch: ' . $caseId
            );
            $this->assertSame(
                (int) ($case['expected_sa'] ?? 0),
                (int) data_get($payload, 'scores.SA.raw_sum', -1),
                'golden case SA mismatch: ' . $caseId
            );
            $this->assertSame(
                (int) ($case['expected_er'] ?? 0),
                (int) data_get($payload, 'scores.ER.raw_sum', -1),
                'golden case ER mismatch: ' . $caseId
            );
            $this->assertSame(
                (int) ($case['expected_em'] ?? 0),
                (int) data_get($payload, 'scores.EM.raw_sum', -1),
                'golden case EM mismatch: ' . $caseId
            );
            $this->assertSame(
                (int) ($case['expected_rm'] ?? 0),
                (int) data_get($payload, 'scores.RM.raw_sum', -1),
                'golden case RM mismatch: ' . $caseId
            );
        }
    }
}

