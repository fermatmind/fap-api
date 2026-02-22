<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Services\Assessment\Scorers\ClinicalCombo68ScorerV1;
use App\Services\Content\ClinicalComboPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalComboGoldenCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_golden_cases_are_stable_for_crisis_and_masked_depression(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);

        /** @var ClinicalComboPackLoader $loader */
        $loader = app(ClinicalComboPackLoader::class);
        /** @var ClinicalCombo68ScorerV1 $scorer */
        $scorer = app(ClinicalCombo68ScorerV1::class);

        $questionIndex = $loader->loadQuestionIndex('v1');
        $optionSets = $loader->loadOptionSets('v1');
        $policy = $loader->loadPolicy('v1');
        $golden = $loader->readCompiledJson('golden_cases.compiled.json', 'v1');

        $this->assertIsArray($golden);
        $cases = is_array($golden['cases'] ?? null) ? $golden['cases'] : [];
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $answersString = strtoupper(trim((string) ($case['answers'] ?? '')));
            $this->assertSame(68, strlen($answersString));

            $answers = [];
            for ($i = 1; $i <= 68; $i++) {
                $answers[$i] = substr($answersString, $i - 1, 1);
            }

            $score = $scorer->score($answers, $questionIndex, $optionSets, $policy, [
                'pack_id' => 'CLINICAL_COMBO_68',
                'dir_version' => 'v1',
                'started_at' => now()->subSeconds((int) ($case['time_seconds_total'] ?? 300))->toISOString(),
                'submitted_at' => now()->toISOString(),
            ]);

            $this->assertSame(
                (bool) ($case['expected_crisis_alert'] ?? false),
                (bool) data_get($score, 'quality.crisis_alert', false),
                'golden case crisis mismatch: '.(string) ($case['case_id'] ?? '')
            );

            $flags = (array) data_get($score, 'scores.depression.flags', []);
            $this->assertSame(
                (bool) ($case['expected_masked_depression'] ?? false),
                in_array('masked_depression', $flags, true),
                'golden case masked_depression mismatch: '.(string) ($case['case_id'] ?? '')
            );
        }
    }
}

