<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20\Concerns;

use App\Services\Assessment\Scorers\Sds20ScorerV2FactorLogic;
use App\Services\Content\Sds20PackLoader;

trait BuildsSds20ScorerInput
{
    /**
     * @param array<int|string,string> $overrides
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    protected function scoreSds(array $overrides = [], array $ctx = []): array
    {
        $answers = $this->buildAnswers('A');
        foreach ($overrides as $qid => $code) {
            $qid = (int) $qid;
            if ($qid < 1 || $qid > 20) {
                continue;
            }
            $answers[$qid] = strtoupper(trim((string) $code));
        }

        return $this->scoreSdsFromAnswers($answers, $ctx);
    }

    /**
     * @param array<int,string> $answers
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    protected function scoreSdsFromAnswers(array $answers, array $ctx = []): array
    {
        /** @var Sds20PackLoader $loader */
        $loader = app(Sds20PackLoader::class);
        /** @var Sds20ScorerV2FactorLogic $scorer */
        $scorer = app(Sds20ScorerV2FactorLogic::class);

        $ctx = array_merge([
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'duration_ms' => 98000,
            'started_at' => now()->subSeconds(98)->toISOString(),
            'submitted_at' => now()->toISOString(),
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'content_manifest_hash' => '',
        ], $ctx);

        return $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            $ctx,
        );
    }

    /**
     * @return array<int,string>
     */
    protected function buildAnswers(string $code = 'A'): array
    {
        $code = strtoupper(trim($code));
        if (!in_array($code, ['A', 'B', 'C', 'D'], true)) {
            $code = 'A';
        }

        $out = [];
        for ($i = 1; $i <= 20; $i++) {
            $out[$i] = $code;
        }

        return $out;
    }
}
