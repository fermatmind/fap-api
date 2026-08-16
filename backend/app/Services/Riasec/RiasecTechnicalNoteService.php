<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use Carbon\CarbonImmutable;

final class RiasecTechnicalNoteService
{
    public const SCHEMA_VERSION = 'riasec.technical_note.v1';

    public const TECHNICAL_NOTE_VERSION = 'riasec_technical_note.v0.1';

    public const METHOD_BOUNDARY_VERSION = 'riasec.method_boundary.v0.1';

    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
        private readonly RiasecLifecycleCopyService $lifecycleCopy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        $standard = $this->measurementContract->forFormCode('riasec_60', 60);
        $enhanced = $this->measurementContract->forFormCode('riasec_140', 140);

        return [
            'technical_note_v1' => [
                'schema_version' => self::SCHEMA_VERSION,
                'scale_code' => 'RIASEC',
                'technical_note_version' => self::TECHNICAL_NOTE_VERSION,
                'measurement_contract_version' => RiasecMeasurementContract::SCHEMA_VERSION,
                'method_boundary_version' => self::METHOD_BOUNDARY_VERSION,
                'sections' => $this->sections(),
                'method_boundaries' => $this->methodBoundaries(),
                'lifecycle_copy_v1' => $this->lifecycleCopy->lifecycleCopyContract(),
                'form_contracts' => [
                    'riasec_60' => [
                        'form_code' => 'riasec_60',
                        'question_count' => 60,
                        'score_space_version' => data_get($standard, 'form.score_space_version'),
                        'normalization_method' => data_get($standard, 'scoring.normalization_method'),
                        'quality_rule_status' => data_get($standard, 'quality.quality_rule_status'),
                        'low_quality_strength' => data_get($standard, 'quality.low_quality_strength'),
                        'cross_form_comparable' => false,
                        'raw_score_delta_allowed' => false,
                    ],
                    'riasec_140' => [
                        'form_code' => 'riasec_140',
                        'question_count' => 140,
                        'score_space_version' => data_get($enhanced, 'form.score_space_version'),
                        'normalization_method' => data_get($enhanced, 'scoring.normalization_method'),
                        'quality_rule_status' => data_get($enhanced, 'quality.quality_rule_status'),
                        'low_quality_strength' => data_get($enhanced, 'quality.low_quality_strength'),
                        'cross_form_comparable' => false,
                        'raw_score_delta_allowed' => false,
                    ],
                ],
                'data_status_summary' => [
                    'currently_operational' => [
                        '60q_scoring_v1',
                        'projection_v2_minimal',
                        'score_space_version',
                        'compare_policy',
                        'snapshot_bound_report',
                    ],
                    'partial' => [
                        'activity_explorer_v0_1',
                        'pdf_share_history_snapshot_surfaces',
                    ],
                    'not_claimed' => [
                        'ability',
                        'personality',
                        'values',
                        'career_success_probability',
                        'job_fit',
                        'hiring_screening_suitability',
                        'career_registry_match',
                        'cross_form_raw_score_delta',
                    ],
                ],
                'disclaimers' => $this->disclaimers(),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function sections(): array
    {
        $sections = [];
        foreach ($this->lifecycleCopy->technicalNoteSummarySections() as $section) {
            if ($section['section_key'] === '' || $section['data_status'] === '') {
                continue;
            }

            $sections[] = [
                'section_key' => $section['section_key'],
                'title' => $section['title'],
                'body' => $section['copy'],
                'data_status' => $section['data_status'],
            ];
        }

        return $sections;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function methodBoundaries(): array
    {
        return $this->lifecycleCopy->technicalNoteRuntimeContract()['method_boundaries'];
    }

    /**
     * @return list<array<string,string>>
     */
    private function disclaimers(): array
    {
        return $this->lifecycleCopy->technicalNoteRuntimeContract()['disclaimers'];
    }
}
