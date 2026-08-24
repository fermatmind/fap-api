<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Detector;

use App\Services\SeoIntel\SeoIssueQueueContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DetectorQueueMaterializer
{
    public const RECEIPT_SCHEMA_VERSION = 'seo-detector-materialization.v1';

    public function __construct(
        private readonly BoundedDetectorRunner $runner = new BoundedDetectorRunner,
        private readonly SeoDetectorRegistry $registry = new SeoDetectorRegistry,
        private readonly SeoIssueQueueContract $issueContract = new SeoIssueQueueContract,
        private readonly ?string $connectionName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function materialize(array $artifact, bool $execute = false, ?string $now = null): array
    {
        $timestamp = $this->timestamp($now);
        $evaluatedAt = $this->artifactTimestamp($artifact, 'evaluated_at');
        $materializeBefore = $this->artifactTimestamp($artifact, 'materialize_before');
        $this->assertArtifact($artifact, $execute, $timestamp, $evaluatedAt, $materializeBefore);
        $plan = $this->plan($artifact);
        if (! $execute) {
            return $this->receipt($artifact, $plan, false, false);
        }

        $changes = $this->connection()->transaction(function () use ($artifact, $timestamp, $evaluatedAt): array {
            $counts = $this->emptyChangeCounts();
            foreach ($artifact['results'] as $result) {
                $outcome = (string) $result['outcome'];
                if ($outcome === 'measurement_hold') {
                    $counts['measurement_holds']++;

                    continue;
                }
                if ($outcome === 'pass') {
                    if ((int) ($result['affected_url_count'] ?? 1) !== 0) {
                        $counts['recovery_deferred']++;

                        continue;
                    }
                    $recovery = $this->closeRecoveredCluster($result, $timestamp, $evaluatedAt);
                    $counts['closed'] += $recovery['closed'];
                    $counts['no_change'] += $recovery['no_change'];

                    continue;
                }
                $change = $outcome === 'issue'
                    ? $this->materializeIssue($result, (string) $artifact['artifact_hash'], $timestamp, $evaluatedAt)
                    : $this->materializeOpportunity($result, (string) $artifact['artifact_hash'], $timestamp, $evaluatedAt);
                $counts[$change]++;
            }

            return $counts;
        });

        $writesCommitted = ($changes['created'] + $changes['updated'] + $changes['reopened'] + $changes['closed']) > 0;

        return $this->receipt($artifact, $changes, true, $writesCommitted);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function assertArtifact(
        array $artifact,
        bool $execute,
        CarbonImmutable $now,
        CarbonImmutable $evaluatedAt,
        CarbonImmutable $materializeBefore,
    ): void {
        if (($artifact['schema_version'] ?? null) !== BoundedDetectorRunner::SCHEMA_VERSION
            || ($artifact['registry_version'] ?? null) !== SeoDetectorRegistry::VERSION
            || ($artifact['registry_hash'] ?? null) !== $this->registry->registryHash()
            || ! $this->runner->verifyArtifact($artifact)) {
            throw new InvalidArgumentException('Detector artifact identity or hash is invalid.');
        }
        if (($artifact['complete'] ?? false) !== true || ($artifact['next_cursor'] ?? null) !== null) {
            throw new InvalidArgumentException('Partial detector artifacts cannot be materialized.');
        }
        if ($execute && ($artifact['mode'] ?? null) !== 'controlled_materialization_candidate') {
            throw new InvalidArgumentException('Execution requires a controlled materialization candidate.');
        }
        if ($materializeBefore->lt($evaluatedAt)
            || ($execute && ($evaluatedAt->gt($now) || $now->gt($materializeBefore)))) {
            throw new InvalidArgumentException('Detector artifact evidence freshness window has expired.');
        }
        if (data_get($artifact, 'boundaries.search_submission_allowed') !== false
            || data_get($artifact, 'boundaries.read_only_gsc') !== true
            || data_get($artifact, 'boundaries.writes_attempted') !== false) {
            throw new InvalidArgumentException('Detector artifact crosses a protected write boundary.');
        }
        if (! is_array($artifact['results'] ?? null)) {
            throw new InvalidArgumentException('Detector artifact results are invalid.');
        }

        foreach ($artifact['results'] as $result) {
            if (! is_array($result)) {
                throw new InvalidArgumentException('Detector result is invalid.');
            }
            $detectorId = (string) ($result['detector'] ?? '');
            $definition = $this->registry->detectors()[$detectorId] ?? null;
            if (! is_array($definition)
                || ($result['detector_version'] ?? null) !== $definition['version']
                || ! in_array($result['outcome'] ?? null, SeoDetectorRegistry::OUTPUTS, true)) {
                throw new InvalidArgumentException('Detector result registry binding is invalid.');
            }
            foreach (['authority_revision', 'url_truth_revision', 'policy_version'] as $revision) {
                if (! is_string($result[$revision] ?? null)
                    || ($result[$revision] ?? 'unknown') === 'unknown'
                    || preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $result[$revision]) !== 1) {
                    throw new InvalidArgumentException("Detector result {$revision} is invalid.");
                }
            }
            if (data_get($result, 'privacy.private_negative_set_checked') !== true
                || data_get($result, 'privacy.sensitive_fields_stored') !== false
                || data_get($result, 'privacy.raw_urls_stored') !== false) {
                throw new InvalidArgumentException('Detector result privacy proof is invalid.');
            }
            if (in_array($result['severity'] ?? null, ['P0', 'P1'], true)
                && ($result['evidence_state'] ?? null) !== 'direct_evidence') {
                throw new InvalidArgumentException('P0/P1 detector results require direct evidence.');
            }
            $this->assertNoForbiddenKeys($result);
        }
    }

    /** @param array<string, mixed> $artifact @return array<string, int> */
    private function plan(array $artifact): array
    {
        $counts = $this->emptyChangeCounts();
        foreach ($artifact['results'] as $result) {
            $outcome = (string) $result['outcome'];
            if ($outcome === 'issue') {
                $counts['planned_issues']++;
            } elseif ($outcome === 'opportunity') {
                $counts['planned_opportunities']++;
            } elseif ($outcome === 'measurement_hold') {
                $counts['measurement_holds']++;
            } elseif ((int) ($result['affected_url_count'] ?? 1) === 0) {
                $counts['planned_recoveries']++;
            } else {
                $counts['recovery_deferred']++;
            }
        }

        return $counts;
    }

    /** @param array<string, mixed> $result */
    private function materializeIssue(
        array $result,
        string $artifactHash,
        CarbonImmutable $now,
        CarbonImmutable $evaluatedAt,
    ): string {
        $detectorId = (string) $result['detector'];
        if (! $this->issueContract->isIssueTypeAllowed($detectorId)) {
            throw new RuntimeException("Detector {$detectorId} is not allowed in the Issue Queue.");
        }
        $uid = 'seo_detector_issue_'.(string) $result['dedupe_key'];
        $evidenceHash = $this->evidenceHash($result);
        $table = $this->connection()->table('seo_issue_queue');
        $existing = $table->where('issue_uid', $uid)->lockForUpdate()->first();
        if ($existing !== null && ! $this->isNewerEvidence($existing->metadata_json ?? null, $evaluatedAt)) {
            return 'no_change';
        }
        if ($existing !== null
            && hash_equals((string) ($existing->evidence_hash ?? ''), $evidenceHash)
            && ($existing->status ?? null) === 'open') {
            return 'no_change';
        }

        $reopened = $existing !== null && ($existing->status ?? null) === 'resolved';
        $metadata = $this->metadata($existing?->metadata_json, $result, $artifactHash, $now, $evaluatedAt, $reopened ? 'reopened' : ($existing === null ? 'created' : 'evidence_updated'));
        $values = [
            'issue_type' => $detectorId,
            'detector_id' => $detectorId,
            'detector_version' => (string) $result['detector_version'],
            'severity' => $this->legacySeverity($result['severity'] ?? null),
            'source_system' => 'seo_detector_registry',
            'source_engine' => null,
            'canonical_url_hash' => $result['canonical_url_hash'],
            'canonical_url' => null,
            'query_hash' => $result['query_hash'] ?? null,
            'locale' => (string) $result['locale'],
            'page_entity_type' => (string) $result['page_family'],
            'entity_id_or_slug' => null,
            'cluster' => (string) $result['page_family'],
            'cluster_uid' => (string) $result['cluster_uid'],
            'authority_revision' => (string) $result['authority_revision'],
            'url_truth_revision' => (string) $result['url_truth_revision'],
            'policy_version' => (string) $result['policy_version'],
            'affected_url_count' => max(0, (int) $result['affected_url_count']),
            'status' => 'open',
            'lifecycle_state' => 'open',
            'resolved_at' => null,
            'summary' => "Detector {$detectorId} observed a verified issue cluster.",
            'recommendation' => 'Review the detector evidence and registry recovery conditions.',
            'evidence_hash' => $evidenceHash,
            'artifact_hash' => $artifactHash,
            'last_evidence_at' => $evaluatedAt,
            'reopen_count' => (int) ($existing->reopen_count ?? 0) + ($reopened ? 1 : 0),
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $table->insert([
                'issue_uid' => $uid,
                'detected_at' => $now,
                'created_at' => $now,
                ...$values,
            ]);

            return 'created';
        }
        $table->where('issue_uid', $uid)->update($values);

        return $reopened ? 'reopened' : 'updated';
    }

    /** @param array<string, mixed> $result */
    private function materializeOpportunity(
        array $result,
        string $artifactHash,
        CarbonImmutable $now,
        CarbonImmutable $evaluatedAt,
    ): string {
        $uid = 'seo_detector_opportunity_'.(string) $result['dedupe_key'];
        $evidenceHash = $this->evidenceHash($result);
        $table = $this->connection()->table('seo_detector_opportunities');
        $existing = $table->where('opportunity_uid', $uid)->lockForUpdate()->first();
        if ($existing !== null && ! $this->isNewerEvidence($existing->metadata_json ?? null, $evaluatedAt)) {
            return 'no_change';
        }
        if ($existing !== null
            && hash_equals((string) ($existing->evidence_hash ?? ''), $evidenceHash)
            && ($existing->status ?? null) === 'open') {
            return 'no_change';
        }

        $reopened = $existing !== null && ($existing->status ?? null) === 'resolved';
        $metadata = $this->metadata($existing?->metadata_json, $result, $artifactHash, $now, $evaluatedAt, $reopened ? 'reopened' : ($existing === null ? 'created' : 'evidence_updated'));
        $values = [
            'detector_id' => (string) $result['detector'],
            'detector_version' => (string) $result['detector_version'],
            'cluster_uid' => (string) $result['cluster_uid'],
            'canonical_url_hash' => $result['canonical_url_hash'],
            'query_hash' => $result['query_hash'] ?? null,
            'locale' => (string) $result['locale'],
            'page_family' => (string) $result['page_family'],
            'authority_revision' => (string) $result['authority_revision'],
            'url_truth_revision' => (string) $result['url_truth_revision'],
            'policy_version' => (string) $result['policy_version'],
            'status' => 'open',
            'lifecycle_state' => 'open',
            'affected_url_count' => max(0, (int) $result['affected_url_count']),
            'evidence_hash' => $evidenceHash,
            'artifact_hash' => $artifactHash,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'last_evidence_at' => $evaluatedAt,
            'resolved_at' => null,
            'reopen_count' => (int) ($existing->reopen_count ?? 0) + ($reopened ? 1 : 0),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $table->insert([
                'opportunity_uid' => $uid,
                'detected_at' => $now,
                'created_at' => $now,
                ...$values,
            ]);

            return 'created';
        }
        $table->where('opportunity_uid', $uid)->update($values);

        return $reopened ? 'reopened' : 'updated';
    }

    /** @param array<string, mixed> $result */
    private function closeRecoveredCluster(
        array $result,
        CarbonImmutable $now,
        CarbonImmutable $evaluatedAt,
    ): array {
        $definition = $this->registry->detectors()[(string) $result['detector']];
        $tableName = $definition['output_type'] === 'opportunity'
            ? 'seo_detector_opportunities'
            : 'seo_issue_queue';
        $rows = $this->connection()->table($tableName)
            ->where('cluster_uid', (string) $result['cluster_uid'])
            ->where('status', 'open')
            ->lockForUpdate()
            ->get();
        $closed = 0;
        $noChange = 0;
        foreach ($rows as $row) {
            if (! $this->isNewerEvidence($row->metadata_json ?? null, $evaluatedAt)) {
                $noChange++;

                continue;
            }
            $idField = $tableName === 'seo_issue_queue' ? 'issue_uid' : 'opportunity_uid';
            $metadata = $this->metadata($row->metadata_json ?? null, $result, null, $now, $evaluatedAt, 'resolved');
            $this->connection()->table($tableName)
                ->where($idField, (string) $row->{$idField})
                ->update([
                    'status' => 'resolved',
                    'lifecycle_state' => 'resolved',
                    'resolved_at' => $now,
                    'last_evidence_at' => $evaluatedAt,
                    'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);
            $closed++;
        }

        return ['closed' => $closed, 'no_change' => $noChange];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function receipt(array $artifact, array $counts, bool $writesAttempted, bool $writesCommitted): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'artifact_hash' => $artifact['artifact_hash'],
            'registry_hash' => $artifact['registry_hash'],
            'mode' => $writesAttempted ? 'controlled_materialization' : 'dry_run',
            'counts' => $counts,
            'writes_attempted' => $writesAttempted,
            'writes_committed' => $writesCommitted,
            'idempotency_proof' => [
                'issue_uid_unique' => true,
                'opportunity_uid_unique' => true,
                'same_evidence_is_no_change' => true,
                'duplicate_rows_created' => 0,
            ],
            'boundaries' => [
                'cms_mutation_attempted' => false,
                'content_rewrite_attempted' => false,
                'indexability_change_attempted' => false,
                'search_submission_allowed' => false,
                'read_only_gsc' => true,
                'raw_sensitive_fields_output' => false,
            ],
        ];
    }

    /** @return array<string, int> */
    private function emptyChangeCounts(): array
    {
        return [
            'planned_issues' => 0,
            'planned_opportunities' => 0,
            'planned_recoveries' => 0,
            'created' => 0,
            'updated' => 0,
            'reopened' => 0,
            'closed' => 0,
            'no_change' => 0,
            'measurement_holds' => 0,
            'recovery_deferred' => 0,
        ];
    }

    /** @param array<string, mixed> $result */
    private function evidenceHash(array $result): string
    {
        return hash('sha256', json_encode($this->sortRecursively($result), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function metadata(
        mixed $existing,
        array $result,
        ?string $artifactHash,
        CarbonImmutable $now,
        CarbonImmutable $evaluatedAt,
        string $event,
    ): array {
        $metadata = $this->decodeJson($existing);
        $history = is_array($metadata['lifecycle_history'] ?? null) ? $metadata['lifecycle_history'] : [];
        $history[] = [
            'event' => $event,
            'at' => $now->toIso8601String(),
            'evidence_hash' => $this->evidenceHash($result),
        ];

        return [
            'schema_version' => 'seo-detector-queue-metadata.v1',
            'detector_result' => $result,
            'artifact_hash' => $artifactHash ?? ($metadata['artifact_hash'] ?? null),
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'lifecycle_history' => array_slice($history, -20),
            'raw_sensitive_fields_stored' => false,
        ];
    }

    private function legacySeverity(mixed $severity): string
    {
        return match ($severity) {
            'P0' => 'critical',
            'P1' => 'high',
            'P2' => 'warning',
            default => 'info',
        };
    }

    /** @param array<string, mixed> $value */
    private function assertNoForbiddenKeys(array $value): void
    {
        $forbidden = array_fill_keys($this->issueContract->forbiddenColumns(), true);
        $walk = function (array $payload) use (&$walk, $forbidden): void {
            foreach ($payload as $key => $item) {
                if (is_string($key) && isset($forbidden[strtolower($key)])) {
                    throw new InvalidArgumentException('Detector result contains a forbidden field.');
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($value);
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function timestamp(?string $value): CarbonImmutable
    {
        try {
            return $value === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('Materialization timestamp is invalid.');
        }
    }

    /** @param array<string, mixed> $artifact */
    private function artifactTimestamp(array $artifact, string $key): CarbonImmutable
    {
        if (! is_string($artifact[$key] ?? null)) {
            throw new InvalidArgumentException("Detector artifact {$key} is invalid.");
        }

        return $this->timestamp($artifact[$key]);
    }

    private function isNewerEvidence(mixed $metadata, CarbonImmutable $evaluatedAt): bool
    {
        $existing = $this->decodeJson($metadata);
        if (! is_string($existing['evaluated_at'] ?? null)) {
            return true;
        }

        try {
            return $evaluatedAt->gt(CarbonImmutable::parse($existing['evaluated_at']));
        } catch (Throwable) {
            return true;
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName ?? (string) config('seo_intel.connection', 'seo_intel'));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
