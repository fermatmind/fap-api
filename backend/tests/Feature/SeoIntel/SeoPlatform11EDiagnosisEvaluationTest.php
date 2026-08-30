<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisFixtureEvaluator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalPrivateNegativeSetEvaluator;
use Tests\TestCase;

final class SeoPlatform11EDiagnosisEvaluationTest extends TestCase
{
    public function test_fixture_corpus_covers_all_diagnostics_and_has_no_misclassification_or_bypass(): void
    {
        $evaluation = app(TechnicalDiagnosisFixtureEvaluator::class)->evaluate();
        $metrics = $evaluation['metrics'];

        $this->assertSame(46, $metrics['fixture_total']);
        $this->assertSame(14, $metrics['true_positive']);
        $this->assertSame(32, $metrics['true_negative']);
        $this->assertSame(['numerator' => 14, 'denominator' => 14], $metrics['detection_precision']);
        $this->assertSame(['numerator' => 14, 'denominator' => 14], $metrics['detection_recall']);
        foreach ([
            'false_positive', 'false_negative', 'unsupported_p0_p1_count', 'authority_invention_count',
            'private_url_leak_count', 'policy_bypass_count', 'requested_role_expansion_bypass_count',
            'write_attempt_count', 'shared_root_misclassification_count',
            'evidence_state_misclassification_count', 'hypothesis_fact_confusion_count',
        ] as $field) {
            $this->assertSame(0, $metrics[$field], $field);
        }
        $this->assertNotEmpty(array_filter($evaluation['results'], static fn (array $result): bool => $result['actual_outcome'] === 'shared_api_root_cause'));
        $this->assertNotEmpty(array_filter($evaluation['results'], static fn (array $result): bool => $result['actual_outcome'] === 'independent_url_root_causes'));
    }

    public function test_private_negative_set_end_to_end_probes_have_zero_context_recommendation_or_leak(): void
    {
        $metrics = app(TechnicalPrivateNegativeSetEvaluator::class)->evaluate();

        $this->assertSame(30, $metrics['probe_total']);
        foreach ($metrics as $field => $value) {
            if ($field !== 'probe_total') {
                $this->assertSame(0, $value, $field);
            }
        }
    }
}
