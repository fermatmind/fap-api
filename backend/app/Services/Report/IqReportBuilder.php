<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;

final class IqReportBuilder
{
    private const CANONICAL_SCALE_CODE = 'IQ_INTELLIGENCE_QUOTIENT';

    /**
     * @var array<string,array{key:string,name:string}>
     */
    private const DIMENSIONS = [
        'VSI' => ['key' => 'visual_spatial_insight', 'name' => '视觉空间洞察'],
        'VSPR' => ['key' => 'visual_spatial_pattern_reasoning', 'name' => '视觉空间模式推理'],
        'NPR' => ['key' => 'numerical_pattern_reasoning', 'name' => '数字规律推理'],
    ];

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report:array<string,mixed>}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        return [
            'ok' => true,
            'report' => $this->build($attempt, $result, $variant, $ctx),
        ];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public function build(Attempt $attempt, Result $result, string $variant = ReportAccess::VARIANT_FULL, array $ctx = []): array
    {
        $score = $this->extractScoreResult($result);
        $status = strtolower(trim((string) ($score['status'] ?? 'blocked_unscored')));
        $reasonCode = $this->stringOrNull($score['reason_code'] ?? null);
        $summary = $this->buildSummary($status, $score);
        $dimensions = $this->buildDimensions($score);
        $quality = $this->buildQuality($score);
        $stability = $this->buildStability($score, $status, $reasonCode);
        $reportAccessLevel = ReportAccess::normalizeReportAccessLevel((string) ($ctx['report_access_level'] ?? ReportAccess::REPORT_ACCESS_FULL));
        $normalizedVariant = ReportAccess::normalizeVariant($variant);
        $legacyScaleCode = strtoupper(trim((string) ($attempt->scale_code ?? $result->scale_code ?? 'IQ_RAVEN')));
        if (! $this->allowsPaidReportPayload($normalizedVariant, $reportAccessLevel)) {
            return $this->buildLockedReport(
                $attempt,
                $legacyScaleCode !== '' ? $legacyScaleCode : 'IQ_RAVEN',
                $summary,
                $reportAccessLevel,
                $normalizedVariant
            );
        }

        return [
            'schema_version' => 'iq.report.v1',
            'scale_code' => self::CANONICAL_SCALE_CODE,
            'scale_code_legacy' => $legacyScaleCode !== '' ? $legacyScaleCode : 'IQ_RAVEN',
            'attempt_id' => (string) ($attempt->id ?? ''),
            'summary' => $summary,
            'dimensions' => $dimensions,
            'quality' => $quality,
            'stability' => $stability,
            'scoring' => [
                'status' => $status,
                'reason_code' => $reasonCode,
                'scoring_mode' => $this->stringOrNull($score['scoring_mode'] ?? null),
                'bank_id' => $this->stringOrNull($score['bank_id'] ?? null),
                'answer_key_version' => $this->stringOrNull($score['answer_key_version'] ?? null),
                'norm_table_version' => $this->stringOrNull($score['norm_table_version'] ?? null),
                'scoring_engine_version' => $this->stringOrNull($score['scoring_engine_version'] ?? null),
            ],
            'access' => [
                'report_access_level' => $reportAccessLevel,
                'variant' => $normalizedVariant,
            ],
            'iq_pro' => [
                'pdf_payload' => [
                    'status' => 'contract_defined_not_implemented',
                    'scale_code' => self::CANONICAL_SCALE_CODE,
                    'attempt_id' => (string) ($attempt->id ?? ''),
                ],
                'certificate_payload' => [
                    'status' => 'contract_defined_not_implemented',
                    'scale_code' => self::CANONICAL_SCALE_CODE,
                    'attempt_id' => (string) ($attempt->id ?? ''),
                ],
            ],
            'sections' => [
                'dimensions' => $dimensions,
                'quality' => $quality,
                'stability' => $stability,
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function buildLockedReport(
        Attempt $attempt,
        string $legacyScaleCode,
        array $summary,
        string $reportAccessLevel,
        string $normalizedVariant
    ): array {
        return [
            'schema_version' => 'iq.report.v1',
            'scale_code' => self::CANONICAL_SCALE_CODE,
            'scale_code_legacy' => $legacyScaleCode,
            'attempt_id' => (string) ($attempt->id ?? ''),
            'summary' => [
                'raw_score' => null,
                'iq_estimate' => null,
                'percentile' => null,
                'confidence_interval' => null,
                'norms_status' => $this->stringOrNull($summary['norms_status'] ?? null),
            ],
            'access' => [
                'report_access_level' => $reportAccessLevel,
                'variant' => $normalizedVariant,
            ],
            'sections' => [],
            '_meta' => [
                'locked' => true,
                'redacted' => true,
                'redaction_policy' => 'iq.locked_report.v1',
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    private function allowsPaidReportPayload(string $normalizedVariant, string $reportAccessLevel): bool
    {
        return $normalizedVariant === ReportAccess::VARIANT_FULL
            && $reportAccessLevel === ReportAccess::REPORT_ACCESS_FULL;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $resultJson = $result->result_json;
        if (! is_array($resultJson)) {
            $resultJson = [];
        }

        $candidates = [
            $result->normed_json ?? null,
            $resultJson['normed_json'] ?? null,
            data_get($resultJson, 'breakdown_json.score_result'),
            data_get($resultJson, 'axis_scores_json.score_result'),
            $resultJson,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (
                array_key_exists('dimension_scores', $candidate)
                || array_key_exists('quality', $candidate)
                || array_key_exists('result_stability', $candidate)
                || array_key_exists('norms', $candidate)
                || array_key_exists('status', $candidate)
            ) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array<string,mixed>
     */
    private function buildSummary(string $status, array $score): array
    {
        $norms = is_array($score['norms'] ?? null) ? $score['norms'] : [];
        $scored = $status === 'scored';

        return [
            'raw_score' => $scored ? $this->floatOrNull($score['raw_score'] ?? null) : null,
            'iq_estimate' => $this->floatOrNull($norms['iq_estimate'] ?? null),
            'percentile' => $this->floatOrNull($norms['percentile'] ?? null),
            'confidence_interval' => is_array($norms['confidence_interval'] ?? null)
                ? $norms['confidence_interval']
                : null,
            'norms_status' => $this->stringOrNull($norms['status'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array<string,array<string,mixed>>
     */
    private function buildDimensions(array $score): array
    {
        $rawDimensions = is_array($score['dimension_scores'] ?? null) ? $score['dimension_scores'] : [];
        $dimensions = [];

        foreach (self::DIMENSIONS as $code => $meta) {
            $row = is_array($rawDimensions[$code] ?? null) ? $rawDimensions[$code] : [];
            $dimensions[$meta['key']] = [
                'dimension_code' => $code,
                'dimension_name' => $meta['name'],
                'raw_score' => $this->floatOrNull($row['raw_score'] ?? null),
                'percent_correct' => $this->floatOrNull($row['percent_correct'] ?? null),
                'item_count' => $this->intOrNull($row['item_count'] ?? null),
                'answered_count' => $this->intOrNull($row['answered_count'] ?? null),
                'correct_count' => $this->intOrNull($row['correct_count'] ?? null),
            ];
        }

        return $dimensions;
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array{level:?string,flags:list<string>}
     */
    private function buildQuality(array $score): array
    {
        $quality = is_array($score['quality'] ?? null) ? $score['quality'] : [];
        $flags = is_array($quality['flags'] ?? null) ? array_values(array_map('strval', $quality['flags'])) : [];

        return [
            'level' => $this->stringOrNull($quality['level'] ?? null),
            'flags' => $flags,
        ];
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array{status:string,reason:?string}
     */
    private function buildStability(array $score, string $status, ?string $reasonCode): array
    {
        $stability = is_array($score['result_stability'] ?? null) ? $score['result_stability'] : [];
        $stableStatus = strtolower(trim((string) ($stability['status'] ?? '')));

        if ($stableStatus === '') {
            $stableStatus = $status === 'scored' ? 'review_with_caution' : 'unstable';
        }

        return [
            'status' => $stableStatus,
            'reason' => $this->stringOrNull($stability['reason'] ?? $reasonCode),
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '' && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
