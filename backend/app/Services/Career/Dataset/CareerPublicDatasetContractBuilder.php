<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\DTO\Career\CareerPublicDatasetContract;
use App\DTO\Career\CareerPublicDatasetMethodContract;

final class CareerPublicDatasetContractBuilder
{
    public function __construct(
        private readonly CareerDatasetPublicationMetadataService $publicationMetadataService,
        private readonly CareerFirstWaveDatasetAuthorityBuilder $datasetAuthorityBuilder,
    ) {}

    public function buildHubContract(): CareerPublicDatasetContract
    {
        $publication = $this->publicationMetadataService->build()->toArray();
        $authority = $this->datasetAuthorityBuilder->build()->toArray();

        $counts = (array) data_get($authority, 'aggregate.counts', []);
        $collectionSummary = [
            'member_kind' => (string) data_get($authority, 'aggregate.member_kind', 'career_job_detail'),
            'member_count' => (int) data_get($authority, 'aggregate.member_count', 0),
            'stable_count' => (int) ($counts['stable'] ?? 0),
            'candidate_count' => (int) ($counts['candidate'] ?? 0),
            'hold_count' => (int) ($counts['hold'] ?? 0),
            'discoverable_count' => (int) ($counts['discoverable'] ?? 0),
            'excluded_count' => (int) ($counts['excluded'] ?? 0),
            'manifest_version' => (string) data_get($authority, 'descriptor.manifest_version', 'unknown'),
            'selection_policy_version' => (string) data_get($authority, 'descriptor.selection_policy_version', 'unknown'),
        ];

        return new CareerPublicDatasetContract(
            datasetKey: (string) data_get($authority, 'descriptor.dataset_key', CareerDatasetPublicationMetadataService::DATASET_KEY),
            datasetScope: (string) data_get($authority, 'descriptor.dataset_scope', CareerDatasetPublicationMetadataService::DATASET_SCOPE),
            datasetName: 'FermatMind Career Occupations Dataset (First Wave)',
            datasetNameZh: '费马测试职业数据库（首批职业集）',
            publication: $publication,
            collectionSummary: $collectionSummary,
            filters: [
                'family' => true,
                'publish_track' => true,
                'index_posture' => true,
            ],
            methodUrl: (string) data_get($publication, 'distribution.methodology_url', 'https://www.fermatmind.com/datasets/occupations/method'),
        );
    }

    public function buildMethodContract(): CareerPublicDatasetMethodContract
    {
        $publication = $this->publicationMetadataService->build()->toArray();
        $authority = $this->datasetAuthorityBuilder->build()->toArray();

        return new CareerPublicDatasetMethodContract(
            datasetKey: (string) data_get($authority, 'descriptor.dataset_key', CareerDatasetPublicationMetadataService::DATASET_KEY),
            datasetScope: (string) data_get($authority, 'descriptor.dataset_scope', CareerDatasetPublicationMetadataService::DATASET_SCOPE),
            methodUrl: (string) data_get($publication, 'distribution.methodology_url', 'https://www.fermatmind.com/datasets/occupations/method'),
            hubUrl: (string) data_get($publication, 'distribution.documentation_url', 'https://www.fermatmind.com/datasets/occupations'),
            title: 'Occupations dataset method',
            summary: 'This dataset is compiled from backend-owned occupation authority, launch policy, and trust manifests under a conservative publication boundary.',
            sourceSummary: 'Included records come from career_job_detail members in the first-wave authority scope and are materialized through import/compile runs.',
            reviewDisciplineSummary: 'Updates follow validate, trust compile, and publish-candidate discipline. Internal review queue states are not published in this method contract.',
            included: [
                'career_job_detail first-wave members only',
                'public-safe publication metadata (publisher, license, usage, distribution)',
                'high-level launch and index posture summaries',
            ],
            excluded: [
                'family hub as primary dataset members',
                'raw internal evidence refs and review queue internals',
                'internal storage/source paths and debug-only provenance fields',
            ],
            boundaryNotes: [
                'Dataset scope is currently limited to career_first_wave_10.',
                'Crosswalk and editorial overrides remain backend-owned and are not exposed as raw operational records.',
                'This page is a public method summary, not an internal authority dump.',
            ],
        );
    }
}
