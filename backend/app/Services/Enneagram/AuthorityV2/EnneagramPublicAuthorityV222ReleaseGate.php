<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use JsonException;
use RuntimeException;

final class EnneagramPublicAuthorityV222ReleaseGate
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RELEASE-GATE-22';

    /** @var list<string> */
    private const ASSET_SOURCES = [
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-hub-centers-09/hub-centers-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-1-family-10/type-1-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-2-family-11/type-2-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-3-family-12/type-3-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-4-family-13/type-4-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-5-family-14/type-5-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-6-family-15/type-6-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-7-family-16/type-7-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-8-family-17/type-8-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-9-family-18/type-9-family-draft.json',
    ];

    /** @var list<string> */
    private const QA_SOURCES = [
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-hub-centers-09/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-1-family-10/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-2-family-11/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-3-family-12/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-4-family-13/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-5-family-14/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-6-family-15/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-7-family-16/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-8-family-17/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-9-family-18/qa-report.json',
    ];

    private const PAGE_MAPS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json';

    private const SOURCE_REGISTRY = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/source-registry.json';

    private const BENCHMARK = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json';

    private const LINK_GRAPH = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-link-graph-20/link-graph.json';

    private const MEDIA_SPECIFICATIONS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-media-og-19/media-specifications.json';

    private const MEDIA_MAPPINGS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-media-og-19/localized-og-mappings.json';

    /** @var list<string> */
    private const HIDDEN_SCHEMA_KEYS = ['json_ld', 'schema', 'structured_data', 'faq_schema'];

    /** @var array<string, int> */
    private const ENTITY_COUNTS = [
        'center' => 6,
        'core_type' => 18,
        'hub' => 2,
        'instinctual_subtype' => 54,
        'wing' => 36,
    ];

    /** @var list<string> */
    private const EXECUTION_BOUNDARY_KEYS = [
        'production_write_executed',
        'database_mutated',
        'cms_mutated',
        'revision_pointer_changed',
        'media_uploaded',
        'cache_revalidated',
        'indexability_changed',
        'sitemap_changed',
        'llms_changed',
        'search_submitted',
        'deployed',
    ];

    public function __construct(
        private readonly EnneagramPublicAuthorityV2IntegrityGate $integrityGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(string $basePath, string $manualReviewsPath): array
    {
        $errors = [];
        $assetRecords = [];
        $assetsByKey = [];
        $aggregateAssets = [];
        $paths = [];
        $entityCounts = [];
        $localeCounts = [];

        foreach (self::ASSET_SOURCES as $sourcePath) {
            $source = $this->loadJson($basePath, $sourcePath);
            $assets = is_array($source['assets'] ?? null) ? $source['assets'] : [];
            foreach ($assets as $index => $asset) {
                if (! is_array($asset)) {
                    $errors[] = $this->error('asset_not_object', $sourcePath.'#'.$index);

                    continue;
                }

                $assetKey = $this->assetKey($asset);
                if ($assetKey === null) {
                    $errors[] = $this->error('asset_identity_missing', $sourcePath.'#'.$index);

                    continue;
                }
                if (isset($assetsByKey[$assetKey])) {
                    $errors[] = $this->error('duplicate_asset_key', $assetKey);

                    continue;
                }

                $path = (string) ($asset['path'] ?? '');
                if ($path === '' || isset($paths[$path])) {
                    $errors[] = $this->error($path === '' ? 'asset_path_missing' : 'duplicate_asset_path', $assetKey);
                }
                $paths[$path] = true;
                $assetsByKey[$assetKey] = $asset;
                $aggregateAssets[] = $asset;
                $entity = (string) ($asset['entity_type'] ?? '');
                $locale = (string) ($asset['locale'] ?? '');
                $entityCounts[$entity] = ($entityCounts[$entity] ?? 0) + 1;
                $localeCounts[$locale] = ($localeCounts[$locale] ?? 0) + 1;

                $review = is_array($asset['review_truth'] ?? null) ? $asset['review_truth'] : [];
                $release = is_array($asset['release_truth'] ?? null) ? $asset['release_truth'] : [];
                if (($review['status'] ?? null) !== 'pending_manual_review'
                    || ($review['reviewer'] ?? null) !== null
                    || ($review['reviewed_at'] ?? null) !== null
                    || ($review['human_review_completed'] ?? null) !== false) {
                    $errors[] = $this->error('draft_review_truth_invalid', $assetKey);
                }
                if (($release['draft_only'] ?? null) !== true
                    || ($release['publish_eligible'] ?? null) !== false
                    || ($release['indexability_changed'] ?? null) !== false
                    || ($release['sitemap_changed'] ?? null) !== false
                    || ($release['llms_changed'] ?? null) !== false) {
                    $errors[] = $this->error('draft_release_truth_invalid', $assetKey);
                }
                if ($this->isPrivatePath($path)) {
                    $errors[] = $this->error('private_path_detected', $assetKey);
                }
                foreach ($this->hiddenSchemaPaths($asset) as $hiddenSchemaPath) {
                    $errors[] = $this->error('hidden_schema_detected', $assetKey.'.'.$hiddenSchemaPath);
                }

                $assetRecords[] = [
                    'asset_key' => $assetKey,
                    'identity_key' => (string) ($asset['identity_key'] ?? ''),
                    'locale' => $locale,
                    'entity_type' => $entity,
                    'code' => (string) ($asset['code'] ?? ''),
                    'path' => $path,
                    'source_path' => $sourcePath,
                    'asset_sha256' => $this->hashValue($asset),
                ];
            }
        }

        usort($assetRecords, fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        ksort($entityCounts);
        ksort($localeCounts);

        if (count($assetRecords) !== 116 || count(array_unique(array_column($assetRecords, 'identity_key'))) !== 58) {
            $errors[] = $this->error('asset_count_mismatch', 'aggregate');
        }
        if ($entityCounts !== self::ENTITY_COUNTS || $localeCounts !== ['en' => 58, 'zh-CN' => 58]) {
            $errors[] = $this->error('taxonomy_count_mismatch', 'aggregate');
        }

        $pageMapsDocument = $this->loadJson($basePath, self::PAGE_MAPS);
        $pageMaps = is_array($pageMapsDocument['page_maps'] ?? null) ? $pageMapsDocument['page_maps'] : [];
        $pageMapByKey = $this->keyRows($pageMaps, 'page_map', $errors);
        $this->compareKeySets(array_keys($assetsByKey), array_keys($pageMapByKey), 'page_map_key_mismatch', $errors);

        $editorialGate = $this->integrityGate->validateEditorial(
            [
                'schema_version' => EnneagramPublicAuthorityV2IntegrityGate::EDITORIAL_SCHEMA_VERSION,
                'framework' => 'enneagram',
                'assets' => $aggregateAssets,
            ],
            $this->loadJson($basePath, self::SOURCE_REGISTRY),
            $pageMapsDocument,
        );
        $editorialIssues = $this->enrichEditorialIssues(
            is_array($editorialGate['issues'] ?? null) ? $editorialGate['issues'] : [],
            $aggregateAssets,
        );
        foreach ($editorialIssues as $issue) {
            $errors[] = $this->error(
                'editorial_integrity_'.(string) ($issue['code'] ?? 'unknown'),
                (string) ($issue['asset_key'] ?? $issue['path'] ?? 'aggregate'),
            );
        }
        if (($editorialGate['automated_gate_passed'] ?? null) !== true
            || ($editorialGate['qa_row_count'] ?? null) !== 116) {
            $errors[] = $this->error('editorial_integrity_gate_failed', 'aggregate');
        }

        $graphDocument = $this->loadJson($basePath, self::LINK_GRAPH);
        $graphRows = is_array($graphDocument['graph_records'] ?? null) ? $graphDocument['graph_records'] : [];
        $graphByKey = $this->keyRows($graphRows, 'graph', $errors);
        $this->compareKeySets(array_keys($assetsByKey), array_keys($graphByKey), 'graph_key_mismatch', $errors);

        foreach ($assetRecords as $record) {
            $key = $record['asset_key'];
            if (($pageMapByKey[$key]['path'] ?? null) !== $record['path']) {
                $errors[] = $this->error('page_map_path_mismatch', $key);
            }
            if (($graphByKey[$key]['path'] ?? null) !== $record['path']
                || ($graphByKey[$key]['canonical']['path'] ?? null) !== $record['path']) {
                $errors[] = $this->error('graph_path_mismatch', $key);
            }
        }
        $canonicalPaths = array_map(
            static fn (array $row): string => (string) ($row['canonical']['path'] ?? ''),
            $graphRows,
        );
        if (count($canonicalPaths) !== 116
            || in_array('', $canonicalPaths, true)
            || count(array_unique($canonicalPaths)) !== 116) {
            $errors[] = $this->error('canonical_count_mismatch', 'aggregate');
        }

        $qaAssetCount = 0;
        $qaSources = [];
        foreach (self::QA_SOURCES as $qaPath) {
            $qa = $this->loadJson($basePath, $qaPath);
            $finalQa = is_array($qa['final_qa'] ?? null) ? $qa['final_qa'] : [];
            if (($finalQa['status'] ?? null) !== 'pass_for_manual_review_handoff'
                || ($finalQa['asset_specific_issue_count'] ?? null) !== 0
                || ($finalQa['human_review_completed'] ?? null) !== false
                || ($finalQa['publish_eligible'] ?? null) !== false) {
                $errors[] = $this->error('qa_handoff_invalid', $qaPath);
            }
            $qaAssetCount += (int) ($finalQa['asset_count'] ?? 0);
            $qaSources[] = ['path' => $qaPath, 'sha256' => $this->hashFile($basePath, $qaPath)];
        }
        if ($qaAssetCount !== 116) {
            $errors[] = $this->error('qa_asset_count_mismatch', 'aggregate');
        }

        $benchmark = $this->loadJson($basePath, self::BENCHMARK);
        $benchmarkRows = is_array($benchmark['rows'] ?? null) ? $benchmark['rows'] : [];
        $fingerprints = [];
        foreach ($benchmarkRows as $row) {
            if (! is_array($row) || ($key = $this->assetKey($row)) === null) {
                $errors[] = $this->error('benchmark_identity_missing', 'benchmark');

                continue;
            }
            $fingerprints[$key] = [
                'asset_key' => $key,
                'path' => (string) ($row['path'] ?? ''),
                'pre_write_public_sha256' => $this->hashValue($row),
            ];
        }
        ksort($fingerprints);
        $this->compareKeySets(array_keys($assetsByKey), array_keys($fingerprints), 'benchmark_key_mismatch', $errors);
        $discoverabilityCounts = [
            'sitemap' => 0,
            'llms_txt' => 0,
            'llms_full_txt' => 0,
        ];
        foreach ($benchmarkRows as $row) {
            $discoverability = is_array($row['discoverability'] ?? null) ? $row['discoverability'] : [];
            $discoverabilityCounts['sitemap'] += ($discoverability['in_sitemap'] ?? null) === true ? 1 : 0;
            $discoverabilityCounts['llms_txt'] += ($discoverability['in_llms_txt'] ?? null) === true ? 1 : 0;
            $discoverabilityCounts['llms_full_txt'] += ($discoverability['in_llms_full_txt'] ?? null) === true ? 1 : 0;
        }
        if ($discoverabilityCounts !== ['sitemap' => 116, 'llms_txt' => 116, 'llms_full_txt' => 116]) {
            $errors[] = $this->error('discoverability_inventory_mismatch', 'benchmark');
        }

        $mediaSpecifications = $this->loadJson($basePath, self::MEDIA_SPECIFICATIONS);
        $mediaRows = is_array($mediaSpecifications['media_specifications'] ?? null) ? $mediaSpecifications['media_specifications'] : [];
        $mediaMappings = $this->loadJson($basePath, self::MEDIA_MAPPINGS);
        $mappingRows = is_array($mediaMappings['mappings'] ?? null) ? $mediaMappings['mappings'] : [];
        $mappingByKey = $this->keyRows($mappingRows, 'media_mapping', $errors);
        if (count($mediaRows) !== 58 || count($mappingByKey) !== 116) {
            $errors[] = $this->error('media_count_mismatch', 'aggregate');
        }
        $this->compareKeySets(array_keys($assetsByKey), array_keys($mappingByKey), 'media_mapping_key_mismatch', $errors);
        $mediaManifestRecords = [];
        foreach ($mediaRows as $index => $mediaRow) {
            if (! is_array($mediaRow)) {
                $errors[] = $this->error('media_manifest_record_invalid', 'media:'.$index);

                continue;
            }
            $mediaManifestRecords[] = [
                'identity_key' => (string) ($mediaRow['identity_key'] ?? ''),
                'spec_id' => (string) ($mediaRow['spec_id'] ?? ''),
                'record_sha256' => $this->hashValue($mediaRow),
            ];
        }
        usort($mediaManifestRecords, fn (array $left, array $right): int => $left['identity_key'] <=> $right['identity_key']);
        if (count(array_unique(array_column($mediaManifestRecords, 'identity_key'))) !== 58
            || count(array_unique(array_column($mediaManifestRecords, 'spec_id'))) !== 58) {
            $errors[] = $this->error('media_manifest_identity_mismatch', 'aggregate');
        }

        $pendingMediaRights = [];
        foreach ($mappingByKey as $key => $mapping) {
            $review = is_array($mapping['manual_rights_review'] ?? null) ? $mapping['manual_rights_review'] : [];
            if (($review['status'] ?? null) !== 'approved'
                || ($review['approved'] ?? null) !== true
                || ! is_string($review['reviewer'] ?? null)
                || trim((string) $review['reviewer']) === ''
                || ! is_string($review['reviewed_at'] ?? null)
                || trim((string) $review['reviewed_at']) === '') {
                $pendingMediaRights[] = $key;
            }
        }
        sort($pendingMediaRights);

        $manualReviews = $this->loadJson($basePath, $manualReviewsPath);
        $reviewEvidence = $this->validateManualReviews($manualReviews, $assetRecords, $errors);
        $missingReviews = [];
        foreach ($assetRecords as $record) {
            if (! isset($reviewEvidence['valid'][$record['asset_key']])) {
                $missingReviews[] = [
                    'asset_key' => $record['asset_key'],
                    'path' => $record['path'],
                    'asset_sha256' => $record['asset_sha256'],
                    'required_fields' => ['reviewer', 'reviewed_at', 'asset_sha256', 'decision'],
                ];
            }
        }

        $sourceHashes = [];
        foreach (array_merge(
            self::ASSET_SOURCES,
            self::QA_SOURCES,
            [self::PAGE_MAPS, self::SOURCE_REGISTRY, self::BENCHMARK, self::LINK_GRAPH, self::MEDIA_SPECIFICATIONS, self::MEDIA_MAPPINGS]
        ) as $path) {
            $sourceHashes[] = ['path' => $path, 'sha256' => $this->hashFile($basePath, $path)];
        }

        $automatedGatePassed = $errors === [];
        $humanReviewPassed = count($reviewEvidence['valid']) === 116
            && $missingReviews === []
            && $reviewEvidence['rejected'] === [];
        $mediaRightsPassed = $pendingMediaRights === [];
        $releaseEligible = $automatedGatePassed && $humanReviewPassed && $mediaRightsPassed;
        $packageSha = $this->hashValue([
            'asset_records' => $assetRecords,
            'pre_write_public_fingerprints' => array_values($fingerprints),
            'source_hashes' => $sourceHashes,
        ]);

        $status = match (true) {
            ! $automatedGatePassed => 'fail_closed',
            ! $humanReviewPassed => 'hold_missing_human_review',
            ! $mediaRightsPassed => 'hold_missing_media_rights_review',
            default => 'pass',
        };
        $currentBlockers = array_values(array_filter([
            $automatedGatePassed ? null : 'automated_release_gate_failed',
            $humanReviewPassed ? null : 'missing_or_rejected_named_human_review_records',
            $mediaRightsPassed ? null : 'missing_media_rights_review_records',
        ]));

        return [
            'artifact' => self::ARTIFACT,
            'schema_version' => 'enneagram_public_authority_v2_release_gate.v1',
            'status' => $status,
            'decision' => $releaseEligible ? 'PASS' : 'HOLD',
            'ok' => $releaseEligible,
            'automated_gate_passed' => $automatedGatePassed,
            'human_review_passed' => $humanReviewPassed,
            'media_rights_review_passed' => $mediaRightsPassed,
            'release_eligible' => $releaseEligible,
            'package_sha256' => $packageSha,
            'counts' => [
                'identities' => 58,
                'assets' => count($assetRecords),
                'locales' => $localeCounts,
                'entities' => $entityCounts,
                'source_mappings' => count($pageMapByKey),
                'qa_rows' => $qaAssetCount,
                'editorial_integrity_qa_rows' => (int) ($editorialGate['qa_row_count'] ?? 0),
                'graph_records' => count($graphByKey),
                'unique_canonicals' => count(array_unique($canonicalPaths)),
                'media_originals' => count($mediaRows),
                'media_mappings' => count($mappingByKey),
                'pre_write_public_fingerprints' => count($fingerprints),
                'named_human_reviews' => count($reviewEvidence['valid']),
                'approved_human_reviews' => count($reviewEvidence['approved']),
                'rejected_human_reviews' => count($reviewEvidence['rejected']),
                'missing_human_reviews' => count($missingReviews),
                'pending_media_rights_reviews' => count($pendingMediaRights),
            ],
            'collision_preflight' => [
                'status' => $automatedGatePassed ? 'pass' : 'fail',
                'unique_asset_keys' => count($assetsByKey),
                'unique_paths' => count($paths),
                'errors' => $errors,
            ],
            'editorial_integrity_gate' => [
                'status' => (string) ($editorialGate['status'] ?? 'fail_closed'),
                'automated_gate_passed' => ($editorialGate['automated_gate_passed'] ?? null) === true,
                'qa_row_count' => (int) ($editorialGate['qa_row_count'] ?? 0),
                'issue_count' => count($editorialIssues),
                'issues' => $editorialIssues,
                'validates' => [
                    'unsupported_claims',
                    'competitor_as_science',
                    'duplicate_or_template_content',
                    'mechanical_translation',
                    'visible_evidence_and_claim_boundaries',
                ],
            ],
            'discoverability_inventory' => [
                ...$discoverabilityCounts,
                'expected_each' => 116,
                'source' => self::BENCHMARK,
                'mutation_performed' => false,
            ],
            'asset_records' => $assetRecords,
            'pre_write_public_fingerprints' => array_values($fingerprints),
            'manual_review_records' => array_values($reviewEvidence['valid']),
            'missing_human_reviews' => $missingReviews,
            'rejected_human_review_asset_keys' => array_keys($reviewEvidence['rejected']),
            'media_manifest_records' => $mediaManifestRecords,
            'pending_media_rights_review_asset_keys' => $pendingMediaRights,
            'source_hashes' => $sourceHashes,
            'graph_manifest_sha256' => $this->hashFile($basePath, self::LINK_GRAPH),
            'media_manifest_sha256' => $this->hashFile($basePath, self::MEDIA_SPECIFICATIONS),
            'qa_sources' => $qaSources,
            'errors' => $errors,
            'release_boundary' => [
                'manual_review_requirement' => '116/116 named human review records bound to exact asset SHA256',
                'current_blockers' => $currentBlockers,
                'next_authority' => 'operator_supplied_human_review_evidence_then_separate_exact_sha_production_authorization',
                'production_command_executed' => false,
            ],
            'rollback_readiness' => [
                'complete' => true,
                'implementation' => EnneagramPublicAuthorityV206RevisionPromoter::class,
                'token_format' => 'base64url(json).hmac_sha256; payload version, artifact, target_count=116, preflight_fingerprint, and 116 rollback rows',
                'requires_exact_token_sha256_authorization' => true,
                'rollback_command_executed' => false,
            ],
            'exact_production_command_plan' => [
                'authorization_boundary' => 'PR23 separate exact backend SHA, package SHA256, and operator authorization required',
                'preflight' => 'php artisan personality:enneagram-authority-v2-revision-promoter --plan=<reviewed-116-target-plan.json> --preflight --json',
                'promote' => 'php artisan personality:enneagram-authority-v2-revision-promoter --plan=<reviewed-116-target-plan.json> --promote --confirm-preflight-fingerprint=<exact-preflight-sha256> --confirm-writer-deploy-sha=<exact-backend-git-sha> --operator-approved=<exact-dynamic-approval-phrase> --json',
                'rollback' => 'php artisan personality:enneagram-authority-v2-revision-promoter --rollback-token-file=<retained-token-file> --confirm-writer-deploy-sha=<exact-backend-git-sha> --operator-approved=<exact-dynamic-rollback-approval-phrase> --json',
                'executed' => false,
            ],
            'execution_boundaries' => array_fill_keys(self::EXECUTION_BOUNDARY_KEYS, false),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $assetRecords
     * @param  list<array{code:string,subject:string}>  $errors
     * @return array{valid:array<string,array<string,mixed>>,approved:array<string,array<string,mixed>>,rejected:array<string,array<string,mixed>>}
     */
    private function validateManualReviews(array $document, array $assetRecords, array &$errors): array
    {
        $assetHashes = [];
        foreach ($assetRecords as $record) {
            $assetHashes[$record['asset_key']] = $record['asset_sha256'];
        }

        $reviews = is_array($document['reviews'] ?? null) ? $document['reviews'] : [];
        $valid = [];
        $approved = [];
        $rejected = [];
        foreach ($reviews as $index => $review) {
            if (! is_array($review)) {
                $errors[] = $this->error('manual_review_not_object', 'review:'.$index);

                continue;
            }
            $key = (string) ($review['asset_key'] ?? '');
            if ($key === '' || ! isset($assetHashes[$key])) {
                $errors[] = $this->error('manual_review_asset_unknown', $key === '' ? 'review:'.$index : $key);

                continue;
            }
            if (isset($valid[$key])) {
                $errors[] = $this->error('duplicate_manual_review', $key);

                continue;
            }

            $reviewer = trim((string) ($review['reviewer'] ?? ''));
            $reviewedAt = (string) ($review['reviewed_at'] ?? '');
            $decision = (string) ($review['decision'] ?? '');
            if ($reviewer === ''
                || $reviewedAt === ''
                || strtotime($reviewedAt) === false
                || ($review['asset_sha256'] ?? null) !== $assetHashes[$key]
                || ! in_array($decision, ['approved', 'rejected'], true)) {
                $errors[] = $this->error('manual_review_invalid', $key);

                continue;
            }
            $valid[$key] = $review;
            if ($decision === 'approved') {
                $approved[$key] = $review;
            } else {
                $rejected[$key] = $review;
            }
        }

        ksort($valid);
        ksort($approved);
        ksort($rejected);

        return ['valid' => $valid, 'approved' => $approved, 'rejected' => $rejected];
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<array{code:string,subject:string}>  $errors
     * @return array<string, array<string, mixed>>
     */
    private function keyRows(array $rows, string $source, array &$errors): array
    {
        $keyed = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || ($key = $this->assetKey($row)) === null) {
                $errors[] = $this->error($source.'_identity_missing', $source.':'.$index);

                continue;
            }
            if (isset($keyed[$key])) {
                $errors[] = $this->error('duplicate_'.$source.'_key', $key);

                continue;
            }
            $keyed[$key] = $row;
        }

        return $keyed;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @param  list<array{code:string,subject:string}>  $errors
     */
    private function compareKeySets(array $left, array $right, string $code, array &$errors): void
    {
        sort($left);
        sort($right);
        if ($left !== $right) {
            $errors[] = $this->error($code, 'aggregate');
        }
    }

    /** @param array<string, mixed> $row */
    private function assetKey(array $row): ?string
    {
        $locale = trim((string) ($row['locale'] ?? ''));
        $identity = trim((string) ($row['identity_key'] ?? ''));

        return $locale !== '' && $identity !== '' ? $locale.'|'.$identity : null;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $basePath, string $relativePath): array
    {
        $resolved = str_starts_with($relativePath, '/') ? $relativePath : rtrim($basePath, '/').'/'.$relativePath;
        if (! is_file($resolved)) {
            throw new RuntimeException('Release-gate input not found: '.$resolved);
        }
        try {
            $decoded = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Release-gate input is not valid JSON: '.$resolved, 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Release-gate input must be a JSON object: '.$resolved);
        }

        return $decoded;
    }

    private function hashFile(string $basePath, string $relativePath): string
    {
        $resolved = str_starts_with($relativePath, '/') ? $relativePath : rtrim($basePath, '/').'/'.$relativePath;
        $hash = hash_file('sha256', $resolved);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash release-gate input: '.$resolved);
        }

        return $hash;
    }

    private function hashValue(mixed $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalizeForHash($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to hash release-gate value.', 0, $exception);
        }
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
    }

    private function isPrivatePath(string $path): bool
    {
        return preg_match('~/(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:/|$)~i', $path) === 1;
    }

    /** @param array<string, mixed> $value @return list<string> */
    private function hiddenSchemaPaths(array $value, string $prefix = ''): array
    {
        $paths = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, self::HIDDEN_SCHEMA_KEYS, true)) {
                $paths[] = $path;
            }
            if (is_array($child)) {
                $paths = array_merge($paths, $this->hiddenSchemaPaths($child, $path));
            }
        }

        return $paths;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    private function enrichEditorialIssues(array $issues, array $assets): array
    {
        foreach ($issues as &$issue) {
            $message = (string) ($issue['message'] ?? '');
            if (preg_match('/duplicates assets\.(\d+)\./', $message, $matches) === 1) {
                $duplicateIndex = (int) $matches[1];
                if (isset($assets[$duplicateIndex])) {
                    $issue['duplicate_of_asset_key'] = $this->assetKey($assets[$duplicateIndex]);
                }
            }
        }
        unset($issue);

        return $issues;
    }

    /** @return array{code:string,subject:string} */
    private function error(string $code, string $subject): array
    {
        return ['code' => $code, 'subject' => $subject];
    }
}
