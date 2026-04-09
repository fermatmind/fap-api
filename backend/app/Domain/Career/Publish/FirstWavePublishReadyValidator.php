<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\Import\FirstWaveAliasCatalogReader;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationTruthMetric;
use App\Models\RecommendationSnapshot;
use App\Models\TrustManifest;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\Bundles\CareerSearchBundleBuilder;

final class FirstWavePublishReadyValidator
{
    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly FirstWaveAliasCatalogReader $aliasCatalogReader,
        private readonly FirstWavePublishGate $publishGate,
        private readonly CareerJobDetailBundleBuilder $jobDetailBundleBuilder,
        private readonly CareerJobListBundleBuilder $jobListBundleBuilder,
        private readonly CareerSearchBundleBuilder $searchBundleBuilder,
    ) {}

    /**
     * @param  array<string, list<string>>  $externalIssuesBySlug
     * @return array{
     *   wave_name:string,
     *   counts:array{publish_ready:int,partial:int,blocked:int},
     *   occupations:list<array<string,mixed>>
     * }
     */
    public function validate(array $externalIssuesBySlug = []): array
    {
        $manifest = $this->manifestReader->read();
        $aliasCatalog = $this->aliasCatalogReader->bySlug();

        $jobListSlugs = array_fill_keys(array_map(
            static fn (object $item): string => (string) ($item->identity['canonical_slug'] ?? ''),
            $this->jobListBundleBuilder->build()
        ), true);

        $items = [];
        $counts = [
            'publish_ready' => 0,
            'partial' => 0,
            'blocked' => 0,
        ];

        foreach ($manifest['occupations'] as $manifestOccupation) {
            $slug = (string) $manifestOccupation['canonical_slug'];
            $occupation = Occupation::query()
                ->with(['family', 'aliases'])
                ->where('canonical_slug', $slug)
                ->first();

            $truthMetric = $occupation?->truthMetrics()
                ->orderByDesc('reviewed_at')
                ->orderByDesc('effective_at')
                ->orderByDesc('created_at')
                ->first();
            $trustManifest = $occupation?->trustManifests()
                ->orderByDesc('reviewed_at')
                ->orderByDesc('created_at')
                ->first();
            $indexState = $occupation?->indexStates()
                ->orderByDesc('changed_at')
                ->orderByDesc('updated_at')
                ->first();
            $snapshot = $occupation?->recommendationSnapshots()
                ->with(['contextSnapshot', 'profileProjection'])
                ->orderByDesc('compiled_at')
                ->orderByDesc('created_at')
                ->first();

            $missing = [];
            $notes = array_values(array_unique($externalIssuesBySlug[$slug] ?? []));

            if (! $occupation instanceof Occupation) {
                $missing[] = 'occupation_missing';
            } else {
                if ($occupation->id !== (string) $manifestOccupation['occupation_uuid']) {
                    $missing[] = 'occupation_uuid_mismatch';
                }

                if ($occupation->family?->id === null) {
                    $missing[] = 'family_missing';
                } elseif ($occupation->family?->id !== (string) $manifestOccupation['family_uuid']) {
                    $missing[] = 'family_uuid_mismatch';
                }

                if ($occupation->crosswalk_mode !== (string) $manifestOccupation['crosswalk_mode']) {
                    $missing[] = 'crosswalk_mode_mismatch';
                }
            }

            if (! $truthMetric instanceof OccupationTruthMetric) {
                $missing[] = 'truth_metric_missing';
            }
            if (! $trustManifest instanceof TrustManifest) {
                $missing[] = 'trust_manifest_missing';
            }
            if (! $indexState instanceof IndexState) {
                $missing[] = 'index_state_missing';
            }
            if (! $snapshot instanceof RecommendationSnapshot) {
                $missing[] = 'compiled_snapshot_missing';
            } else {
                if ($snapshot->compiled_at === null || $snapshot->compile_run_id === null) {
                    $missing[] = 'compiled_snapshot_incomplete';
                }
                if (($snapshot->contextSnapshot?->context_payload['materialization'] ?? null) !== 'career_first_wave') {
                    $missing[] = 'context_materialization_mismatch';
                }
                if (($snapshot->profileProjection?->projection_payload['materialization'] ?? null) !== 'career_first_wave') {
                    $missing[] = 'profile_materialization_mismatch';
                }
            }

            $this->aliasIssues($occupation, $aliasCatalog[$slug] ?? null, $missing);

            $gate = $this->publishGate->evaluate([
                'crosswalk_mode' => $occupation?->crosswalk_mode,
                'confidence_score' => data_get($trustManifest?->quality, 'confidence_score', data_get($trustManifest?->quality, 'confidence', 0)),
                'reviewer_status' => $trustManifest?->reviewer_status,
                'index_state' => $indexState?->index_state,
                'index_eligible' => $indexState?->index_eligible,
                'allow_strong_claim' => data_get($snapshot?->snapshot_payload, 'claim_permissions.allow_strong_claim', false),
            ]);

            if (! (bool) $gate['publishable']) {
                $missing[] = 'publish_gate_not_publishable';
            }

            $detailReady = $this->jobDetailBundleBuilder->buildBySlug($slug) !== null;
            if (! $detailReady) {
                $missing[] = 'detail_bundle_unavailable';
            }

            $listReady = isset($jobListSlugs[$slug]);
            if (! $listReady) {
                $missing[] = 'job_list_unavailable';
            }

            $searchReady = false;
            $searchResults = $this->searchBundleBuilder->build($slug, 1, null, 'exact');
            if ($searchResults !== [] && (($searchResults[0]->identity['canonical_slug'] ?? null) === $slug)) {
                $searchReady = true;
            }
            if (! $searchReady) {
                $missing[] = 'search_unavailable';
            }

            $missing = array_values(array_unique(array_filter($missing)));
            $status = $this->statusFor($occupation, $truthMetric, $trustManifest, $indexState, $snapshot, $missing);
            $counts[$status]++;

            $items[] = [
                'occupation_uuid' => (string) $manifestOccupation['occupation_uuid'],
                'canonical_slug' => $slug,
                'status' => $status,
                'missing_requirements' => $missing,
                'notes' => $notes,
                'crosswalk_mode' => $occupation?->crosswalk_mode,
                'trust_status' => $gate['classification'],
                'reviewer_status' => $trustManifest?->reviewer_status,
                'index_state' => $indexState?->index_state,
                'index_eligible' => (bool) ($indexState?->index_eligible ?? false),
                'alias_count' => $occupation?->aliases->count() ?? 0,
                'compiled_snapshot_present' => $snapshot?->compiled_at !== null,
            ];
        }

        return [
            'wave_name' => (string) $manifest['wave_name'],
            'counts' => $counts,
            'occupations' => $items,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $aliasCatalogEntry
     * @param  list<string>  &$missing
     */
    private function aliasIssues(?Occupation $occupation, ?array $aliasCatalogEntry, array &$missing): void
    {
        if (! $occupation instanceof Occupation || ! is_array($aliasCatalogEntry)) {
            return;
        }

        $aliases = $occupation->aliases ?? collect();
        $actual = [];
        foreach ($aliases as $alias) {
            if (! $alias instanceof OccupationAlias) {
                continue;
            }

            $actual[strtolower((string) $alias->lang).'|'.trim((string) $alias->normalized)] = true;
        }

        foreach ((array) ($aliasCatalogEntry['approved_alias_rows'] ?? []) as $row) {
            $lang = strtolower(trim((string) ($row['lang'] ?? '')));
            $normalized = trim((string) ($row['normalized'] ?? ''));
            if ($lang === '' || $normalized === '') {
                continue;
            }

            $key = $lang.'|'.$normalized;
            if (! isset($actual[$key])) {
                $missing[] = 'approved_alias_rows_missing';
                break;
            }
        }

        foreach ((array) ($aliasCatalogEntry['blocked_aliases'] ?? []) as $blockedAlias) {
            $blockedNormalized = mb_strtolower(trim((string) $blockedAlias), 'UTF-8');
            foreach ($aliases as $alias) {
                if (! $alias instanceof OccupationAlias) {
                    continue;
                }

                if (mb_strtolower(trim((string) $alias->alias), 'UTF-8') === $blockedNormalized) {
                    $missing[] = 'blocked_alias_materialized';
                    break 2;
                }
            }
        }
    }

    /**
     * @param  list<string>  $missing
     */
    private function statusFor(
        ?Occupation $occupation,
        ?OccupationTruthMetric $truthMetric,
        ?TrustManifest $trustManifest,
        ?IndexState $indexState,
        ?RecommendationSnapshot $snapshot,
        array $missing,
    ): string {
        if ($missing === []) {
            return 'publish_ready';
        }

        if (
            ! $occupation instanceof Occupation
            || ! $truthMetric instanceof OccupationTruthMetric
            || ! $trustManifest instanceof TrustManifest
            || ! $indexState instanceof IndexState
            || ! $snapshot instanceof RecommendationSnapshot
        ) {
            return 'blocked';
        }

        return 'partial';
    }
}
