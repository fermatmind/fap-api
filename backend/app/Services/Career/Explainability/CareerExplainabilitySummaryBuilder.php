<?php

declare(strict_types=1);

namespace App\Services\Career\Explainability;

use App\DTO\Career\CareerExplainabilitySummary;
use App\DTO\Career\CareerJobDetailBundle;
use App\DTO\Career\CareerRecommendationDetailBundle;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;

final class CareerExplainabilitySummaryBuilder
{
    public function __construct(
        private readonly CareerJobDetailBundleBuilder $jobDetailBundleBuilder,
        private readonly CareerRecommendationDetailBundleBuilder $recommendationDetailBundleBuilder,
    ) {}

    public function buildForJobSlug(string $slug): ?CareerExplainabilitySummary
    {
        $bundle = $this->jobDetailBundleBuilder->buildBySlug($slug);

        if (! $bundle instanceof CareerJobDetailBundle) {
            return null;
        }

        return new CareerExplainabilitySummary(
            subjectKind: 'job',
            subjectIdentity: [
                'occupation_uuid' => $bundle->identity['occupation_uuid'] ?? null,
                'canonical_slug' => $bundle->identity['canonical_slug'] ?? null,
                'canonical_title_en' => $bundle->titles['canonical_en'] ?? null,
            ],
            scoreBundle: $this->normalizeScoreBundle($bundle->scoreBundle),
            warnings: $this->normalizeArray($bundle->warnings),
            claimPermissions: $this->normalizeArray($bundle->claimPermissions),
            integritySummary: $this->normalizeArray($bundle->integritySummary),
        );
    }

    public function buildForRecommendationType(string $type): ?CareerExplainabilitySummary
    {
        $bundle = $this->recommendationDetailBundleBuilder->buildByType($type);

        if (! $bundle instanceof CareerRecommendationDetailBundle) {
            return null;
        }

        $subjectMeta = $this->normalizeArray($bundle->recommendationSubjectMeta);

        return new CareerExplainabilitySummary(
            subjectKind: 'recommendation',
            subjectIdentity: [
                'public_route_slug' => $subjectMeta['public_route_slug'] ?? null,
                'type' => $subjectMeta['type_code'] ?? null,
                'canonical_type_code' => $subjectMeta['canonical_type_code'] ?? null,
                'display_title' => $subjectMeta['display_title'] ?? null,
                'occupation_uuid' => $bundle->identity['occupation_uuid'] ?? null,
                'canonical_slug' => $bundle->identity['canonical_slug'] ?? null,
                'canonical_title_en' => $bundle->identity['canonical_title_en'] ?? null,
            ],
            scoreBundle: $this->normalizeScoreBundle($bundle->scoreBundle),
            warnings: $this->normalizeArray($bundle->warnings),
            claimPermissions: $this->normalizeArray($bundle->claimPermissions),
            integritySummary: $this->normalizeArray($bundle->integritySummary),
        );
    }

    /**
     * @param  array<string, mixed>  $scoreBundle
     * @return array<string, array<string, mixed>>
     */
    private function normalizeScoreBundle(array $scoreBundle): array
    {
        $normalized = [];

        foreach (['fit_score', 'strain_score', 'ai_survival_score', 'mobility_score', 'confidence_score'] as $dimension) {
            $score = $scoreBundle[$dimension] ?? null;
            if (! is_array($score)) {
                continue;
            }

            $normalized[$dimension] = [
                'value' => (int) ($score['value'] ?? 0),
                'integrity_state' => is_scalar($score['integrity_state'] ?? null) ? (string) $score['integrity_state'] : null,
                'critical_missing_fields' => array_values(array_filter(
                    is_array($score['critical_missing_fields'] ?? null) ? $score['critical_missing_fields'] : [],
                    static fn (mixed $field): bool => is_string($field) && $field !== '',
                )),
                'confidence_cap' => (int) ($score['confidence_cap'] ?? 0),
                'formula_version' => is_scalar($score['formula_ref'] ?? null) ? (string) $score['formula_ref'] : null,
                'components' => $this->normalizeArray($score['component_breakdown'] ?? []),
                'penalties' => array_values(
                    array_filter(
                        is_array($score['penalties'] ?? null) ? $score['penalties'] : [],
                        static fn (mixed $penalty): bool => is_array($penalty),
                    )
                ),
                'degradation_factor' => (float) ($score['degradation_factor'] ?? 0.0),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
