<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;

final class RiasecPublicFormSummaryBuilder
{
    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
    ) {}

    public function build(?Attempt $attempt, ?Result $result = null): ?array
    {
        $formCode = trim((string) data_get($attempt?->answers_summary_json, 'meta.form_code', ''));
        if ($formCode === '') {
            $formCode = trim((string) data_get($result?->result_json, 'form_code', ''));
        }
        if ($formCode === '') {
            $formCode = ((int) ($attempt?->question_count ?? 0)) >= 140 ? 'riasec_140' : 'riasec_60';
        }

        $questionCount = (int) ($attempt?->question_count ?? 0);
        if ($questionCount <= 0) {
            $questionCount = str_contains($formCode, '140') ? 140 : 60;
        }

        $formCode = $this->measurementContract->canonicalFormCode($formCode, $questionCount);
        $measurementContract = $this->measurementContract->forFormCode($formCode, $questionCount);
        $comparePolicy = is_array($measurementContract['compare_policy'] ?? null)
            ? $measurementContract['compare_policy']
            : $this->measurementContract->comparePolicyForFormCode($formCode, $questionCount);

        return [
            'form_code' => $formCode,
            'question_count' => $questionCount,
            'form_kind' => str_contains($formCode, '140') ? 'enhanced' : 'standard',
            'estimated_minutes' => str_contains($formCode, '140') ? 18 : 8,
            'measurement_contract_version' => RiasecMeasurementContract::SCHEMA_VERSION,
            'score_space_version' => (string) data_get($measurementContract, 'form.score_space_version', ''),
            'compare_compatibility_group' => (string) ($comparePolicy['compare_compatibility_group'] ?? ''),
            'cross_form_comparable' => false,
            'raw_score_delta_allowed' => false,
            'measurement_contract_v1' => $measurementContract,
            'compare_policy_v1' => $comparePolicy,
        ];
    }
}
