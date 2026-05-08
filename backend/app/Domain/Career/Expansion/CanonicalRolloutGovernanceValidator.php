<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalRolloutGovernanceValidator
{
    public function __construct(
        private readonly CanonicalExpansionManifestValidator $manifestValidator,
        private readonly CareerCanonicalRuntimeTruthValidator $truthValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function validate(array $manifestPayload, array $truth, ?array $projection = null): array
    {
        $manifestResult = $this->manifestValidator->validate($manifestPayload);
        $truthResult = $this->truthValidator->validate($truth);
        $manifest = is_array($manifestPayload['manifest'] ?? null) ? $manifestPayload['manifest'] : $manifestPayload;
        $failures = [];

        foreach ($manifestResult['failures'] ?? [] as $failure) {
            $failures[] = $this->failure('manifest', (string) ($failure['reason'] ?? 'manifest_failure'), $failure);
        }
        foreach ($truthResult['failures'] ?? [] as $failure) {
            $failures[] = $this->failure('surface_equality', (string) ($failure['reason'] ?? 'truth_failure'), $failure);
        }

        $truthBySlugLocale = $this->truthBySlugLocale($truth);
        $rolloutState = (string) ($manifest['rollout_state'] ?? '');
        foreach ($this->manifestSlugs($manifest) as $slug) {
            foreach ($this->manifestLocales($manifest) as $locale) {
                $truthItem = $truthBySlugLocale[$slug.'|'.$locale] ?? null;
                if ($truthItem === null) {
                    $failures[] = $this->failure('runtime_truth', 'manifest_row_missing_from_truth', [
                        'slug' => $slug,
                        'locale' => $locale,
                    ]);

                    continue;
                }

                if ($rolloutState === CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED
                    && (bool) ($truthItem['fully_live'] ?? false) !== true) {
                    $failures[] = $this->failure('runtime_truth', 'published_manifest_row_not_fully_live', [
                        'slug' => $slug,
                        'locale' => $locale,
                    ]);
                }
            }
        }

        if ($projection !== null) {
            foreach ($this->validateProjectionLeakage($projection) as $failure) {
                $failures[] = $failure;
            }
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'counts' => [
                'manifest_failures' => (int) data_get($manifestResult, 'counts.failures', 0),
                'truth_failures' => (int) data_get($truthResult, 'counts.failures', 0),
                'failures' => count($failures),
                'projection_only' => (int) data_get($truthResult, 'counts.projection_only', 0),
                'route_only' => (int) data_get($truthResult, 'counts.route_only', 0),
                'sitemap_only' => (int) data_get($truthResult, 'counts.sitemap_only', 0),
                'llms_only' => (int) data_get($truthResult, 'counts.llms_only', 0),
                'llms_full_only' => (int) data_get($truthResult, 'counts.llms_full_only', 0),
                'candidate_pre_route_expected_count' => (int) data_get($truthResult, 'counts.candidate_pre_route_expected_count', 0),
                'candidate_release_gate_not_applicable_count' => (int) data_get($truthResult, 'counts.candidate_release_gate_not_applicable_count', 0),
                'candidate_unexpected_route_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_route_exposure_count', 0),
                'candidate_unexpected_api_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_api_exposure_count', 0),
                'candidate_unexpected_dataset_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_dataset_exposure_count', 0),
                'candidate_unexpected_search_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_search_exposure_count', 0),
                'candidate_unexpected_sitemap_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_sitemap_exposure_count', 0),
                'candidate_unexpected_llms_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_llms_exposure_count', 0),
                'candidate_unexpected_llms_full_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_llms_full_exposure_count', 0),
                'candidate_unexpected_indexable_exposure_count' => (int) data_get($truthResult, 'counts.candidate_unexpected_indexable_exposure_count', 0),
            ],
            'candidate_semantics' => [
                'published_candidate_state' => 'expected_pre_route_inventory',
                'public_release_gate_route_validation' => 'not_applicable_before_promotion',
                'failure_condition' => 'published_candidate_visible_on_any_public_runtime_surface',
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function truthBySlugLocale(array $truth): array
    {
        $lookup = [];
        $items = is_array($truth['items'] ?? null) ? $truth['items'] : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $locale = strtolower(trim((string) ($item['locale'] ?? '')));
            if ($slug !== '' && $locale !== '') {
                $lookup[$slug.'|'.$locale] = $item;
            }
        }

        return $lookup;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function manifestSlugs(array $manifest): array
    {
        $slugs = is_array($manifest['slugs'] ?? null) ? $manifest['slugs'] : [];

        return array_values(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            $slugs,
        ));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function manifestLocales(array $manifest): array
    {
        $locales = is_array($manifest['locales'] ?? null) ? $manifest['locales'] : [];

        return array_values(array_map(
            static fn (mixed $locale): string => strtolower(trim((string) $locale)),
            $locales,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateProjectionLeakage(array $projection): array
    {
        $failures = [];
        $items = is_array($projection['items'] ?? null) ? $projection['items'] : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $type = (string) ($item['public_resolution_type'] ?? '');
            $visible = (bool) ($item['dataset_visible'] ?? false)
                || (bool) ($item['search_visible'] ?? false)
                || ($item['detail_route_enabled'] ?? false) === true
                || (bool) ($item['sitemap_live'] ?? false)
                || (bool) ($item['llms_live'] ?? false)
                || (bool) ($item['llms_full_live'] ?? false);

            if (! $visible) {
                continue;
            }

            if ($slug === 'software-developers') {
                $failures[] = $this->failure('projection', 'software_leakage', ['slug' => $slug]);
            }
            if (str_starts_with($slug, 'cn-') || $type === CareerPublicResolutionTypeMatrix::PUBLIC_CN_PROXY_PAGE) {
                $failures[] = $this->failure('projection', 'cn_leakage', ['slug' => $slug, 'public_resolution_type' => $type]);
            }
            if ($type === CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB) {
                $failures[] = $this->failure('projection', 'family_leakage', ['slug' => $slug, 'public_resolution_type' => $type]);
            }
            if ($type !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB
                && ($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $failures[] = $this->failure('projection', 'non_canonical_published_leakage', [
                    'slug' => $slug,
                    'public_resolution_type' => $type,
                ]);
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function failure(string $surface, string $reason, array $context = []): array
    {
        return [
            'surface' => $surface,
            'reason' => $reason,
            'context' => $context,
        ];
    }
}
