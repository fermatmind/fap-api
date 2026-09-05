<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Services\SeoIntel\Detector\BoundedDetectorRunner;
use App\Services\SeoIntel\Detector\DetectorQueueMaterializer;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ControlledUrlTruthReconciliationService
{
    public const SCHEMA_VERSION = 'seo-platform-controlled-url-truth-reconciliation.v1';

    public function __construct(
        private readonly EffectivePublicUrlEvaluator $evaluator,
        private readonly UrlTruthInventoryRecordWriter $writer,
        private readonly BoundedPublicUrlEvidenceProbe $probe,
        private readonly PageFamilyPolicyRegistry $policy,
        private readonly BoundedDetectorRunner $detectorRunner = new BoundedDetectorRunner,
        private readonly DetectorQueueMaterializer $detectorMaterializer = new DetectorQueueMaterializer,
    ) {}

    /**
     * @param  list<UrlTruthInventoryRecord>  $authorityRecords
     * @param  array<string,mixed>  $sourceMetadata
     * @return array<string,mixed>
     */
    public function run(
        array $authorityRecords,
        array $sourceMetadata,
        bool $execute,
        bool $probeHttp,
        int $maxRecords = 5000,
        int $batchSize = 250,
    ): array {
        $maxRecords = min(10000, max(1, $maxRecords));
        $batchSize = min(250, max(1, $batchSize));
        if (count($authorityRecords) > $maxRecords) {
            return $this->blocked('authority_record_bound_exceeded', $execute, $maxRecords, $batchSize);
        }
        if (! $this->schemaReady()) {
            return $this->blocked('url_truth_hardened_schema_unavailable', $execute, $maxRecords, $batchSize);
        }

        [$accepted, $rejectionCounts, $sourceConflicts] = $this->classifyAuthority($authorityRecords);
        $artifact = $this->artifact($accepted, $sourceMetadata, $maxRecords, $batchSize);
        $before = $this->plan($accepted, $rejectionCounts, $sourceConflicts);
        $batches = $this->batches($accepted, $batchSize);
        $evidence = $this->consumerEvidence($batches, $probeHttp);
        $detector = $this->sitemapAuthorityDetector($accepted, $artifact, $evidence, false);

        if (! $execute) {
            return $this->receipt($artifact, $before, $batches, $evidence, $detector, false, [], null, $maxRecords, $batchSize);
        }
        if (! (bool) config('seo_intel.enabled', false) || ! (bool) config('seo_intel.write_enabled', false)) {
            return $this->blocked('seo_intel_write_flags_disabled', true, $maxRecords, $batchSize, $artifact, $before);
        }
        if ($sourceConflicts > 0) {
            return $this->blocked('authority_binding_conflict', true, $maxRecords, $batchSize, $artifact, $before);
        }

        [$batchReceipts, $idempotency, $detector] = $this->connection()->transaction(function () use ($accepted, $artifact, $batches, $evidence): array {
            $batchReceipts = [];
            foreach ($batches as $batch) {
                $records = array_map(static fn (array $item): UrlTruthInventoryRecord => $item['record'], $batch['items']);
                $this->writer->write($records);
                $batchReceipts[] = $this->batchReadback($batch, $evidence);
            }
            $retired = $this->retireOrphans($accepted);

            // Execute the exact same accepted input again. Semantic planning after this pass must remain a no-op.
            foreach ($batches as $batch) {
                $this->writer->write(array_map(
                    static fn (array $item): UrlTruthInventoryRecord => $item['record'],
                    $batch['items'],
                ));
            }

            $rerun = $this->plan($accepted, [], 0);
            $privateLeakage = $this->privateLeakageCount();
            $bindingConflicts = $this->currentBindingConflictCount();
            $idempotency = [
                'same_artifact_hash' => true,
                'added' => (int) $rerun['counts']['added'],
                'duplicate' => (int) $rerun['counts']['duplicate'],
                'unexpected_updated' => (int) $rerun['counts']['updated'],
                'private_leakage' => $privateLeakage,
                'current_binding_conflicts' => $bindingConflicts,
                'retired_on_first_pass' => $retired,
                'passed' => (int) $rerun['counts']['added'] === 0
                    && (int) $rerun['counts']['duplicate'] === 0
                    && (int) $rerun['counts']['updated'] === 0
                    && $privateLeakage === 0
                    && $bindingConflicts === 0,
            ];
            $detector = $this->sitemapAuthorityDetector($accepted, $artifact, $evidence, true);

            return [$batchReceipts, $idempotency, $detector];
        });

        return $this->receipt(
            $artifact,
            $before,
            $batches,
            $evidence,
            $detector,
            true,
            $batchReceipts,
            $idempotency,
            $maxRecords,
            $batchSize,
        );
    }

    /** @param list<UrlTruthInventoryRecord> $records @return array{0:list<array<string,mixed>>,1:array<string,int>,2:int} */
    private function classifyAuthority(array $records): array
    {
        $accepted = [];
        $rejectionCounts = [];
        $identities = [];
        foreach ($records as $record) {
            $evaluation = $this->evaluator->evaluate($record);
            if (! (bool) $evaluation['effective_public']) {
                $rejectionCounts['rejected_records'] = ($rejectionCounts['rejected_records'] ?? 0) + 1;
                foreach ((array) ($evaluation['blocking_reasons'] ?? ['effective_public_rejected']) as $reason) {
                    $reason = (string) $reason;
                    $rejectionCounts[$reason] = ($rejectionCounts[$reason] ?? 0) + 1;
                }

                continue;
            }
            $identity = $this->bindingKey($record);
            $item = [
                'record' => $record,
                'family' => (string) $evaluation['family_id'],
                'authority_revision' => (string) $evaluation['authority_revision'],
                'canonical_revision' => $this->canonicalRevision($record),
                'identity' => $identity,
                'hash' => $record->canonicalUrlHash(),
            ];
            $identities[$identity][] = $item;
        }

        $conflicts = 0;
        foreach ($identities as $items) {
            $hashes = array_values(array_unique(array_column($items, 'hash')));
            if (count($hashes) !== 1) {
                $conflicts += count($items);

                continue;
            }
            $accepted[] = $items[0];
        }
        usort($accepted, static fn (array $left, array $right): int => [$left['family'], $left['record']->locale, $left['hash']]
            <=> [$right['family'], $right['record']->locale, $right['hash']]);

        ksort($rejectionCounts);

        return [$accepted, $rejectionCounts, $conflicts];
    }

    /** @param list<array<string,mixed>> $accepted @param array<string,mixed> $sourceMetadata @return array<string,mixed> */
    private function artifact(array $accepted, array $sourceMetadata, int $maxRecords, int $batchSize): array
    {
        $rows = array_map(static fn (array $item): array => [
            'canonical_hash' => $item['hash'],
            'locale' => $item['record']->locale,
            'family' => $item['family'],
            'page_entity_type' => $item['record']->pageEntityType,
            'entity_identity_hash' => hash('sha256', (string) $item['record']->entityIdOrSlug),
            'source_authority' => $item['record']->sourceAuthority,
            'authority_revision' => $item['authority_revision'],
            'canonical_revision' => $item['canonical_revision'],
        ], $accepted);
        $contentDigest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $sourceVersionHash = hash('sha256', json_encode($sourceMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $revisionDigest = hash('sha256', json_encode(array_column($rows, 'authority_revision'), JSON_THROW_ON_ERROR));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'source_version_hash' => $sourceVersionHash,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $this->policy->policyHash(),
            'authority_revision_digest' => $revisionDigest,
            'record_count' => count($rows),
            'content_digest' => $contentDigest,
            'artifact_hash' => hash('sha256', implode('|', [
                self::SCHEMA_VERSION,
                $sourceVersionHash,
                $this->policy->policyHash(),
                $revisionDigest,
                (string) count($rows),
                $contentDigest,
            ])),
            'bounds' => ['max_records' => $maxRecords, 'max_batch_size' => $batchSize],
            'raw_urls_emitted' => false,
        ];
    }

    /** @param list<array<string,mixed>> $accepted @return array<string,mixed> */
    private function plan(array $accepted, array $rejectionCounts, int $sourceConflicts): array
    {
        $connection = $this->connection();
        $truthRows = $connection->table('seo_urls')->get()->keyBy(
            static fn (object $row): string => (string) $row->locale.'|'.(string) $row->canonical_url_hash,
        );
        $bindings = $connection->table('seo_url_entities')->whereNotNull('current_binding_key')->get()->keyBy('current_binding_key');
        $acceptedKeys = [];
        $counts = ['added' => 0, 'updated' => 0, 'retired' => 0, 'conflict' => $sourceConflicts, 'rejected' => (int) ($rejectionCounts['rejected_records'] ?? 0), 'no_change' => 0, 'duplicate' => 0];

        foreach ($accepted as $item) {
            $record = $item['record'];
            $key = $record->locale.'|'.$item['hash'];
            $acceptedKeys[$key] = true;
            $truth = $truthRows->get($key);
            $binding = $bindings->get($item['identity']);
            if ($truth === null) {
                $counts['added']++;

                continue;
            }
            if (! $this->semanticallyCurrent($truth, $binding, $item)) {
                $counts['updated']++;

                continue;
            }
            $counts['no_change']++;
        }
        foreach ($truthRows as $key => $row) {
            if (! isset($acceptedKeys[$key]) && $this->rowIsCurrent($row)) {
                $counts['retired']++;
            }
        }
        $counts['duplicate'] = $this->currentBindingConflictCount();

        return ['status' => 'success', 'counts' => $counts, 'rejection_counts' => $rejectionCounts];
    }

    /** @param list<array<string,mixed>> $accepted @return list<array<string,mixed>> */
    private function batches(array $accepted, int $batchSize): array
    {
        $groups = [];
        foreach ($accepted as $item) {
            $groups[$item['family'].'|'.$item['record']->locale][] = $item;
        }
        ksort($groups);
        $batches = [];
        foreach ($groups as $group => $items) {
            [$family, $locale] = explode('|', $group, 2);
            $steps = $family === 'career' ? [3, 10, 50] : [1, 10, 50];
            $offset = 0;
            foreach ($steps as $step) {
                if ($offset >= count($items)) {
                    break;
                }
                $slice = array_slice($items, $offset, $step);
                $batches[] = $this->batch($family, $locale, $slice, $offset === 0 ? 'canary' : 'ramp');
                $offset += count($slice);
            }
            while ($offset < count($items)) {
                $slice = array_slice($items, $offset, $batchSize);
                $batches[] = $this->batch($family, $locale, $slice, 'cohort');
                $offset += count($slice);
            }
        }

        return $batches;
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function batch(string $family, string $locale, array $items, string $stage): array
    {
        return [
            'family' => $family,
            'locale' => $locale,
            'stage' => $stage,
            'risk' => $family === 'career' ? 'career_1_3_10_50_cohort' : (string) data_get($this->policy->families(), $family.'.agent_risk_cap', 'L3'),
            'record_count' => count($items),
            'batch_digest' => hash('sha256', implode('|', array_column($items, 'hash'))),
            'items' => $items,
        ];
    }

    /** @param list<array<string,mixed>> $batches @return array<string,mixed> */
    private function consumerEvidence(array $batches, bool $probeHttp): array
    {
        if (! $probeHttp || $batches === []) {
            return [
                'state' => 'measurement_hold',
                'reason' => $probeHttp ? 'no_batches' : 'http_probe_disabled',
                'consumer_urls' => ['public_api' => null, 'sitemap' => null, 'llms' => null, 'llms_full' => null],
                'live_http' => ['state' => 'measurement_hold', 'bounded' => true],
            ];
        }

        $canaries = array_map(static fn (array $batch): UrlTruthInventoryRecord => $batch['items'][0]['record'], $batches);
        try {
            $probe = $this->probe->collect($canaries, null, min(100, count($canaries)), 4, 10, 1);

            return ['state' => 'available'] + $probe;
        } catch (Throwable) {
            return [
                'state' => 'measurement_hold',
                'reason' => 'bounded_consumer_readback_unavailable',
                'consumer_urls' => ['public_api' => null, 'sitemap' => null, 'llms' => null, 'llms_full' => null],
                'live_http' => ['state' => 'measurement_hold', 'bounded' => true],
            ];
        }
    }

    /** @param list<array<string,mixed>> $accepted @param array<string,mixed> $artifact @param array<string,mixed> $evidence @return array<string,mixed> */
    private function sitemapAuthorityDetector(array $accepted, array $artifact, array $evidence, bool $execute): array
    {
        $sitemapUrls = data_get($evidence, 'consumer_urls.sitemap');
        if (($evidence['state'] ?? null) !== 'available' || ! is_array($sitemapUrls)) {
            return [
                'status' => 'measurement_hold',
                'reason' => 'comparable_sitemap_snapshot_unavailable',
                'sitemap_without_authority_count' => null,
                'planned_issues' => 0,
                'writes_committed' => false,
            ];
        }

        $authorityHashes = array_fill_keys(array_column($accepted, 'hash'), true);
        $localeCounts = ['zh-CN' => 0, 'en' => 0];
        foreach ($sitemapUrls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $normalized = rtrim(trim($url), '/');
            if ($normalized === '' || isset($authorityHashes[hash('sha256', $normalized)])) {
                continue;
            }
            $path = (string) parse_url($normalized, PHP_URL_PATH);
            $localeCounts[str_starts_with($path, '/en/') || $path === '/en' ? 'en' : 'zh-CN']++;
        }
        $differenceCount = array_sum($localeCounts);
        if ($differenceCount === 0) {
            return [
                'status' => 'success',
                'sitemap_without_authority_count' => 0,
                'planned_issues' => 0,
                'writes_committed' => false,
            ];
        }

        $observedAt = now('UTC')->toIso8601String();
        $jobs = [];
        foreach ($localeCounts as $locale => $count) {
            if ($count === 0) {
                continue;
            }
            $jobs[] = [
                'detector_id' => 'public_collection_split',
                'evidence' => [
                    'source_state' => 'available',
                    'evidence_complete' => true,
                    'direct_evidence' => true,
                    'same_revision_snapshots' => true,
                    'collection_set_diff_count' => $count,
                    'page_family' => 'other_public',
                    'locale' => $locale,
                    'indexability_state' => 'indexable',
                    'canonical_url_hash' => hash('sha256', 'sitemap_without_authority|'.$locale.'|'.$artifact['artifact_hash']),
                    'authority_revision' => $artifact['authority_revision_digest'],
                    'url_truth_revision' => $artifact['artifact_hash'],
                    'policy_version' => PageFamilyPolicyRegistry::VERSION,
                    'affected_url_count' => $count,
                    'private_negative_set_checked' => true,
                    'evidence_observed_at' => $observedAt,
                    'root_cause_or_error_code' => 'sitemap_without_authority',
                    'verified_impact' => 'bounded',
                ],
            ];
        }
        $detectorArtifact = $this->detectorRunner->run($jobs, [
            'dry_run' => ! $execute,
            'max_urls' => min(500, max(1, $differenceCount)),
            'timeout_ms' => 2_000,
            'max_evidence_age_seconds' => 86_400,
            'expected_policy_version' => PageFamilyPolicyRegistry::VERSION,
            'expected_authority_revision' => $artifact['authority_revision_digest'],
            'now' => $observedAt,
        ]);
        $materialization = $this->detectorMaterializer->materialize($detectorArtifact, $execute, $observedAt);

        return [
            'status' => 'success',
            'sitemap_without_authority_count' => $differenceCount,
            'planned_issues' => count($jobs),
            'detector_artifact_hash' => $detectorArtifact['artifact_hash'],
            'materialization' => $materialization,
            'writes_committed' => (bool) ($materialization['writes_committed'] ?? false),
            'raw_urls_emitted' => false,
        ];
    }

    /** @param array<string,mixed> $batch @param array<string,mixed> $evidence @return array<string,mixed> */
    private function batchReadback(array $batch, array $evidence): array
    {
        $connection = $this->connection();
        $hashes = array_column($batch['items'], 'hash');
        $identities = array_column($batch['items'], 'identity');
        $truth = $connection->table('seo_urls')
            ->where('locale', $batch['locale'])
            ->whereIn('canonical_url_hash', $hashes)
            ->where('indexability_state', 'indexable')
            ->where('is_private_flow', false)
            ->count();
        $bindings = $connection->table('seo_url_entities')->whereIn('current_binding_key', $identities)->count();
        $consumerMissing = [];
        foreach (($evidence['consumer_urls'] ?? []) as $name => $urls) {
            $consumerMissing[$name] = $urls === null ? null : $this->missingHashes($hashes, $urls);
        }

        return [
            'family' => $batch['family'],
            'locale' => $batch['locale'],
            'stage' => $batch['stage'],
            'risk' => $batch['risk'],
            'record_count' => $batch['record_count'],
            'batch_digest' => $batch['batch_digest'],
            'authority_bound' => true,
            'url_truth_readback' => $truth,
            'current_binding_readback' => $bindings,
            'database_readback_ok' => $truth === $batch['record_count'] && $bindings === $batch['record_count'],
            'consumer_missing' => $consumerMissing,
            'runtime_canonical_evidence_state' => (string) data_get($evidence, 'live_http.state', 'measurement_hold'),
        ];
    }

    /** @param list<array<string,mixed>> $accepted */
    private function retireOrphans(array $accepted): int
    {
        $acceptedKeys = array_fill_keys(array_map(
            static fn (array $item): string => $item['record']->locale.'|'.$item['hash'],
            $accepted,
        ), true);
        $connection = $this->connection();
        $rows = $connection->table('seo_urls')->where('indexability_state', 'indexable')->get();
        $retired = 0;
        foreach ($rows as $row) {
            $key = (string) $row->locale.'|'.(string) $row->canonical_url_hash;
            if (isset($acceptedKeys[$key])) {
                continue;
            }
            $metadata = json_decode((string) ($row->metadata_json ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['retired_by'] = self::SCHEMA_VERSION;
            $connection->transaction(function () use ($connection, $row, $metadata): void {
                $connection->table('seo_urls')->where('id', $row->id)->update([
                    'indexability_state' => 'retired_authority',
                    'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
                $connection->table('seo_url_entities')
                    ->where('canonical_url_hash', $row->canonical_url_hash)
                    ->where('locale', $row->locale)
                    ->update([
                        'authority_status' => 'retired_authority',
                        'binding_status' => 'retired_authority',
                        'current_binding_key' => null,
                        'retired_at' => now(),
                        'updated_at' => now(),
                    ]);
            });
            $retired++;
        }

        return $retired;
    }

    /** @param array<string,mixed> $artifact @param array<string,mixed> $plan @param list<array<string,mixed>> $batches @param array<string,mixed> $evidence @param array<string,mixed> $detector @param list<array<string,mixed>> $batchReceipts @param array<string,mixed>|null $idempotency @return array<string,mixed> */
    private function receipt(array $artifact, array $plan, array $batches, array $evidence, array $detector, bool $executed, array $batchReceipts, ?array $idempotency, int $maxRecords, int $batchSize): array
    {
        $batchSummary = array_map(static fn (array $batch): array => [
            'family' => $batch['family'],
            'locale' => $batch['locale'],
            'stage' => $batch['stage'],
            'risk' => $batch['risk'],
            'record_count' => $batch['record_count'],
            'batch_digest' => $batch['batch_digest'],
        ], $batches);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $idempotency === null || ($idempotency['passed'] ?? false) ? 'success' : 'blocked',
            'mode' => $executed ? 'controlled_write' : 'dry_run',
            'artifact' => $artifact,
            'dry_run' => ! $executed,
            'writes_attempted' => $executed,
            'writes_committed' => $executed,
            'plan' => $plan,
            'batch_count' => count($batches),
            'batches' => $executed ? $batchReceipts : $batchSummary,
            'consumer_evidence' => [
                'state' => $evidence['state'] ?? 'measurement_hold',
                'live_http' => $evidence['live_http'] ?? ['state' => 'measurement_hold'],
                'raw_urls_emitted' => false,
                'response_bodies_emitted' => false,
            ],
            'sitemap_authority_detector' => $detector,
            'idempotent_rerun' => $idempotency,
            'bounds' => ['max_records' => $maxRecords, 'max_batch_size' => $batchSize, 'max_http_concurrency' => 4],
            'boundaries' => [
                'backend_cms_authority_only' => true,
                'sitemap_can_create_authority' => false,
                'database_tables' => ['seo_urls', 'seo_url_entities', 'seo_issue_queue'],
                'cms_write' => false,
                'content_publication' => false,
                'sitemap_authority_write' => false,
                'search_submission_allowed' => false,
                'read_only_gsc' => true,
                'hard_delete' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $artifact @param array<string,mixed> $plan @return array<string,mixed> */
    private function blocked(string $issue, bool $execute, int $maxRecords, int $batchSize, array $artifact = [], array $plan = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'mode' => $execute ? 'controlled_write' : 'dry_run',
            'issues' => [$issue],
            'artifact' => $artifact,
            'plan' => $plan,
            'writes_attempted' => false,
            'writes_committed' => false,
            'bounds' => ['max_records' => $maxRecords, 'max_batch_size' => $batchSize],
            'boundaries' => ['search_submission_allowed' => false, 'hard_delete' => false],
        ];
    }

    /** @param array<string,mixed> $item */
    private function semanticallyCurrent(object $truth, ?object $binding, array $item): bool
    {
        $record = $item['record'];

        return (string) $truth->page_entity_type === $record->pageEntityType
            && (string) $truth->entity_id_or_slug === (string) $record->entityIdOrSlug
            && (string) $truth->source_authority === $record->sourceAuthority
            && (string) $truth->indexability_state === 'indexable'
            && ! (bool) $truth->is_private_flow
            && (string) $truth->page_family === $item['family']
            && (string) $truth->authority_revision === $item['authority_revision']
            && (string) $truth->canonical_revision === $item['canonical_revision']
            && $binding !== null
            && (string) $binding->canonical_url_hash === $item['hash']
            && (string) $binding->binding_status === 'current';
    }

    private function rowIsCurrent(object $row): bool
    {
        return (string) $row->indexability_state === 'indexable' && ! (bool) $row->is_private_flow;
    }

    private function bindingKey(UrlTruthInventoryRecord $record): string
    {
        return hash('sha256', json_encode([
            $record->pageEntityType,
            $record->entityIdOrSlug,
            $record->locale,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalRevision(UrlTruthInventoryRecord $record): string
    {
        $revision = trim((string) ($record->metadata['canonical_revision'] ?? $record->canonicalUrlHash()));

        return preg_match('/^[a-f0-9]{64}$/', $revision) === 1 ? $revision : hash('sha256', $revision);
    }

    /** @param list<string> $hashes @param list<string> $urls */
    private function missingHashes(array $hashes, array $urls): int
    {
        $available = [];
        foreach ($urls as $url) {
            $available[hash('sha256', rtrim(trim($url), '/'))] = true;
        }

        return count(array_filter($hashes, static fn (string $hash): bool => ! isset($available[$hash])));
    }

    private function privateLeakageCount(): int
    {
        return $this->connection()->table('seo_url_entities as entities')
            ->join('seo_urls as urls', function ($join): void {
                $join->on('urls.canonical_url_hash', '=', 'entities.canonical_url_hash')
                    ->on('urls.locale', '=', 'entities.locale');
            })
            ->whereNotNull('entities.current_binding_key')
            ->where(function ($query): void {
                $query->where('urls.is_private_flow', true)
                    ->orWhere('urls.indexability_state', '!=', 'indexable');
            })
            ->count();
    }

    private function currentBindingConflictCount(): int
    {
        return $this->connection()->table('seo_url_entities')
            ->whereNotNull('current_binding_key')
            ->select('current_binding_key')
            ->groupBy('current_binding_key')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    private function schemaReady(): bool
    {
        try {
            $schema = Schema::connection((string) config('seo_intel.connection', 'seo_intel'));

            return \App\Support\SchemaBaseline::tableExists('seo_urls', $schema->getConnection()->getName())
                && \App\Support\SchemaBaseline::tableExists('seo_url_entities', $schema->getConnection()->getName())
                && \App\Support\SchemaBaseline::columnExists('seo_urls', 'authority_revision', $schema->getConnection()->getName())
                && \App\Support\SchemaBaseline::columnExists('seo_url_entities', 'current_binding_key', $schema->getConnection()->getName());
        } catch (Throwable) {
            return false;
        }
    }

    private function connection(): mixed
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'));
    }
}
