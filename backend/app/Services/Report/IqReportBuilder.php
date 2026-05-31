<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;

final class IqReportBuilder
{
    private const CANONICAL_SCALE_CODE = 'IQ_INTELLIGENCE_QUOTIENT';

    private const DEFAULT_LOCALE = 'zh-CN';

    /**
     * @var array<string,array{key:string}>
     */
    private const DIMENSIONS = [
        'VSI' => ['key' => 'visual_spatial_insight'],
        'VSPR' => ['key' => 'visual_spatial_pattern_reasoning'],
        'NPR' => ['key' => 'numerical_pattern_reasoning'],
    ];

    /**
     * @var array<string,mixed>
     */
    private const LABEL_CATALOG = [
        'schema_version' => 'iq.locale_labels.v1',
        'scale_code' => self::CANONICAL_SCALE_CODE,
        'asset_status' => 'backend_report_builder_label_catalog',
        'default_locale' => self::DEFAULT_LOCALE,
        'fallback_policy' => [
            'en' => 'missing_label_returns_null_no_zh_fallback',
            'zh-CN' => 'backend_catalog_authority',
        ],
        'dimensions' => [
            'visual_spatial_insight' => [
                'dimension_code' => 'VSI',
                'labels' => [
                    'zh-CN' => '视觉空间洞察',
                    'en' => 'Visual-spatial insight',
                ],
            ],
            'visual_spatial_pattern_reasoning' => [
                'dimension_code' => 'VSPR',
                'labels' => [
                    'zh-CN' => '视觉空间模式推理',
                    'en' => 'Visual-spatial pattern reasoning',
                ],
            ],
            'numerical_pattern_reasoning' => [
                'dimension_code' => 'NPR',
                'aliases' => [
                    'numeric_pattern_reasoning',
                ],
                'labels' => [
                    'zh-CN' => '数字规律推理',
                    'en' => 'Numeric pattern reasoning',
                ],
            ],
        ],
        'iq_pro' => [
            'pdf_payload' => [
                'labels' => [
                    'zh-CN' => 'IQ 报告 PDF',
                    'en' => 'IQ report PDF',
                ],
                'description' => [
                    'zh-CN' => '在线 IQ 估测报告 PDF；当前仅定义合同，尚未生成正式文件。',
                    'en' => 'Online IQ estimate report PDF; contract-defined only until formal file generation is implemented.',
                ],
            ],
            'certificate_payload' => [
                'labels' => [
                    'zh-CN' => 'IQ 结果凭证',
                    'en' => 'IQ result certificate',
                ],
                'description' => [
                    'zh-CN' => '在线 IQ 估测结果凭证；当前仅定义合同，尚未生成正式文件。',
                    'en' => 'Online IQ estimate result certificate; contract-defined only until formal file generation is implemented.',
                ],
            ],
        ],
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
        $locale = $this->normalizeLocale((string) ($ctx['locale'] ?? $attempt->locale ?? self::DEFAULT_LOCALE));
        $summary = $this->buildSummary($status, $score);
        $dimensions = $this->buildDimensions($score, $locale);
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
            'locale' => $locale,
            'label_catalog' => [
                'schema_version' => (string) (self::LABEL_CATALOG['schema_version'] ?? ''),
                'fallback_policy' => $locale === 'en'
                    ? 'missing_label_returns_null_no_zh_fallback'
                    : 'backend_catalog_authority',
            ],
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
                    'label' => $this->iqProLabel('pdf_payload', $locale),
                    'description' => $this->iqProDescription('pdf_payload', $locale),
                    'scale_code' => self::CANONICAL_SCALE_CODE,
                    'attempt_id' => (string) ($attempt->id ?? ''),
                ],
                'certificate_payload' => [
                    'status' => 'contract_defined_not_implemented',
                    'label' => $this->iqProLabel('certificate_payload', $locale),
                    'description' => $this->iqProDescription('certificate_payload', $locale),
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
        $hasNormAuthority = $this->hasNormAuthority($norms);

        return [
            'raw_score' => $scored ? $this->floatOrNull($score['raw_score'] ?? null) : null,
            'iq_estimate' => $scored && $hasNormAuthority ? $this->floatOrNull($norms['iq_estimate'] ?? null) : null,
            'percentile' => $scored && $hasNormAuthority ? $this->floatOrNull($norms['percentile'] ?? null) : null,
            'confidence_interval' => $scored && $hasNormAuthority && is_array($norms['confidence_interval'] ?? null)
                ? $norms['confidence_interval']
                : null,
            'norms_status' => $this->stringOrNull($norms['status'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $norms
     */
    private function hasNormAuthority(array $norms): bool
    {
        $status = strtolower(trim((string) ($norms['status'] ?? '')));

        return in_array($status, [
            'available',
            'calibrated',
            'norm_table_available',
            'production_normed',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $score
     * @return array<string,array<string,mixed>>
     */
    private function buildDimensions(array $score, string $locale): array
    {
        $rawDimensions = is_array($score['dimension_scores'] ?? null) ? $score['dimension_scores'] : [];
        $dimensions = [];

        foreach (self::DIMENSIONS as $code => $meta) {
            $row = is_array($rawDimensions[$code] ?? null) ? $rawDimensions[$code] : [];
            $dimensions[$meta['key']] = [
                'dimension_code' => $code,
                'dimension_name' => $this->dimensionLabel($meta['key'], $locale),
                'raw_score' => $this->floatOrNull($row['raw_score'] ?? null),
                'percent_correct' => $this->floatOrNull($row['percent_correct'] ?? null),
                'item_count' => $this->intOrNull($row['item_count'] ?? null),
                'answered_count' => $this->intOrNull($row['answered_count'] ?? null),
                'correct_count' => $this->intOrNull($row['correct_count'] ?? null),
            ];
        }

        return $dimensions;
    }

    private function dimensionLabel(string $dimensionKey, string $locale): ?string
    {
        return $this->localizedLabel("dimensions.{$dimensionKey}.labels", $locale);
    }

    private function iqProLabel(string $payloadKey, string $locale): ?string
    {
        return $this->localizedLabel("iq_pro.{$payloadKey}.labels", $locale);
    }

    private function iqProDescription(string $payloadKey, string $locale): ?string
    {
        return $this->localizedLabel("iq_pro.{$payloadKey}.description", $locale);
    }

    private function localizedLabel(string $basePath, string $locale): ?string
    {
        $labels = data_get(self::LABEL_CATALOG, $basePath);
        if (! is_array($labels)) {
            return null;
        }

        $value = $labels[$locale] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
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

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);

        if (str_starts_with(strtolower($normalized), 'en')) {
            return 'en';
        }

        if ($normalized === 'zh' || $normalized === 'zh_CN' || $normalized === 'zh-CN') {
            return 'zh-CN';
        }

        return self::DEFAULT_LOCALE;
    }
}
