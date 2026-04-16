<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\DTO\Career\CareerPublicDatasetContract;
use App\DTO\Career\CareerPublicDatasetMethodContract;

final class CareerPublicDatasetContractBuilder
{
    public function __construct(
        private readonly CareerDatasetPublicationMetadataService $publicationMetadataService,
        private readonly CareerFullDatasetAuthorityBuilder $datasetAuthorityBuilder,
    ) {}

    public function buildHubContract(): CareerPublicDatasetContract
    {
        $publication = $this->publicationMetadataService->build()->toArray();
        $authority = $this->datasetAuthorityBuilder->build()->toArray();

        $summary = (array) data_get($authority, 'summary', []);
        $facetDistributions = (array) data_get($authority, 'facet_distributions', []);
        $releaseCohortCounts = (array) data_get($summary, 'release_cohort_counts', []);
        $publishTrackCounts = (array) data_get($facetDistributions, 'publish_track', []);
        $collectionSummary = [
            'member_kind' => (string) data_get($authority, 'member_kind', 'career_tracked_occupation'),
            'member_count' => (int) data_get($authority, 'member_count', 0),
            'included_count' => (int) data_get($summary, 'included_count', 0),
            'excluded_count' => (int) data_get($summary, 'excluded_count', 0),
            'public_detail_indexable_count' => (int) ($releaseCohortCounts['public_detail_indexable'] ?? 0),
            'public_detail_conservative_count' => (int) ($releaseCohortCounts['public_detail_conservative'] ?? 0),
            'stable_count' => (int) ($publishTrackCounts['stable'] ?? 0),
            'candidate_count' => (int) ($publishTrackCounts['candidate'] ?? 0),
            'hold_count' => (int) ($publishTrackCounts['hold'] ?? 0),
            'discoverable_count' => (int) data_get($summary, 'included_count', 0),
            'manifest_version' => self::safeString(data_get($authority, 'authority_version'), 'unknown'),
            'selection_policy_version' => (string) data_get($authority, 'tracking_counts.tracking_complete', false)
                ? 'career_full_release_ledger'
                : 'tracking_incomplete',
            'release_cohort_counts' => $releaseCohortCounts,
            'public_index_state_counts' => (array) data_get($summary, 'public_index_state_counts', []),
            'strong_index_decision_counts' => (array) data_get($summary, 'strong_index_decision_counts', []),
            'included_release_cohort_counts' => (array) data_get($summary, 'included_release_cohort_counts', []),
            'excluded_release_cohort_counts' => (array) data_get($summary, 'excluded_release_cohort_counts', []),
            'tracking_counts' => (array) data_get($authority, 'tracking_counts', []),
            'facet_distributions' => $facetDistributions,
        ];

        return new CareerPublicDatasetContract(
            datasetKey: (string) data_get($authority, 'dataset_key', CareerDatasetPublicationMetadataService::DATASET_KEY),
            datasetScope: (string) data_get($authority, 'dataset_scope', CareerDatasetPublicationMetadataService::DATASET_SCOPE),
            datasetName: 'FermatMind Career Occupations Dataset (All 342 Tracked Occupations)',
            datasetNameZh: '费马测试职业数据库（342 全量职业范围）',
            publication: $publication,
            collectionSummary: $collectionSummary,
            filters: [
                'family' => true,
                'publish_track' => true,
                'index_posture' => true,
                'included_excluded' => true,
            ],
            methodUrl: (string) data_get($publication, 'distribution.methodology_url', 'https://www.fermatmind.com/datasets/occupations/method'),
        );
    }

    public function buildMethodContract(): CareerPublicDatasetMethodContract
    {
        $publication = $this->publicationMetadataService->build()->toArray();
        $authority = $this->datasetAuthorityBuilder->build()->toArray();

        return new CareerPublicDatasetMethodContract(
            datasetKey: (string) data_get($authority, 'dataset_key', CareerDatasetPublicationMetadataService::DATASET_KEY),
            datasetScope: (string) data_get($authority, 'dataset_scope', CareerDatasetPublicationMetadataService::DATASET_SCOPE),
            methodUrl: (string) data_get($publication, 'distribution.methodology_url', 'https://www.fermatmind.com/datasets/occupations/method'),
            hubUrl: (string) data_get($publication, 'distribution.documentation_url', 'https://www.fermatmind.com/datasets/occupations'),
            title: 'Occupations dataset method',
            summary: 'This dataset is compiled from backend-owned full-342 career authorities with explicit included/excluded public scope boundaries.',
            sourceSummary: 'Members are derived from career_tracked_occupation scope (career_all_342) and reconciled with release ledger, strong-index eligibility, and publication metadata authorities.',
            reviewDisciplineSummary: 'Updates follow validate, trust compile, release-ledger reconciliation, and conservative publicization discipline. Internal queue internals remain private.',
            included: [
                'career_tracked_occupation members in career_all_342 scope',
                'public-safe publication metadata (publisher, license, usage, distribution)',
                'public-safe included/excluded, cohort, index-posture, and publish-track summaries',
            ],
            excluded: [
                'family hub as primary dataset members',
                'raw internal evidence refs and review queue internals',
                'internal storage/source paths and debug-only provenance fields',
            ],
            boundaryNotes: [
                'Dataset scope is career_all_342 with explicit included/excluded publication boundaries.',
                'Crosswalk and editorial overrides remain backend-owned and are not exposed as raw operational records.',
                'This page is a public method summary, not an internal authority dump.',
            ],
            scopeSummary: [
                'member_count' => (int) data_get($authority, 'member_count', 0),
                'included_count' => (int) data_get($authority, 'summary.included_count', 0),
                'excluded_count' => (int) data_get($authority, 'summary.excluded_count', 0),
                'release_cohort_counts' => (array) data_get($authority, 'summary.release_cohort_counts', []),
                'strong_index_decision_counts' => (array) data_get($authority, 'summary.strong_index_decision_counts', []),
            ],
            publication: $publication,
        );
    }

    private static function safeString(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? $fallback : $normalized;
    }
}
