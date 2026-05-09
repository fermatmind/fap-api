<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalPromotionRollbackGate
{
    /**
     * @var list<string>
     */
    public const REQUIRED_POST_PROMOTION_FIELDS = [
        'route_exists',
        'final_200',
        'robots_indexable',
        'canonical_self',
        'dataset_visible',
        'search_visible',
        'sitemap_live',
        'llms_live',
        'llms_full_live',
        'release_gate_pass',
        'fully_live',
    ];

    /**
     * @return array<string, mixed>
     */
    public function validatePromotionPlan(array $manifestPayload, ?array $truth = null, ?array $projection = null): array
    {
        $manifest = $this->manifest($manifestPayload);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest);
        $failures = $this->validateManifestPreconditions($manifest, $transaction);

        if ($truth !== null) {
            $truthItems = $this->items($truth);
            foreach ($transaction->expectedLocaleRows() as $expectedRow) {
                $item = $this->itemFor($truthItems, $expectedRow['slug'], $expectedRow['locale']);
                if ($item === null) {
                    $failures[] = $this->failure('candidate_truth_row_missing', $expectedRow['slug'], $expectedRow['locale']);

                    continue;
                }

                foreach ($this->validateCandidateTruthItem($item) as $failure) {
                    $failures[] = $failure;
                }
            }
        }

        if ($projection !== null) {
            $projectionItems = $this->items($projection);
            foreach ($transaction->expectedLocaleRows() as $expectedRow) {
                $item = $this->itemFor($projectionItems, $expectedRow['slug'], $expectedRow['locale']);
                if ($item === null) {
                    $failures[] = $this->failure('candidate_projection_row_missing', $expectedRow['slug'], $expectedRow['locale']);

                    continue;
                }

                foreach ($this->validateCandidateProjectionItem($item) as $failure) {
                    $failures[] = $failure;
                }
            }
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'candidate_pre_route_semantics' => 'expected_pre_route',
            'candidate_public_exposure_failure_condition' => 'published_candidate_visible_on_any_public_runtime_surface',
            'counts' => [
                'candidate_slugs' => count($transaction->slugs),
                'candidate_locale_rows' => count($transaction->expectedLocaleRows()),
                'failures' => count($failures),
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatePostPromotion(array $manifestPayload, array $truth, ?array $projection = null): array
    {
        $manifest = $this->manifest($manifestPayload);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest, CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED, false);
        $truthItems = $this->items($truth);
        $failures = $this->validatePostPromotionManifest($manifest, $transaction);
        $states = [];

        foreach ($transaction->expectedLocaleRows() as $expectedRow) {
            $item = $this->itemFor($truthItems, $expectedRow['slug'], $expectedRow['locale']);
            if ($item === null) {
                $failures[] = $this->failure('post_promotion_truth_row_missing', $expectedRow['slug'], $expectedRow['locale']);

                continue;
            }

            $state = (string) ($item['projection_state'] ?? '');
            $states[$state] = true;
            if ($state !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $failures[] = $this->failure('post_promotion_state_not_published', $this->slug($item), $this->locale($item), ['state' => $state]);
            }

            foreach (self::REQUIRED_POST_PROMOTION_FIELDS as $field) {
                if (! (bool) ($item[$field] ?? false)) {
                    $failures[] = $this->failure('post_promotion_'.$field.'_missing', $this->slug($item), $this->locale($item));
                }
            }
        }

        if (count($states) > 1) {
            $failures[] = $this->failure('partial_promotion_detected', null, null, ['states' => array_keys($states)]);
        }

        if ($projection !== null) {
            foreach ($this->validatePostPromotionProjection($transaction, $projection) as $failure) {
                $failures[] = $failure;
            }
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'required_fields' => self::REQUIRED_POST_PROMOTION_FIELDS,
            'counts' => [
                'expected_locale_rows' => count($transaction->expectedLocaleRows()),
                'failures' => count($failures),
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    public function rollback(array $manifestPayload, string $strategy, array $failures): CanonicalPromotionRollbackResultDTO
    {
        $manifest = $this->manifest($manifestPayload);
        $strategy = strtolower(trim($strategy));
        $targetState = $strategy === CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED || $strategy === 'quarantine'
            ? CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED
            : CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE;

        $updatedManifest = $manifest;
        $updatedManifest['rollout_state'] = $targetState;
        $updatedManifest['projection_state'] = $this->projectionStateForRolloutState($targetState);

        return new CanonicalPromotionRollbackResultDTO(
            status: $targetState === CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED ? 'quarantined' : 'rolled_back',
            strategy: $targetState === CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED ? 'quarantine' : 'rollback',
            targetState: $targetState,
            rollbackGroup: $this->strings($manifest['rollback_group'] ?? []),
            updatedManifest: $updatedManifest,
            failures: $failures,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateManifestPreconditions(
        array $manifest,
        CanonicalPromotionTransaction $transaction,
        string $targetState = CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
    ): array {
        $failures = [];
        $rollbackGroup = $transaction->rollbackGroup;
        $slugs = $transaction->slugs;

        if ($transaction->batchId === '') {
            $failures[] = $this->failure('missing_batch_id');
        }
        if ($slugs === []) {
            $failures[] = $this->failure('missing_candidate_slugs');
        }
        if ($transaction->locales === []) {
            $failures[] = $this->failure('missing_candidate_locales');
        }
        if ($transaction->currentState !== CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE) {
            $failures[] = $this->failure('promotion_requires_published_candidate_state', null, null, ['current_state' => $transaction->currentState]);
        }
        if ($targetState !== CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED) {
            $failures[] = $this->failure('promotion_target_must_be_published', null, null, ['target_state' => $targetState]);
        }
        if (($manifest['projection_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE) {
            $failures[] = $this->failure('promotion_requires_candidate_projection_state', null, null, [
                'projection_state' => $manifest['projection_state'] ?? null,
            ]);
        }
        if ($rollbackGroup !== $slugs) {
            $failures[] = $this->failure('partial_promotion_rejected', null, null, [
                'slugs' => $slugs,
                'rollback_group' => $rollbackGroup,
            ]);
        }

        foreach ($slugs as $slug) {
            if ($slug === 'software-developers') {
                $failures[] = $this->failure('software_developers_cannot_be_promoted', $slug);
            }
            if (str_starts_with($slug, 'cn-')) {
                $failures[] = $this->failure('cn_proxy_cannot_be_promoted', $slug);
            }
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validatePostPromotionManifest(array $manifest, CanonicalPromotionTransaction $transaction): array
    {
        $failures = [];
        $rollbackGroup = $transaction->rollbackGroup;
        $slugs = $transaction->slugs;

        if ($transaction->batchId === '') {
            $failures[] = $this->failure('missing_batch_id');
        }
        if ($slugs === []) {
            $failures[] = $this->failure('missing_candidate_slugs');
        }
        if ($transaction->locales === []) {
            $failures[] = $this->failure('missing_candidate_locales');
        }
        if (($manifest['rollout_state'] ?? null) !== CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED) {
            $failures[] = $this->failure('post_promotion_manifest_not_published', null, null, [
                'rollout_state' => $manifest['rollout_state'] ?? null,
            ]);
        }
        if (($manifest['projection_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
            $failures[] = $this->failure('post_promotion_manifest_projection_not_published', null, null, [
                'projection_state' => $manifest['projection_state'] ?? null,
            ]);
        }
        if ($rollbackGroup !== $slugs) {
            $failures[] = $this->failure('partial_promotion_rejected', null, null, [
                'slugs' => $slugs,
                'rollback_group' => $rollbackGroup,
            ]);
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateCandidateTruthItem(array $item): array
    {
        $failures = [];
        $slug = $this->slug($item);
        $locale = $this->locale($item);

        if (($item['public_resolution_type'] ?? null) !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
            $failures[] = $this->failure('candidate_must_be_public_canonical_job', $slug, $locale, [
                'public_resolution_type' => $item['public_resolution_type'] ?? null,
            ]);
        }
        if (($item['projection_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE) {
            $failures[] = $this->failure('candidate_truth_state_mismatch', $slug, $locale, ['state' => $item['projection_state'] ?? null]);
        }
        if (! (bool) ($item['candidate_pre_route_expected'] ?? false)) {
            $failures[] = $this->failure('candidate_pre_route_not_expected', $slug, $locale);
        }

        foreach ($this->candidateExposureFields() as $field => $reason) {
            if ((bool) ($item[$field] ?? false)) {
                $failures[] = $this->failure($reason, $slug, $locale);
            }
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateCandidateProjectionItem(array $item): array
    {
        $failures = [];
        $slug = $this->slug($item);
        $locale = $this->locale($item);

        if (($item['public_resolution_type'] ?? null) !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
            $failures[] = $this->failure('candidate_projection_must_be_public_canonical_job', $slug, $locale, [
                'public_resolution_type' => $item['public_resolution_type'] ?? null,
            ]);
        }
        if (($item['runtime_publish_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE) {
            $failures[] = $this->failure('candidate_projection_state_mismatch', $slug, $locale, [
                'runtime_publish_state' => $item['runtime_publish_state'] ?? null,
            ]);
        }
        if (($item['detail_route_enabled'] ?? false) === true) {
            $failures[] = $this->failure('candidate_unexpected_route_exposure', $slug, $locale);
            $failures[] = $this->failure('candidate_unexpected_api_exposure', $slug, $locale);
        }
        foreach (['dataset_visible', 'search_visible', 'sitemap_live', 'llms_live', 'llms_full_live'] as $field) {
            if ((bool) ($item[$field] ?? false)) {
                $failures[] = $this->failure('candidate_unexpected_'.$field.'_exposure', $slug, $locale);
            }
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validatePostPromotionProjection(CanonicalPromotionTransaction $transaction, array $projection): array
    {
        $failures = [];
        $projectionItems = $this->items($projection);

        foreach ($transaction->expectedLocaleRows() as $expectedRow) {
            $item = $this->itemFor($projectionItems, $expectedRow['slug'], $expectedRow['locale']);
            if ($item === null) {
                $failures[] = $this->failure('post_promotion_projection_row_missing', $expectedRow['slug'], $expectedRow['locale']);

                continue;
            }

            if (($item['runtime_publish_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $failures[] = $this->failure('post_promotion_projection_state_not_published', $expectedRow['slug'], $expectedRow['locale'], [
                    'runtime_publish_state' => $item['runtime_publish_state'] ?? null,
                ]);
            }
            foreach (['dataset_visible', 'search_visible', 'sitemap_live', 'llms_live', 'llms_full_live'] as $field) {
                if (! (bool) ($item[$field] ?? false)) {
                    $failures[] = $this->failure('post_promotion_projection_'.$field.'_missing', $expectedRow['slug'], $expectedRow['locale']);
                }
            }
            if (($item['detail_route_enabled'] ?? false) !== true) {
                $failures[] = $this->failure('post_promotion_projection_detail_route_missing', $expectedRow['slug'], $expectedRow['locale']);
            }
        }

        return $failures;
    }

    /**
     * @return array<string, string>
     */
    private function candidateExposureFields(): array
    {
        return [
            'route_exists' => 'candidate_unexpected_route_exposure',
            'final_200' => 'candidate_unexpected_api_exposure',
            'dataset_visible' => 'candidate_unexpected_dataset_exposure',
            'search_visible' => 'candidate_unexpected_search_exposure',
            'sitemap_live' => 'candidate_unexpected_sitemap_exposure',
            'llms_live' => 'candidate_unexpected_llms_exposure',
            'llms_full_live' => 'candidate_unexpected_llms_full_exposure',
            'robots_indexable' => 'candidate_unexpected_indexable_exposure',
            'release_gate_pass' => 'candidate_unexpected_release_gate_pass',
            'fully_live' => 'candidate_unexpected_fully_live_exposure',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(array $manifestPayload): array
    {
        $manifest = $manifestPayload['manifest'] ?? $manifestPayload;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        $items = $payload['items'] ?? $payload;

        return is_array($items)
            ? array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)))
            : [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemFor(array $items, string $slug, string $locale): ?array
    {
        foreach ($items as $item) {
            if ($this->slug($item) === $slug && $this->locale($item) === $locale) {
                return $item;
            }
        }

        return null;
    }

    private function slug(array $item): string
    {
        return strtolower(trim((string) ($item['slug'] ?? '')));
    }

    private function locale(array $item): string
    {
        return strtolower(trim((string) ($item['locale'] ?? '')));
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = strtolower(trim((string) $item));
            if ($string !== '') {
                $strings[$string] = $string;
            }
        }

        ksort($strings);

        return array_values($strings);
    }

    private function projectionStateForRolloutState(string $targetState): string
    {
        return match ($targetState) {
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED => CareerRuntimePublishProjectionService::STATE_QUARANTINED,
            CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED => CareerRuntimePublishProjectionService::STATE_BLOCKED,
            default => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
        };
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function failure(string $reason, ?string $slug = null, ?string $locale = null, ?array $context = null): array
    {
        return array_filter([
            'reason' => $reason,
            'slug' => $slug,
            'locale' => $locale,
            'context' => $context,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
