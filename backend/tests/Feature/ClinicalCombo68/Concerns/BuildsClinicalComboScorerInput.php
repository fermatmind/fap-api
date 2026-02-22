<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68\Concerns;

use App\Services\Assessment\Scorers\ClinicalCombo68ScorerV1;
use App\Services\Content\ClinicalComboPackLoader;

trait BuildsClinicalComboScorerInput
{
    /**
     * @param array<int,string> $overrides
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    protected function scoreClinical(array $overrides = [], array $ctx = []): array
    {
        /** @var ClinicalComboPackLoader $loader */
        $loader = app(ClinicalComboPackLoader::class);
        /** @var ClinicalCombo68ScorerV1 $scorer */
        $scorer = app(ClinicalCombo68ScorerV1::class);

        $answers = $this->buildAnswers('A');
        foreach ($overrides as $qid => $code) {
            $qid = (int) $qid;
            if ($qid >= 1 && $qid <= 68) {
                $answers[$qid] = strtoupper(trim((string) $code));
            }
        }

        $questionIndex = $loader->loadQuestionIndex('v1');
        $optionSets = $loader->loadOptionSets('v1');
        $policy = $loader->loadPolicy('v1');

        $ctx = array_merge([
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'started_at' => now()->subSeconds(315)->toISOString(),
            'submitted_at' => now()->toISOString(),
            'duration_ms' => 10,
            'content_manifest_hash' => '',
        ], $ctx);

        return $scorer->score($answers, $questionIndex, $optionSets, $policy, $ctx);
    }

    /**
     * @return array<int,string>
     */
    protected function buildAnswers(string $code = 'A'): array
    {
        $code = strtoupper(trim($code));
        if (!in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
            $code = 'A';
        }

        $out = [];
        for ($i = 1; $i <= 68; $i++) {
            $out[$i] = $code;
        }

        return $out;
    }
}

