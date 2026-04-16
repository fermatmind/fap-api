<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerStrongIndexEligibilityService;
use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\DTO\Career\CareerFullDatasetAuthority;
use App\DTO\Career\CareerFullDatasetMember;
use App\Models\Occupation;

final class CareerFullDatasetAuthorityBuilder
{
    public const AUTHORITY_KIND = 'career_full_dataset_authority';

    public const AUTHORITY_VERSION = 'career.dataset_authority.full_342.v1';

    public const DATASET_KEY = 'career_all_342_occupations_dataset';

    public const DATASET_SCOPE = 'career_all_342';

    /**
     * @var list<string>
     */
    private const BATCH_MANIFEST_PATHS = [
        'docs/career/batches/batch_2_manifest.json',
        'docs/career/batches/batch_3_manifest.json',
        'docs/career/batches/batch_4_manifest.json',
    ];

    /**
     * @var array<string, true>
     */
    private const PUBLIC_INCLUDED_RELEASE_COHORTS = [
        'public_detail_indexable' => true,
        'public_detail_conservative' => true,
    ];

    public function __construct(
        private readonly CareerFullReleaseLedgerService $fullReleaseLedgerService,
        private readonly CareerStrongIndexEligibilityService $strongIndexEligibilityService,
        private readonly CareerDatasetPublicationMetadataService $publicationMetadataService,
        private readonly CareerAssetBatchManifestBuilder $batchManifestBuilder,
        private readonly FirstWaveManifestReader $firstWaveManifestReader,
    ) {}

