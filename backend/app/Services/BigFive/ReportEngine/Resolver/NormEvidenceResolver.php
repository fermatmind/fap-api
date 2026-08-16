<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class NormEvidenceResolver
{
    /**
     * @param array<string,mixed> $qualityPolicy
     * @param array<string,mixed> $registry
     * @return array<string,mixed>
     */
    public function resolve(ReportContext $context, array $qualityPolicy, array $registry): array
    {
        $metadata = $context->normMetadata();
        $copy = (array) data_get($registry, 'shared.report_policy.norm_evidence', []);
        $status = strtoupper(trim((string) ($metadata['status'] ?? $context->quality['norms_status'] ?? 'MISSING')));
        if (! in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
            $status = 'MISSING';
        }
        $sourceId = trim((string) ($metadata['source_id'] ?? ''));
        $version = trim((string) ($metadata['norms_version'] ?? ''));
        $sampleN = (int) ($metadata['sample_n'] ?? 0);
        $matchType = strtolower(trim((string) ($metadata['match_type'] ?? 'fallback')));
        if (! in_array($matchType, ['exact', 'fallback', 'global'], true)) {
            $matchType = 'fallback';
        }
        $selected = is_array($metadata['selected_group'] ?? null) ? $metadata['selected_group'] : [];
        $complete = $status !== 'MISSING' && $sourceId !== '' && $version !== '' && $sampleN > 0 && $selected !== [];
        $qualityNormMode = (string) ($qualityPolicy['norm_mode'] ?? 'unavailable');
        $comparisonAllowed = $complete && $qualityNormMode !== 'unavailable';
        $precise = $comparisonAllowed && $qualityNormMode === 'precise' && $status === 'CALIBRATED';
        $displayStatus = ! $comparisonAllowed ? 'unavailable' : ($precise ? 'calibrated' : 'provisional');

        $sources = is_array($copy['source_labels'] ?? null) ? $copy['source_labels'] : [];
        $sourceLabel = trim((string) ($sources[$sourceId] ?? ''));
        if ($sourceLabel === '') {
            $comparisonAllowed = false;
            $precise = false;
            $displayStatus = 'unavailable';
        }

        return [
            'status' => $displayStatus,
            'runtime_status' => $status,
            'comparison_allowed' => $comparisonAllowed,
            'show_precise_percentiles' => $precise,
            'is_tentative' => $comparisonAllowed && ! $precise,
            'match_type' => $matchType,
            'match_label' => (string) data_get($copy, "match_labels.{$matchType}", ''),
            'sample_label' => $sourceLabel,
            'sample_n' => $comparisonAllowed ? $sampleN : null,
            'norm_version' => $comparisonAllowed ? $version : null,
            'locale' => $comparisonAllowed ? $this->label($copy, 'locale_labels', (string) ($selected['locale'] ?? '')) : null,
            'region' => $comparisonAllowed ? $this->label($copy, 'region_labels', (string) ($selected['region'] ?? '')) : null,
            'gender' => $comparisonAllowed ? $this->label($copy, 'gender_labels', (string) ($selected['gender'] ?? '')) : null,
            'age_range' => $comparisonAllowed ? $this->ageRange($selected) : null,
            'updated_at' => $comparisonAllowed ? (string) ($metadata['published_at'] ?? '') : null,
            'percentile_explanation' => $comparisonAllowed ? (string) ($copy['percentile_explanation'] ?? '') : null,
            'unavailable_explanation' => ! $comparisonAllowed ? (string) ($copy['unavailable_explanation'] ?? '') : null,
            'status_label' => (string) data_get($copy, "status_labels.{$displayStatus}", ''),
        ];
    }

    /** @param array<string,mixed> $selected */
    private function ageRange(array $selected): ?string
    {
        $min = (int) ($selected['age_min'] ?? 0);
        $max = (int) ($selected['age_max'] ?? 0);

        return $min > 0 && $max >= $min ? "{$min}-{$max}" : null;
    }

    /** @param array<string,mixed> $copy */
    private function label(array $copy, string $group, string $value): string
    {
        return (string) data_get($copy, "{$group}.{$value}", $value);
    }
}
