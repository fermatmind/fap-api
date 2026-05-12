<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Result;

final class RiasecPublicProjectionService
{
    private const LABELS = [
        'R' => ['en' => 'Realistic', 'zh-CN' => '现实型'],
        'I' => ['en' => 'Investigative', 'zh-CN' => '研究型'],
        'A' => ['en' => 'Artistic', 'zh-CN' => '艺术型'],
        'S' => ['en' => 'Social', 'zh-CN' => '社会型'],
        'E' => ['en' => 'Enterprising', 'zh-CN' => '企业型'],
        'C' => ['en' => 'Conventional', 'zh-CN' => '常规型'],
    ];

    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
    ) {}

    public function buildFromResult(Result $result, string $locale = 'zh-CN'): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $scores = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        if ($scores === [] && is_array($payload['scores_0_100'] ?? null)) {
            $scores = $payload['scores_0_100'];
        }

        $topCode = trim((string) ($payload['top_code'] ?? ($result->type_code ?? '')));
        $primary = trim((string) ($payload['primary_type'] ?? substr($topCode, 0, 1)));
        $secondary = trim((string) ($payload['secondary_type'] ?? substr($topCode, 1, 1)));
        $tertiary = trim((string) ($payload['tertiary_type'] ?? substr($topCode, 2, 1)));
        $formCode = $this->measurementContract->canonicalFormCode(
            (string) ($payload['form_code'] ?? data_get($payload, 'measurement_contract_v1.form.form_code', '')),
            (int) ($payload['answer_count'] ?? 0)
        );
        $measurementContract = is_array($payload['measurement_contract_v1'] ?? null)
            ? $payload['measurement_contract_v1']
            : $this->measurementContract->forFormCode($formCode, (int) ($payload['answer_count'] ?? 0));
        $comparePolicy = is_array($payload['compare_policy_v1'] ?? null)
            ? $payload['compare_policy_v1']
            : (is_array($measurementContract['compare_policy'] ?? null)
                ? $measurementContract['compare_policy']
                : $this->measurementContract->comparePolicyForFormCode($formCode, (int) ($payload['answer_count'] ?? 0)));

        return [
            'schema' => 'fap.riasec.public_projection.v1',
            'top_code' => $topCode,
            'primary_type' => $primary,
            'secondary_type' => $secondary,
            'tertiary_type' => $tertiary,
            'scores_0_100' => $this->normalizeScores($scores),
            'clarity_index' => (float) ($payload['clarity_index'] ?? 0),
            'breadth_index' => (float) ($payload['breadth_index'] ?? 0),
            'quality_grade' => (string) ($payload['quality_grade'] ?? data_get($payload, 'quality.grade', 'A')),
            'quality_flags' => array_values(array_filter(array_map('strval', (array) ($payload['quality_flags'] ?? data_get($payload, 'quality.flags', []))))),
            'dimension_labels' => $this->dimensionLabels($locale),
            'form' => [
                'form_code' => $formCode,
                'score_space_version' => (string) data_get($measurementContract, 'form.score_space_version', ''),
                'compare_compatibility_group' => (string) ($comparePolicy['compare_compatibility_group'] ?? ''),
                'cross_form_comparable' => false,
                'raw_score_delta_allowed' => false,
            ],
            'measurement_contract_v1' => $measurementContract,
            'compare_policy_v1' => $comparePolicy,
            'enhanced_breakdown' => [
                'activity' => $this->prefixedScores($payload, 'activity_'),
                'environment' => $this->prefixedScores($payload, 'env_'),
                'role' => $this->prefixedScores($payload, 'role_'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $scores
     * @return array<string,float>
     */
    private function normalizeScores(array $scores): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $dimension) {
            $out[$dimension] = round((float) ($scores[$dimension] ?? 0), 2);
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function dimensionLabels(string $locale): array
    {
        $key = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $out = [];
        foreach (self::LABELS as $dimension => $labels) {
            $out[$dimension] = $labels[$key] ?? $labels['en'];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,float>
     */
    private function prefixedScores(array $payload, string $prefix): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $dimension) {
            $key = $prefix.$dimension;
            if (array_key_exists($key, $payload)) {
                $out[$dimension] = round((float) $payload[$key], 2);
            }
        }

        return $out;
    }
}