    public function build(): CareerFullDatasetAuthority
    {
        $ledger = $this->fullReleaseLedgerService->build()->toArray();
        $strongIndex = $this->strongIndexEligibilityService->build()->toArray();
        $trackingCounts = (array) ($ledger['tracking_counts'] ?? []);

        $ledgerMembers = array_values(array_filter(
            (array) ($ledger['members'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $strongIndexBySlug = $this->mapBySlug((array) ($strongIndex['members'] ?? []), 'canonical_slug');

        $batchContextBySlug = $this->buildBatchContextBySlug();
        $familyBySlug = $this->loadFamilyBySlug(array_map(
            static fn (array $member): string => (string) ($member['canonical_slug'] ?? ''),
            $ledgerMembers,
        ));

        $releaseCohortCounts = [];
        $strongDecisionCounts = [];
        $publicIndexStateCounts = [];
        $includedReleaseCohortCounts = [];
        $excludedReleaseCohortCounts = [];
        $familyDistribution = [];
        $publishTrackDistribution = [];

        $includedCount = 0;
        $excludedCount = 0;

        $members = [];
        foreach ($ledgerMembers as $ledgerMember) {
            $slug = trim((string) ($ledgerMember['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $releaseCohort = $this->normalizeNullableString($ledgerMember['release_cohort'] ?? null);
            $publicIndexState = $this->normalizeNullableString($ledgerMember['public_index_state'] ?? null);

            $strongIndexRow = $strongIndexBySlug[$slug] ?? null;
            $strongIndexDecision = is_array($strongIndexRow)
                ? $this->normalizeNullableString($strongIndexRow['strong_index_decision'] ?? null)
                : null;

            $batchContext = $batchContextBySlug[$slug] ?? null;
            $familySlug = is_array($batchContext)
                ? $this->normalizeNullableString($batchContext['family_slug'] ?? null)
                : null;
            $publishTrack = is_array($batchContext)
                ? $this->normalizeNullableString($batchContext['publish_track'] ?? null)
                : null;

            if ($familySlug === null) {
                $familySlug = $this->normalizeNullableString($familyBySlug[$slug] ?? null);
            }

            $included = $releaseCohort !== null && isset(self::PUBLIC_INCLUDED_RELEASE_COHORTS[$releaseCohort]);
            $exclusionReasons = $included
                ? []
                : $this->buildExclusionReasons($releaseCohort, (array) ($ledgerMember['blocker_reasons'] ?? []));

            if ($included) {
                $includedCount++;
            } else {
                $excludedCount++;
            }

            if ($releaseCohort !== null) {
                $releaseCohortCounts[$releaseCohort] = (int) ($releaseCohortCounts[$releaseCohort] ?? 0) + 1;
                if ($included) {
                    $includedReleaseCohortCounts[$releaseCohort] = (int) ($includedReleaseCohortCounts[$releaseCohort] ?? 0) + 1;
                } else {
                    $excludedReleaseCohortCounts[$releaseCohort] = (int) ($excludedReleaseCohortCounts[$releaseCohort] ?? 0) + 1;
                }
            }

            if ($strongIndexDecision !== null) {
                $strongDecisionCounts[$strongIndexDecision] = (int) ($strongDecisionCounts[$strongIndexDecision] ?? 0) + 1;
            }

            if ($publicIndexState !== null) {
                $publicIndexStateCounts[$publicIndexState] = (int) ($publicIndexStateCounts[$publicIndexState] ?? 0) + 1;
            }

            $familyBucket = $familySlug ?? '__unknown__';
            $familyDistribution[$familyBucket] = (int) ($familyDistribution[$familyBucket] ?? 0) + 1;

            $publishTrackBucket = $publishTrack ?? '__unknown__';
            $publishTrackDistribution[$publishTrackBucket] = (int) ($publishTrackDistribution[$publishTrackBucket] ?? 0) + 1;

            $members[] = new CareerFullDatasetMember(
                memberKind: 'career_tracked_occupation',
                canonicalSlug: $slug,
                canonicalTitleEn: $this->normalizeNullableString($ledgerMember['canonical_title_en'] ?? null),
                familySlug: $familySlug,
                publishTrack: $publishTrack,
                batchOrigin: $this->normalizeNullableString($ledgerMember['batch_origin'] ?? null),
                releaseCohort: $releaseCohort,
                publicIndexState: $publicIndexState,
                strongIndexDecision: $strongIndexDecision,
                includedInPublicDataset: $included,
                exclusionReasons: $exclusionReasons,
                publicFacets: [
                    'release_cohort' => $releaseCohort,
                    'public_index_state' => $publicIndexState,
                    'strong_index_decision' => $strongIndexDecision,
                    'family_slug' => $familySlug,
                    'publish_track' => $publishTrack,
                ],
            );
        }

        usort($members, static fn (CareerFullDatasetMember $left, CareerFullDatasetMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        ksort($releaseCohortCounts);
        ksort($strongDecisionCounts);
        ksort($publicIndexStateCounts);
        ksort($includedReleaseCohortCounts);
        ksort($excludedReleaseCohortCounts);
        ksort($familyDistribution);
        ksort($publishTrackDistribution);

        $memberCount = count($members);
        $expectedTotal = (int) ($trackingCounts['expected_total_occupations'] ?? $memberCount);
        $trackedTotal = (int) ($trackingCounts['tracked_total_occupations'] ?? $memberCount);

        return new CareerFullDatasetAuthority(
            authorityKind: self::AUTHORITY_KIND,
            authorityVersion: self::AUTHORITY_VERSION,
            datasetKey: self::DATASET_KEY,
            datasetScope: self::DATASET_SCOPE,
            memberKind: 'career_tracked_occupation',
            memberCount: $memberCount,
            trackingCounts: [
                'expected_total_occupations' => $expectedTotal,
                'tracked_total_occupations' => $trackedTotal,
                'tracking_complete' => (bool) ($trackingCounts['tracking_complete'] ?? ($trackedTotal > 0 && $expectedTotal === $trackedTotal)),
                'missing_occupations' => max(0, $expectedTotal - $trackedTotal),
            ],
            summary: [
                'included_count' => $includedCount,
                'excluded_count' => $excludedCount,
                'included_rate' => $memberCount > 0 ? round($includedCount / $memberCount, 6) : 0.0,
                'excluded_rate' => $memberCount > 0 ? round($excludedCount / $memberCount, 6) : 0.0,
                'release_cohort_counts' => $releaseCohortCounts,
                'included_release_cohort_counts' => $includedReleaseCohortCounts,
                'excluded_release_cohort_counts' => $excludedReleaseCohortCounts,
                'public_index_state_counts' => $publicIndexStateCounts,
                'strong_index_decision_counts' => $strongDecisionCounts,
            ],
            facetDistributions: [
                'family' => $familyDistribution,
                'publish_track' => $publishTrackDistribution,
                'release_cohort' => $releaseCohortCounts,
                'public_index_state' => $publicIndexStateCounts,
                'strong_index_decision' => $strongDecisionCounts,
                'included_excluded' => [
                    'included' => $includedCount,
                    'excluded' => $excludedCount,
                ],
            ],
            publication: $this->publicationMetadataService->build()->toArray(),
            members: $members,
        );
    }

    /**
     * @return array<string, array{publish_track:?string,family_slug:?string}>
     */
    private function buildBatchContextBySlug(): array
    {
        $context = [];

        foreach (self::BATCH_MANIFEST_PATHS as $path) {
            $manifest = $this->batchManifestBuilder->fromPath($path);
            foreach ($manifest->members as $member) {
                $slug = trim((string) $member->canonicalSlug);
                if ($slug === '') {
                    continue;
                }

                $context[$slug] = [
                    'publish_track' => $this->normalizeNullableString($member->expectedPublishTrack),
                    'family_slug' => $this->normalizeNullableString($member->familySlug),
                ];
            }
        }

        $firstWave = $this->firstWaveManifestReader->read();
        foreach ((array) ($firstWave['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            if (! isset($context[$slug])) {
                $context[$slug] = [
                    'publish_track' => $this->normalizeNullableString($row['wave_classification'] ?? null),
                    'family_slug' => null,
                ];
            } elseif (($context[$slug]['publish_track'] ?? null) === null) {
                $context[$slug]['publish_track'] = $this->normalizeNullableString($row['wave_classification'] ?? null);
            }
        }

        return $context;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function loadFamilyBySlug(array $slugs): array
    {
        $normalizedSlugs = array_values(array_filter(array_unique(array_map(
            static fn (string $slug): string => trim($slug),
            $slugs,
        )), static fn (string $slug): bool => $slug !== ''));

        if ($normalizedSlugs === []) {
            return [];
        }

        return Occupation::query()
            ->with('family:id,canonical_slug')
            ->whereIn('canonical_slug', $normalizedSlugs)
            ->get(['id', 'family_id', 'canonical_slug'])
            ->mapWithKeys(static function (Occupation $occupation): array {
                $familySlug = $occupation->family?->canonical_slug;
                if (! is_string($familySlug) || trim($familySlug) === '') {
                    return [];
                }

                return [
                    (string) $occupation->canonical_slug => trim($familySlug),
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function mapBySlug(array $rows, string $key = 'canonical_slug'): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row[$key] ?? ''));
            if ($slug === '') {
                continue;
            }

            $mapped[$slug] = $row;
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>  $blockerReasons
     * @return list<string>
     */
    private function buildExclusionReasons(?string $releaseCohort, array $blockerReasons): array
    {
        $reasons = [];

        if ($releaseCohort !== null) {
            $reasons[] = 'release_cohort_'.$releaseCohort;
        }

        foreach ($blockerReasons as $reason) {
            $normalized = $this->normalizeNullableString($reason);
            if ($normalized !== null) {
                $reasons[] = $normalized;
            }
        }

        return array_values(array_unique($reasons));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
