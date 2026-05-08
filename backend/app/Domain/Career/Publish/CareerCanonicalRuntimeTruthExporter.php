<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;

final class CareerCanonicalRuntimeTruthExporter
{
    public const TRUTH_KIND = 'career_canonical_runtime_truth';

    public const TRUTH_VERSION = 'career.canonical_runtime_truth.v1';

    public const TRUTH_FILENAME = 'career-canonical-runtime-truth.json';

    public function __construct(
        private readonly CareerRuntimePublishProjectionExporter $projectionExporter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $ledgerPath = null, ?string $projectionPath = null): array
    {
        $projection = $projectionPath !== null
            ? $this->readProjectionPath($projectionPath)
            : $this->projectionExporter->build($ledgerPath);

        return $this->buildFromProjectionArray($projection);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFromProjectionArray(array $projection): array
    {
        $projectionItems = is_array($projection['items'] ?? null) ? $projection['items'] : [];
        $items = [];
        $excludedCounts = [];

        foreach ($projectionItems as $projectionItem) {
            if (! is_array($projectionItem)) {
                continue;
            }

            $type = trim((string) ($projectionItem['public_resolution_type'] ?? ''));
            if ($type !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
                $excludedCounts[$type !== '' ? $type : 'unknown'] = ($excludedCounts[$type !== '' ? $type : 'unknown'] ?? 0) + 1;

                continue;
            }

            $items[] = $this->truthItem($projectionItem);
        }

        return [
            'truth_kind' => self::TRUTH_KIND,
            'truth_version' => self::TRUTH_VERSION,
            'source_authority' => $projection['source_authority'] ?? 'CareerFullReleaseLedger',
            'source_projection_kind' => $projection['projection_kind'] ?? null,
            'source_projection_version' => $projection['projection_version'] ?? null,
            'ledger_kind' => $projection['ledger_kind'] ?? null,
            'ledger_version' => $projection['ledger_version'] ?? null,
            'scope' => $projection['scope'] ?? null,
            'counts' => $this->counts($items, $excludedCounts),
            'excluded_counts_by_public_resolution_type' => $excludedCounts,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function truthItem(array $projectionItem): array
    {
        $state = trim((string) ($projectionItem['runtime_publish_state'] ?? ''));
        $detailRouteEnabled = ($projectionItem['detail_route_enabled'] ?? false) === true;
        $releaseGatePass = (bool) ($projectionItem['release_gate_pass'] ?? false);
        $robotsIndexable = (bool) ($projectionItem['robots_indexable'] ?? false);
        $canonicalSelf = (bool) ($projectionItem['canonical_self'] ?? false);
        $datasetVisible = (bool) ($projectionItem['dataset_visible'] ?? false);
        $searchVisible = (bool) ($projectionItem['search_visible'] ?? false);
        $sitemapLive = (bool) ($projectionItem['sitemap_live'] ?? false);
        $llmsLive = (bool) ($projectionItem['llms_live'] ?? false);
        $llmsFullLive = (bool) ($projectionItem['llms_full_live'] ?? false);
        $final200 = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED
            && $detailRouteEnabled
            && $releaseGatePass;
        $fullyLive = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED
            && $final200
            && $robotsIndexable
            && $canonicalSelf
            && $datasetVisible
            && $searchVisible
            && $sitemapLive
            && $llmsLive
            && $llmsFullLive
            && $releaseGatePass;

        return [
            'slug' => strtolower(trim((string) ($projectionItem['slug'] ?? ''))),
            'locale' => strtolower(trim((string) ($projectionItem['locale'] ?? ''))),
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'projection_state' => $state,
            'route_exists' => $detailRouteEnabled,
            'final_200' => $final200,
            'robots_indexable' => $robotsIndexable,
            'canonical_self' => $canonicalSelf,
            'dataset_visible' => $datasetVisible,
            'search_visible' => $searchVisible,
            'sitemap_live' => $sitemapLive,
            'llms_live' => $llmsLive,
            'llms_full_live' => $llmsFullLive,
            'release_gate_pass' => $releaseGatePass,
            'canonical_url' => $projectionItem['canonical_url'] ?? null,
            'fully_live' => $fullyLive,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $excludedCounts
     * @return array<string, int>
     */
    private function counts(array $items, array $excludedCounts): array
    {
        return [
            'canonical_projection_rows' => count($items),
            'excluded_non_canonical_rows' => array_sum($excludedCounts),
            'published' => $this->countWhere($items, 'projection_state', CareerRuntimePublishProjectionService::STATE_PUBLISHED),
            'published_candidate' => $this->countWhere($items, 'projection_state', CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE),
            'blocked' => $this->countWhere($items, 'projection_state', CareerRuntimePublishProjectionService::STATE_BLOCKED),
            'quarantined' => $this->countWhere($items, 'projection_state', CareerRuntimePublishProjectionService::STATE_QUARANTINED),
            'route_exists' => $this->countTrue($items, 'route_exists'),
            'final_200' => $this->countTrue($items, 'final_200'),
            'robots_indexable' => $this->countTrue($items, 'robots_indexable'),
            'canonical_self' => $this->countTrue($items, 'canonical_self'),
            'dataset_visible' => $this->countTrue($items, 'dataset_visible'),
            'search_visible' => $this->countTrue($items, 'search_visible'),
            'sitemap_live' => $this->countTrue($items, 'sitemap_live'),
            'llms_live' => $this->countTrue($items, 'llms_live'),
            'llms_full_live' => $this->countTrue($items, 'llms_full_live'),
            'release_gate_pass' => $this->countTrue($items, 'release_gate_pass'),
            'fully_live' => $this->countTrue($items, 'fully_live'),
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

    /**
     * @return array<string, mixed>
     */
    private function readProjectionPath(string $projectionPath): array
    {
        $path = trim($projectionPath);
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException('Career runtime publish projection file not found: '.$projectionPath);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Career runtime publish projection file is not valid JSON: '.$projectionPath);
        }

        return is_array($payload['projection'] ?? null) ? $payload['projection'] : $payload;
    }
}
