<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Personality\AuthorityV2\PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter;
use RuntimeException;

final class EnneagramPublicAuthorityV205RevisionWorkspaceWriter
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-REVISION-WORKSPACE-05';

    public const TARGET_COUNT = 116;

    public const SOURCE_PACKAGE = 'enneagram-public-authority-v2-release-gate-22';

    private const MANUAL_REVIEW_REGISTER = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/manual-review-register.json';

    private const PAGE_MAPS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json';

    private const SOURCE_REGISTRY = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/source-registry.json';

    private const LINK_GRAPH = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-link-graph-20/link-graph.json';

    public function __construct(
        private readonly EnneagramPublicAuthorityV2IntegrityGate $integrityGate,
        private readonly PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter $writer,
    ) {}

    /** @param array<string, mixed> $releaseReport @return array<string, mixed> */
    public function preflight(array $releaseReport): array
    {
        $descriptors = $this->descriptors($releaseReport);
        $packageSha256 = $this->releasePackageSha256($releaseReport);
        $plan = $this->writer->preflight(
            PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            $packageSha256,
            $descriptors,
        );
        $this->assertTargetCount($plan);

        return [
            ...$plan,
            'artifact' => self::ARTIFACT,
            'source_page_count' => self::TARGET_COUNT,
            'source_release_artifact' => EnneagramPublicAuthorityV222ReleaseGate::ARTIFACT,
            'candidate_snapshot_count' => self::TARGET_COUNT,
            'pending_manual_review_count' => self::TARGET_COUNT,
            'empty_media_authority_count' => self::TARGET_COUNT,
            'media_write_count' => 0,
            'production_command_executed' => false,
            'database_migration_required' => true,
        ];
    }

    /** @param array<string, mixed> $releaseReport @return array<string, mixed> */
    public function write(
        array $releaseReport,
        string $expectedPackageSha256,
        string $expectedPreflightFingerprint,
    ): array {
        $descriptors = $this->descriptors($releaseReport);
        $packageSha256 = $this->releasePackageSha256($releaseReport);
        if (! hash_equals($packageSha256, $expectedPackageSha256)) {
            throw new RuntimeException('Enneagram release package SHA-256 changed before working import.');
        }

        $result = $this->writer->write(
            PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            $packageSha256,
            $descriptors,
            self::TARGET_COUNT,
            $expectedPreflightFingerprint,
        );

        return [
            ...$result,
            'artifact' => self::ARTIFACT,
            'source_page_count' => self::TARGET_COUNT,
            'candidate_snapshot_count' => self::TARGET_COUNT,
            'pending_manual_review_count' => self::TARGET_COUNT,
            'empty_media_authority_count' => self::TARGET_COUNT,
            'media_write_count' => 0,
            'database_migration_required' => true,
        ];
    }

    public function approvalPhrase(string $deploySha, string $packageSha256, string $preflightFingerprint): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1) {
            throw new RuntimeException('Deploy SHA must be an exact lowercase 40-character Git SHA.');
        }
        foreach (['package SHA-256' => $packageSha256, 'preflight fingerprint' => $preflightFingerprint] as $label => $value) {
            if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
                throw new RuntimeException(ucfirst($label).' must be an exact lowercase SHA-256.');
            }
        }

        return sprintf(
            'AUTHORIZE ENNEAGRAM AUTHORITY V2 EXACT CANDIDATE WORKING IMPORT FOR DEPLOY_SHA=%s PACKAGE_SHA256=%s PREFLIGHT_FINGERPRINT=%s TARGET_COUNT=116 WORKFLOW_STATE=pending_manual_review MEDIA_WRITE_COUNT=0 PRIMARY_CONTENT_OVERWRITE=0 PUBLISHED_POINTER_UPDATE=0; ABORT_ON_ANY_MISMATCH',
            $deploySha,
            $packageSha256,
            $preflightFingerprint,
        );
    }

    /**
     * @param  array<string, mixed>  $releaseReport
     * @return list<array<string, mixed>>
     */
    private function descriptors(array $releaseReport): array
    {
        $this->assertFinalReleaseReport($releaseReport);

        $pageMaps = $this->rowsByAssetKey($this->loadJson(self::PAGE_MAPS), 'page_maps');
        $graphRecords = $this->rowsByAssetKey($this->loadJson(self::LINK_GRAPH), 'graph_records');
        $registry = $this->loadJson(self::SOURCE_REGISTRY);
        $claims = $this->rowsById($registry, 'claims');
        $sources = $this->rowsById($registry, 'sources');
        $sourceDocuments = [];
        $descriptors = [];

        foreach (array_values($releaseReport['asset_records']) as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Enneagram final release asset record must be an object.');
            }
            $releaseAssetKey = (string) ($record['asset_key'] ?? '');
            $sourcePath = (string) ($record['source_path'] ?? '');
            $expectedAssetSha = strtolower((string) ($record['asset_sha256'] ?? ''));
            if ($releaseAssetKey === '' || $sourcePath === '' || preg_match('/^[0-9a-f]{64}$/', $expectedAssetSha) !== 1) {
                throw new RuntimeException('Enneagram final release asset provenance is incomplete: '.$releaseAssetKey.'.');
            }
            if (! isset($sourceDocuments[$sourcePath])) {
                $sourceDocuments[$sourcePath] = $this->loadJson($sourcePath);
            }
            $candidate = $this->candidateAsset($sourceDocuments[$sourcePath], $releaseAssetKey);
            if (! hash_equals($expectedAssetSha, $this->fingerprint($candidate))) {
                throw new RuntimeException('Enneagram candidate asset SHA-256 drifted: '.$releaseAssetKey.'.');
            }
            $pageMap = $pageMaps[$releaseAssetKey] ?? null;
            $graph = $graphRecords[$releaseAssetKey] ?? null;
            if (! is_array($pageMap) || ! is_array($graph)) {
                throw new RuntimeException('Enneagram package/ledger mapping is missing: '.$releaseAssetKey.'.');
            }
            if (($record['path'] ?? null) !== ($candidate['path'] ?? null)
                || ($record['path'] ?? null) !== ($pageMap['path'] ?? null)
                || ($record['path'] ?? null) !== ($graph['path'] ?? null)) {
                throw new RuntimeException('Enneagram candidate route mapping drifted: '.$releaseAssetKey.'.');
            }

            $identity = [
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                'entity_type' => (string) $record['entity_type'],
                'entity_key' => (string) $record['code'],
                'locale' => (string) $record['locale'],
            ];
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where($identity)
                ->first();
            if (! $asset instanceof PersonalityPublicContentAsset) {
                throw new RuntimeException('Published Enneagram authority target is missing: '.$releaseAssetKey.'.');
            }

            $assetKey = implode(':', [
                PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                (string) $record['entity_type'],
                (string) $record['code'],
                (string) $record['locale'],
            ]);
            $snapshot = $this->snapshot($asset, $candidate, $pageMap, $graph, $claims, $sources, $expectedAssetSha);
            $descriptors[] = [
                'asset_key' => $assetKey,
                'identity' => $identity,
                'source_package' => self::SOURCE_PACKAGE,
                'source_hash' => $expectedAssetSha,
                'workflow_state' => EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW,
                'snapshot' => $snapshot,
                'target_descriptor' => [
                    'release_asset_key' => $releaseAssetKey,
                    'path' => (string) $record['path'],
                    'source_path' => $sourcePath,
                    'asset_sha256' => $expectedAssetSha,
                ],
                'expected_attributes' => [
                    'canonical_json.path' => (string) $record['path'],
                ],
            ];
        }

        usort($descriptors, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        if (count($descriptors) !== self::TARGET_COUNT
            || count(array_unique(array_column($descriptors, 'asset_key'))) !== self::TARGET_COUNT
            || count(array_unique(array_column($descriptors, 'source_hash'))) !== self::TARGET_COUNT) {
            throw new RuntimeException('Enneagram working import requires exactly 116 unique candidate descriptors and asset SHAs.');
        }

        return $descriptors;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $pageMap
     * @param  array<string, mixed>  $graph
     * @param  array<string, array<string, mixed>>  $claims
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function snapshot(
        PersonalityPublicContentAsset $asset,
        array $candidate,
        array $pageMap,
        array $graph,
        array $claims,
        array $sources,
        string $assetSha256,
    ): array {
        $snapshot = [];
        foreach ($asset->getFillable() as $field) {
            $snapshot[$field] = $asset->getAttribute($field);
        }

        $claimIds = array_values(array_filter(
            is_array($pageMap['factual_claim_ids'] ?? null) ? $pageMap['factual_claim_ids'] : [],
            'is_string',
        ));
        $claimMapping = [];
        $sourceClaimIds = [];
        foreach ($claimIds as $claimId) {
            $claim = $claims[$claimId] ?? null;
            if (! is_array($claim)) {
                throw new RuntimeException('Enneagram factual claim is missing from the source ledger: '.$claimId.'.');
            }
            $sourceIds = array_values(array_filter(
                is_array($claim['source_ids'] ?? null) ? $claim['source_ids'] : [],
                fn (mixed $sourceId): bool => is_string($sourceId) && isset($sources[$sourceId]),
            ));
            if ($sourceIds === []) {
                throw new RuntimeException('Enneagram factual claim has no public source mapping: '.$claimId.'.');
            }
            $claimMapping[] = [
                'claim_id' => $claimId,
                'source_ids' => $sourceIds,
                'limitation' => (string) ($claim['limitation'] ?? ''),
            ];
            foreach ($sourceIds as $sourceId) {
                $sourceClaimIds[$sourceId][] = $claimId;
            }
        }

        $publicSources = [];
        ksort($sourceClaimIds);
        foreach ($sourceClaimIds as $sourceId => $mappedClaimIds) {
            $source = $sources[$sourceId];
            $publicSources[] = [
                'id' => $sourceId,
                'title' => (string) ($source['title'] ?? ''),
                'author_or_organization' => (string) ($source['authors_or_organization'] ?? ''),
                'year' => (int) ($source['year'] ?? 0),
                'source_type' => $this->sourceType((string) ($source['category'] ?? '')),
                'doi' => $source['doi'] ?? null,
                'public_url' => $source['public_url'] ?? null,
                'accessed_at' => $source['accessed_at'] ?? null,
                'claim_ids' => array_values(array_unique($mappedClaimIds)),
                'limitation' => $source['limitation'] ?? null,
            ];
        }

        $sections = [];
        foreach (is_array($candidate['sections'] ?? null) ? $candidate['sections'] : [] as $section) {
            if (! is_array($section)) {
                throw new RuntimeException('Enneagram candidate section must be an object.');
            }
            $sections[] = [
                'key' => (string) ($section['kind'] ?? ''),
                'title' => (string) ($section['heading'] ?? ''),
                'body_md' => (string) ($section['body'] ?? ''),
            ];
        }
        $exercise = is_array($candidate['observation_exercise'] ?? null) ? $candidate['observation_exercise'] : [];
        $sections[] = [
            'key' => 'observation_exercise',
            'title' => (string) $candidate['locale'] === 'zh-CN' ? '观察练习' : 'Observation exercise',
            'body_md' => $this->exerciseBody($exercise, (string) $candidate['locale']),
            'observation_exercise' => $exercise,
        ];

        return [
            ...$snapshot,
            'title' => (string) $candidate['title'],
            'summary' => (string) $candidate['answer_first'],
            'content_sections_json' => $sections,
            'seo_json' => [
                'title' => (string) $candidate['title'],
                'description' => (string) $candidate['answer_first'],
            ],
            'canonical_json' => $graph['canonical'],
            'hreflang_json' => $graph['hreflang'],
            'faq_json' => array_values(is_array($candidate['faqs'] ?? null) ? $candidate['faqs'] : []),
            'schema_json' => [],
            'method_boundary_json' => [
                'claim_ids' => array_values(is_array($pageMap['claim_ids'] ?? null) ? $pageMap['claim_ids'] : []),
                'factual_claim_ids' => $claimIds,
                'blocked_claim_ids' => array_values(is_array($pageMap['blocked_claim_ids'] ?? null) ? $pageMap['blocked_claim_ids'] : []),
                'evidence_status' => (string) ($pageMap['evidence_status'] ?? ''),
                'limitations' => array_values(is_array($pageMap['limitations'] ?? null) ? $pageMap['limitations'] : []),
            ],
            'evidence_notes_json' => [
                'claim_mapping' => $claimMapping,
                'sources' => $publicSources,
                'limitations' => array_values(is_array($pageMap['limitations'] ?? null) ? $pageMap['limitations'] : []),
            ],
            'authority_json' => [
                'sources' => $publicSources,
                'claim_mapping' => $claimMapping,
                'limitations' => array_values(is_array($pageMap['limitations'] ?? null) ? $pageMap['limitations'] : []),
                'author' => null,
                'reviewer' => null,
                'visible_evidence_eligible' => true,
                'schema_eligible' => true,
            ],
            'internal_links_json' => $this->internalLinks(
                is_array($graph['internal_links'] ?? null) ? $graph['internal_links'] : [],
            ),
            'review_state' => EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW,
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => self::SOURCE_PACKAGE,
            'source_hash' => $assetSha256,
        ];
    }

    /** @param array<string, mixed> $releaseReport */
    private function assertFinalReleaseReport(array $releaseReport): void
    {
        if (($releaseReport['artifact'] ?? null) !== EnneagramPublicAuthorityV222ReleaseGate::ARTIFACT
            || ($releaseReport['schema_version'] ?? null) !== 'enneagram_public_authority_v2_release_gate.v2'
            || ! is_array($releaseReport['asset_records'] ?? null)
            || count($releaseReport['asset_records']) !== self::TARGET_COUNT
            || ($releaseReport['automated_gate_passed'] ?? null) !== true
            || ($releaseReport['media_boundary_passed'] ?? null) !== true
            || (int) data_get($releaseReport, 'counts.empty_media_authority_count', 0) !== self::TARGET_COUNT
            || (int) data_get($releaseReport, 'counts.media_write_count', -1) !== 0) {
            throw new RuntimeException('Enneagram working import requires the final automated-pass empty-media release report.');
        }

        $current = (new EnneagramPublicAuthorityV222ReleaseGate($this->integrityGate))
            ->evaluate(base_path(), self::MANUAL_REVIEW_REGISTER);
        // The reviewed release report keeps its original package SHA and empty-media
        // audit envelope. Runtime revalidation instead proves that the same source
        // records now satisfy the permanent field-absence policy.
        foreach (['asset_records', 'source_hashes'] as $field) {
            if ($this->fingerprint($current[$field] ?? null) !== $this->fingerprint($releaseReport[$field] ?? null)) {
                throw new RuntimeException('Enneagram final release report drifted from current package inputs: '.$field.'.');
            }
        }
        if (($current['automated_gate_passed'] ?? null) !== true || ($current['media_boundary_passed'] ?? null) !== true) {
            throw new RuntimeException('Current Enneagram release package no longer passes automated and media-boundary gates.');
        }
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function candidateAsset(array $document, string $assetKey): array
    {
        foreach (is_array($document['assets'] ?? null) ? $document['assets'] : [] as $candidate) {
            if (is_array($candidate) && $this->releaseAssetKey($candidate) === $assetKey) {
                return $candidate;
            }
        }

        throw new RuntimeException('Enneagram candidate asset is missing from its declared source: '.$assetKey.'.');
    }

    /** @param array<string, mixed> $document @return array<string, array<string, mixed>> */
    private function rowsByAssetKey(array $document, string $field): array
    {
        $keyed = [];
        foreach (is_array($document[$field] ?? null) ? $document[$field] : [] as $row) {
            if (! is_array($row) || ($key = $this->releaseAssetKey($row)) === '' || isset($keyed[$key])) {
                throw new RuntimeException('Enneagram '.$field.' contains an invalid or duplicate asset identity.');
            }
            $keyed[$key] = $row;
        }

        return $keyed;
    }

    /** @param array<string, mixed> $document @return array<string, array<string, mixed>> */
    private function rowsById(array $document, string $field): array
    {
        $keyed = [];
        foreach (is_array($document[$field] ?? null) ? $document[$field] : [] as $row) {
            $id = is_array($row) ? trim((string) ($row['id'] ?? '')) : '';
            if ($id === '' || isset($keyed[$id])) {
                throw new RuntimeException('Enneagram '.$field.' contains an invalid or duplicate id.');
            }
            $keyed[$id] = $row;
        }

        return $keyed;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $relativePath): array
    {
        $resolved = str_starts_with($relativePath, DIRECTORY_SEPARATOR) ? $relativePath : base_path($relativePath);
        if (! is_file($resolved)) {
            throw new RuntimeException('Enneagram package input not found: '.$relativePath.'.');
        }
        $decoded = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Enneagram package input must be a JSON object: '.$relativePath.'.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $exercise */
    private function exerciseBody(array $exercise, string $locale): string
    {
        $labels = $locale === 'zh-CN'
            ? ['context' => '情境', 'observable_signal' => '可观察信号', 'page_specific_signal' => '本页特定信号', 'alternative_explanation' => '替代解释', 'reflection_prompt' => '复盘问题']
            : ['context' => 'Context', 'observable_signal' => 'Observable signal', 'page_specific_signal' => 'Page-specific signal', 'alternative_explanation' => 'Alternative explanation', 'reflection_prompt' => 'Reflection prompt'];
        $lines = [($locale === 'zh-CN' ? '持续天数' : 'Duration').': '.(int) ($exercise['duration_days'] ?? 0)];
        foreach ($labels as $field => $label) {
            $lines[] = $label.': '.trim((string) ($exercise[$field] ?? ''));
        }

        return implode("\n\n", $lines);
    }

    private function sourceType(string $category): string
    {
        return match ($category) {
            'systematic_review', 'primary_empirical', 'measurement_documentation' => 'peer_reviewed_research',
            'traditional_theory' => 'book',
            'dataset' => 'dataset',
            'editorial_synthesis' => 'official_documentation',
            default => 'other_public_source',
        };
    }

    /** @param list<mixed> $links @return list<array<string, mixed>> */
    private function internalLinks(array $links): array
    {
        $mapped = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                throw new RuntimeException('Enneagram link-graph internal link must be an object.');
            }
            $href = trim((string) ($link['target_path'] ?? ''));
            $identity = trim((string) ($link['target_identity_key'] ?? ''));
            if ($href === '' || $identity === '') {
                throw new RuntimeException('Enneagram link-graph internal link is missing its target.');
            }
            $mapped[] = [
                'label' => $identity,
                'href' => $href,
                'relationship' => (string) ($link['relationship'] ?? ''),
                'target_identity_key' => $identity,
            ];
        }

        return $mapped;
    }

    /** @param array<string, mixed> $row */
    private function releaseAssetKey(array $row): string
    {
        $locale = trim((string) ($row['locale'] ?? ''));
        $identity = trim((string) ($row['identity_key'] ?? ''));

        return $locale !== '' && $identity !== '' ? $locale.'|'.$identity : '';
    }

    /** @param array<string, mixed> $releaseReport */
    private function releasePackageSha256(array $releaseReport): string
    {
        $packageSha256 = strtolower(trim((string) ($releaseReport['package_sha256'] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1) {
            throw new RuntimeException('Enneagram final release package SHA-256 is invalid.');
        }

        return $packageSha256;
    }

    /** @param array<string, mixed> $plan */
    private function assertTargetCount(array $plan): void
    {
        if ((int) ($plan['target_count'] ?? 0) !== self::TARGET_COUNT) {
            throw new RuntimeException('Enneagram working import preflight did not resolve exactly 116 targets.');
        }
    }

    private function fingerprint(mixed $value): string
    {
        $value = $this->normalizeForHash($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $child): mixed => $this->normalizeForHash($child), $value);
    }
}
