<?php

declare(strict_types=1);

namespace App\Services\Comparative;

final class VersionedComparativeNormingLayerService
{
    public const VERSION = 'comparative.norming.v1';

    /**
     * @var list<string>
     */
    private const TRUTH_GUARD_FIELDS = [
        'type_code',
        'identity',
        'variant_keys',
        'scene_fingerprint',
        'working_life_v1',
        'cross_assessment_v1',
        'user_state',
        'orchestration',
        'continuity',
        'trait_vector',
        'trait_bands',
        'dominant_traits',
        'action_plan_summary',
        'controlled_narrative_v1',
        'cultural_calibration_v1',
    ];

    /**
     * @param  array<string,mixed>  $personalization
     * @param  array<string,mixed>  $reportPayload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function buildForMbti(array $personalization, array $reportPayload, array $context = []): array
    {
        $locale = $this->normalizeLocale((string) ($context['locale'] ?? data_get($personalization, 'locale', 'zh-CN')));
        $region = trim((string) ($context['region'] ?? ''));
        if ($region === '') {
            $region = str_starts_with(strtolower($locale), 'zh') ? 'CN_MAINLAND' : 'US';
        }

        $consentGranted = (bool) data_get($personalization, 'privacy_contract_v1.consent_scope.norming_anonymized_only', false);
        $norms = is_array($reportPayload['norms'] ?? null) ? $reportPayload['norms'] : [];
        $metrics = is_array($norms['metrics'] ?? null) ? $norms['metrics'] : [];

        $leadMetricKey = $this->resolveLeadMbtiMetricKey($personalization, $metrics);
        $leadMetric = $leadMetricKey !== '' && is_array($metrics[$leadMetricKey] ?? null)
            ? $metrics[$leadMetricKey]
            : [];
        $percentile = $this->resolveMbtiPercentile($leadMetricKey, $leadMetric, $locale);
        $normingSource = $metrics !== [] ? 'anonymized_norms_table' : '';
        if ($percentile === []) {
            $percentile = $this->resolveMbtiProxyPercentile($personalization, $locale);
            if ($percentile !== []) {
                $normingSource = 'axis_distribution_proxy';
            }
        }

        $normingVersion = trim((string) ($norms['version_id'] ?? ($context['norm_version'] ?? '')));
        $normingScope = sprintf('%s.%s.anonymized_population', $region, $locale);

        $sameTypeContrast = $this->buildMbtiSameTypeContrast($personalization, $locale);
        $cohortRelativePosition = $this->buildCohortRelativePosition($percentile, $locale, 'MBTI');

        return $this->buildContract(
            enabled: $consentGranted && $percentile !== [],
            percentile: $percentile,
            cohortRelativePosition: $cohortRelativePosition,
            sameTypeContrast: $sameTypeContrast,
            normingVersion: $normingVersion,
            normingScope: $normingScope,
            normingSource: $normingSource
        );
    }

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function buildForBigFive(array $projection, array $scoreResult, array $context = []): array
    {
        $locale = $this->normalizeLocale((string) ($context['locale'] ?? 'zh-CN'));
        $region = trim((string) ($context['region'] ?? ''));
        if ($region === '') {
            $region = str_starts_with(strtolower($locale), 'zh') ? 'CN_MAINLAND' : 'US';
        }

        $dominantTraits = is_array($projection['dominant_traits'] ?? null) ? $projection['dominant_traits'] : [];
        $leadTrait = is_array($dominantTraits[0] ?? null) ? $dominantTraits[0] : [];
        $percentile = $this->resolveBigFivePercentile($leadTrait);
        $sameTypeContrast = $this->buildBigFiveSameTypeContrast($projection, $leadTrait, $locale);
        $cohortRelativePosition = $this->buildCohortRelativePosition($percentile, $locale, 'BIG5_OCEAN');

        $norms = is_array($scoreResult['norms'] ?? null) ? $scoreResult['norms'] : [];
        $normingVersion = trim((string) ($norms['norms_version'] ?? ($context['norm_version'] ?? '')));
        $normingScope = trim((string) ($norms['group_id'] ?? ''));
        if ($normingScope === '') {
            $normingScope = sprintf('%s.%s.big5_population', $region, $locale);
        }
        $normingSource = trim((string) ($norms['source_id'] ?? ''));
        if ($normingSource === '') {
            $normingSource = 'scale_norms';
        }

        return $this->buildContract(
            enabled: $percentile !== [],
            percentile: $percentile,
            cohortRelativePosition: $cohortRelativePosition,
            sameTypeContrast: $sameTypeContrast,
            normingVersion: $normingVersion,
            normingScope: $normingScope,
            normingSource: $normingSource
        );
    }

    /**
     * @param  array<string,mixed>  $percentile
     * @param  array<string,mixed>  $cohortRelativePosition
     * @param  array<string,mixed>  $sameTypeContrast
     * @return array<string,mixed>
     */
    private function buildContract(
        bool $enabled,
        array $percentile,
        array $cohortRelativePosition,
        array $sameTypeContrast,
        string $normingVersion,
        string $normingScope,
        string $normingSource,
    ): array {
        $fingerprint = hash('sha256', json_encode([
            'version' => self::VERSION,
            'enabled' => $enabled,
            'percentile' => $percentile,
            'cohort_relative_position' => $cohortRelativePosition,
            'same_type_contrast' => $sameTypeContrast,
            'norming_version' => $normingVersion,
            'norming_scope' => $normingScope,
            'norming_source' => $normingSource,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'version' => self::VERSION,
            'comparative_contract_version' => self::VERSION,
            'enabled' => $enabled,
            'percentile' => $percentile,
            'cohort_relative_position' => $cohortRelativePosition,
            'same_type_contrast' => $sameTypeContrast,
            'norming_version' => $normingVersion,
            'norming_scope' => $normingScope,
            'norming_source' => $normingSource,
            'comparative_fingerprint' => $fingerprint,
            'truth_guard_fields' => self::TRUTH_GUARD_FIELDS,
        ];
    }

    /**
     * @param  array<string,mixed>  $personalization
     * @param  array<string,mixed>  $metrics
     */
    private function resolveLeadMbtiMetricKey(array $personalization, array $metrics): string
    {
        $dominantAxes = is_array($personalization['dominant_axes'] ?? null) ? $personalization['dominant_axes'] : [];
        foreach ($dominantAxes as $axis) {
            if (! is_array($axis)) {
                continue;
            }

            $code = strtoupper(trim((string) ($axis['axis'] ?? '')));
            if ($code !== '' && is_array($metrics[$code] ?? null)) {
                return $code;
            }
        }

        $bestMetric = '';
        $bestDelta = -1;
        foreach ($metrics as $metricKey => $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $delta = abs((int) ($metric['score_int'] ?? 50) - 50);
            if ($delta > $bestDelta) {
                $bestMetric = strtoupper(trim((string) $metricKey));
                $bestDelta = $delta;
            }
        }

        return $bestMetric;
    }

    /**
     * @param  array<string,mixed>  $metric
     * @return array<string,mixed>
     */
    private function resolveMbtiPercentile(string $metricKey, array $metric, string $locale): array
    {
        if ($metricKey === '' || $metric === []) {
            return [];
        }

        $value = (int) ($metric['over_percent'] ?? 0);
        if ($value <= 0) {
            return [];
        }

        return [
            'metric_key' => $metricKey,
            'metric_label' => $this->mbtiMetricLabel($metricKey, $locale),
            'value' => $value,
        ];
    }

    /**
     * @param  array<string,mixed>  $personalization
     * @return array<string,mixed>
     */
    private function resolveMbtiProxyPercentile(array $personalization, string $locale): array
    {
        $axis = strtoupper(trim((string) data_get($personalization, 'dominant_axes.0.axis', '')));
        $value = (int) data_get($personalization, sprintf('axis_vector.%s.pct', $axis), 0);
        if ($axis === '' || $value <= 0) {
            return [];
        }

        return [
            'metric_key' => $axis,
            'metric_label' => $this->mbtiMetricLabel($axis, $locale),
            'value' => $value,
        ];
    }

    /**
     * @param  array<string,mixed>  $leadTrait
     * @return array<string,mixed>
     */
    private function resolveBigFivePercentile(array $leadTrait): array
    {
        $metricKey = strtoupper(trim((string) ($leadTrait['key'] ?? '')));
        $value = (int) ($leadTrait['percentile'] ?? 0);
        if ($metricKey === '' || $value <= 0) {
            return [];
        }

        return [
            'metric_key' => $metricKey,
            'metric_label' => trim((string) ($leadTrait['label'] ?? $metricKey)),
            'value' => $value,
        ];
    }

    /**
     * @param  array<string,mixed>  $percentile
     * @return array<string,mixed>
     */
    private function buildCohortRelativePosition(array $percentile, string $locale, string $scaleCode): array
    {
        $value = (int) ($percentile['value'] ?? 0);
        $metricLabel = trim((string) ($percentile['metric_label'] ?? ''));
        if ($value <= 0 || $metricLabel === '') {
            return [];
        }

        $key = $value >= 85
            ? 'top_quintile'
            : ($value >= 70
                ? 'upper_band'
                : ($value >= 55
                    ? 'upper_mid'
                    : ($value >= 40 ? 'middle_band' : 'lower_band')));

        if ($locale === 'zh-CN') {
            $summary = $scaleCode === 'MBTI'
                ? sprintf('在当前常模版本里，你在 %s 这一维上高于约 %d%% 的匿名样本。', $metricLabel, $value)
                : sprintf('在当前常模版本里，你的 %s 高于约 %d%% 的匿名样本。', $metricLabel, $value);
            $label = sprintf('约高于 %d%% 的同范围样本', $value);
        } else {
            $summary = $scaleCode === 'MBTI'
                ? sprintf('In the current norming set, your %s signal sits above roughly %d%% of the anonymized cohort.', $metricLabel, $value)
                : sprintf('In the current norming set, your %s sits above roughly %d%% of the anonymized cohort.', $metricLabel, $value);
            $label = sprintf('Above about %d%% of the cohort', $value);
        }

        return [
            'key' => $key,
            'label' => $label,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $personalization
     * @return array<string,mixed>
     */
    private function buildMbtiSameTypeContrast(array $personalization, string $locale): array
    {
        $typeCode = strtoupper(trim((string) ($personalization['type_code'] ?? '')));
        if ($typeCode === '') {
            return [];
        }

        $boundaryAxes = [];
        foreach ((array) ($personalization['boundary_flags'] ?? []) as $axis => $flag) {
            if ($flag === true) {
                $boundaryAxes[] = strtoupper(trim((string) $axis));
            }
        }

        $contrastAxis = strtoupper(trim((string) data_get($personalization, 'dominant_axes.0.axis', '')));
        if ($boundaryAxes !== []) {
            $axesLabel = implode(' / ', $boundaryAxes);

            return [
                'key' => 'same_type.boundary_axes',
                'label' => $locale === 'zh-CN' ? '同类中的边界型表达' : 'Boundary-leaning within the same type',
                'summary' => $locale === 'zh-CN'
                    ? sprintf('和同为 %s 的常见画像相比，你在 %s 这些轴上更靠近边界，因此切换感会更明显。', $typeCode, $axesLabel)
                    : sprintf('Compared with more prototypical %s profiles, your %s axes sit closer to the boundary, so the switching pattern is more visible.', $typeCode, $axesLabel),
                'contrast_axes' => $boundaryAxes,
            ];
        }

        if ($contrastAxis !== '') {
            $axisLabel = $this->mbtiMetricLabel($contrastAxis, $locale);

            return [
                'key' => 'same_type.dominant_axis',
                'label' => $locale === 'zh-CN' ? '同类中的主轴更集中' : 'More concentrated within the same type',
                'summary' => $locale === 'zh-CN'
                    ? sprintf('和同为 %s 的常见画像相比，你在 %s 这一轴上的倾向更集中，因此这条轴更容易决定别人首先如何感受到你。', $typeCode, $axisLabel)
                    : sprintf('Compared with other %s profiles, your %s preference reads as more concentrated, so it is more likely to shape first impressions.', $typeCode, $axisLabel),
                'contrast_axes' => [$contrastAxis],
            ];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<string,mixed>  $leadTrait
     * @return array<string,mixed>
     */
    private function buildBigFiveSameTypeContrast(array $projection, array $leadTrait, string $locale): array
    {
        $metricLabel = trim((string) ($leadTrait['label'] ?? ''));
        if ($metricLabel === '') {
            return [];
        }

        $profileKey = '';
        foreach ((array) ($projection['variant_keys'] ?? []) as $variantKey) {
            $variant = trim((string) $variantKey);
            if (str_starts_with($variant, 'profile:')) {
                $profileKey = substr($variant, strlen('profile:'));
                break;
            }
        }

        $profileLabel = $profileKey !== ''
            ? str_replace('_', ' ', $profileKey)
            : ($locale === 'zh-CN' ? '相近画像' : 'similar profiles');

        return [
            'key' => 'same_type.profile_family',
            'label' => $locale === 'zh-CN' ? '同类画像中的突出驱动' : 'Standout trait within the same profile family',
            'summary' => $locale === 'zh-CN'
                ? sprintf('在 %s 这一类画像里，%s 仍然是最突出的驱动维度。', $profileLabel, $metricLabel)
                : sprintf('Within the %s profile family, %s still stands out as the clearest driver.', $profileLabel, $metricLabel),
            'contrast_axes' => [trim((string) ($leadTrait['key'] ?? ''))],
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);
        if ($normalized === '') {
            return 'zh-CN';
        }

        if (str_starts_with(strtolower($normalized), 'zh')) {
            return 'zh-CN';
        }

        if (strtolower($normalized) === 'en') {
            return 'en-US';
        }

        return $normalized;
    }

    private function mbtiMetricLabel(string $metricKey, string $locale): string
    {
        $isZh = $locale === 'zh-CN';

        return match (strtoupper($metricKey)) {
            'EI' => $isZh ? '能量方向' : 'energy direction',
            'SN' => $isZh ? '信息偏好' : 'information preference',
            'TF' => $isZh ? '决策偏好' : 'decision style',
            'JP' => $isZh ? '生活方式' : 'lifestyle',
            'AT' => $isZh ? '身份层' : 'identity layer',
            default => strtoupper($metricKey),
        };
    }
}
