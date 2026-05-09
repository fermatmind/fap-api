<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalPostPromotionReleaseGateValidator
{
    /**
     * @return list<array<string, mixed>>
     */
    public function validate(array $manifestPayload, array $truth, ?array $projection = null): array
    {
        $manifest = $this->manifest($manifestPayload);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest, CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED, false);
        $truthItems = $this->items($truth);

        $failures = $this->validateManifest($manifest, $transaction);

        $expectedRows = $transaction->expectedLocaleRows();
        $states = [];

        foreach ($expectedRows as $expectedRow) {
            $item = $this->itemFor($truthItems, $expectedRow['slug'], $expectedRow['locale']);
            if ($item === null) {
                $failures[] = $this->failure('post_promotion_truth_row_missing', $expectedRow['slug'], $expectedRow['locale']);

                continue;
            }

            $state = (string) ($item['projection_state'] ?? '');
            $states[$state] = true;
            if ($state !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $failures[] = $this->failure('post_promotion_state_not_published', $this->slug($item), $this->locale($item), [
                    'state' => $state,
                ]);
            }

            foreach (CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS as $field) {
                if (! (bool) ($item[$field] ?? false)) {
                    $failures[] = $this->failure('post_promotion_'.$field.'_missing', $this->slug($item), $this->locale($item));
                }
            }

            if (($item['public_resolution_type'] ?? null) !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
                $failures[] = $this->failure('post_promotion_non_canonical_public_type', $this->slug($item), $this->locale($item), [
                    'public_resolution_type' => $item['public_resolution_type'] ?? null,
                ]);
            }
        }

        if (count($states) > 1) {
            $failures[] = $this->failure('partial_promotion_detected', null, null, [
                'states' => array_keys($states),
            ]);
        }

        if ($projection !== null) {
            $failures = array_merge($failures, $this->validateProjection($transaction, $projection));
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'counts' => [
                'expected_locale_rows' => count($expectedRows),
                'expected_published_rows' => count($expectedRows),
                'failures' => count($failures),
            ],
            'required_fields' => CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS,
            'release_gate_requirements' => [
                'route' => true,
                'indexing' => true,
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateManifest(array $manifest, CanonicalPromotionTransaction $transaction): array
    {
        $failures = [];

        if ($transaction->batchId === '') {
            $failures[] = $this->failure('missing_batch_id');
        }

        if ($transaction->slugs === []) {
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

        if ($transaction->rollbackGroup !== $transaction->slugs) {
            $failures[] = $this->failure('partial_promotion_rejected', null, null, [
                'slugs' => $transaction->slugs,
                'rollback_group' => $transaction->rollbackGroup,
            ]);
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateProjection(CanonicalPromotionTransaction $transaction, array $projection): array
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

            if (($item['public_resolution_type'] ?? null) !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
                $failures[] = $this->failure('post_promotion_projection_non_canonical_public_type', $expectedRow['slug'], $expectedRow['locale'], [
                    'public_resolution_type' => $item['public_resolution_type'] ?? null,
                ]);
            }
        }

        return $failures;
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
     * @param  array<string, mixed>  $context
     */
    private function failure(string $reason, ?string $slug = null, ?string $locale = null, array $context = []): array
    {
        $failure = array_filter([
            'reason' => $reason,
            'slug' => $slug,
            'locale' => $locale,
            'context' => $context,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        return $failure;
    }
}
