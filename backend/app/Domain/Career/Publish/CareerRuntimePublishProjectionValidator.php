<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;

final class CareerRuntimePublishProjectionValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(array $projection): array
    {
        $items = is_array($projection['items'] ?? null) ? $projection['items'] : [];
        $failures = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $failures[] = [
                    'index' => $index,
                    'slug' => null,
                    'reason' => 'projection_item_not_object',
                ];

                continue;
            }

            foreach ($this->validateItem($item, $index) as $failure) {
                $failures[] = $failure;
            }
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'counts' => [
                'items' => count($items),
                'failures' => count($failures),
                'published' => $this->countWhere($items, 'runtime_publish_state', CareerRuntimePublishProjectionService::STATE_PUBLISHED),
                'dataset_visible' => $this->countTrue($items, 'dataset_visible'),
                'sitemap_live' => $this->countTrue($items, 'sitemap_live'),
                'llms_live' => $this->countTrue($items, 'llms_live'),
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateItem(array $item, int $index): array
    {
        $failures = [];
        $slug = trim((string) ($item['slug'] ?? ''));
        $state = trim((string) ($item['runtime_publish_state'] ?? ''));
        $type = trim((string) ($item['public_resolution_type'] ?? ''));
        $sitemapLive = (bool) ($item['sitemap_live'] ?? false);
        $llmsLive = (bool) ($item['llms_live'] ?? false);
        $llmsFullLive = (bool) ($item['llms_full_live'] ?? false);
        $datasetVisible = (bool) ($item['dataset_visible'] ?? false);
        $searchVisible = (bool) ($item['search_visible'] ?? false);
        $detailRouteEnabled = $item['detail_route_enabled'] ?? false;
        $releaseGatePass = (bool) ($item['release_gate_pass'] ?? false);
        $robotsIndexable = (bool) ($item['robots_indexable'] ?? false);
        $canonicalSelf = (bool) ($item['canonical_self'] ?? false);

        if ($slug === '') {
            $failures[] = $this->failure($index, $slug, 'missing_slug');
        }
        if (! in_array($state, [
            CareerRuntimePublishProjectionService::STATE_BLOCKED,
            CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            CareerRuntimePublishProjectionService::STATE_QUARANTINED,
        ], true)) {
            $failures[] = $this->failure($index, $slug, 'invalid_runtime_publish_state');
        }
        if (! in_array($type, CareerPublicResolutionTypeMatrix::allowedTypes(), true)) {
            $failures[] = $this->failure($index, $slug, 'invalid_public_resolution_type');
        }
        if ($slug === 'software-developers' && ($datasetVisible || $searchVisible || $detailRouteEnabled !== false || $sitemapLive || $llmsLive || $llmsFullLive)) {
            $failures[] = $this->failure($index, $slug, 'software_developers_runtime_visibility');
        }
        if ($type !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB && ($datasetVisible || $searchVisible || $sitemapLive || $llmsLive || $llmsFullLive)) {
            $failures[] = $this->failure($index, $slug, 'non_canonical_runtime_visibility');
        }
        if (str_starts_with($slug, 'cn-') && ($datasetVisible || $searchVisible || $sitemapLive || $llmsLive || $llmsFullLive)) {
            $failures[] = $this->failure($index, $slug, 'cn_proxy_runtime_visibility');
        }
        if (($sitemapLive || $llmsLive || $llmsFullLive) && ! (
            $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED
            && $releaseGatePass
            && $robotsIndexable
            && $detailRouteEnabled === true
            && $canonicalSelf
        )) {
            $failures[] = $this->failure($index, $slug, 'seo_geo_live_without_publish_gate');
        }

        return $failures;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(int $index, string $slug, string $reason): array
    {
        return [
            'index' => $index,
            'slug' => $slug !== '' ? $slug : null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countTrue(array $items, string $field): int
    {
        return count(array_filter($items, static fn (array $item): bool => (bool) ($item[$field] ?? false)));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countWhere(array $items, string $field, string $value): int
    {
        return count(array_filter($items, static fn (array $item): bool => ($item[$field] ?? null) === $value));
    }
}
