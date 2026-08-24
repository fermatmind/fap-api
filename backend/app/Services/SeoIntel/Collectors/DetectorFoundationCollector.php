<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\Detector\BoundedDetectorRunner;
use App\Services\SeoIntel\Detector\DetectorQueueMaterializer;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;
use App\Services\SeoIntel\Sources\DetectorFoundationEvidenceSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DetectorFoundationCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly DetectorFoundationEvidenceSource $source,
        private readonly BoundedDetectorRunner $runner = new BoundedDetectorRunner,
        private readonly DetectorQueueMaterializer $materializer = new DetectorQueueMaterializer,
    ) {}

    public function name(): string
    {
        return 'detector_foundation';
    }

    /** @param array<string,mixed> $options */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $writesAllowed = (bool) ($options['writes_allowed'] ?? false);
        $now = $this->now($options['now'] ?? null);
        $maxUrls = $this->maxUrls($options['limit'] ?? null);
        if (! $dryRun && ! $writesAllowed) {
            return $this->blocked($dryRun, 'detector_materialization_write_gate_closed');
        }

        $snapshot = $this->source->snapshot($now);
        $sourceFingerprint = $this->sourceFingerprint($snapshot);
        $artifact = $this->runner->run($snapshot['jobs'], [
            'dry_run' => $dryRun,
            'page_family' => $this->scope($options['page_type'] ?? null),
            'locale' => $this->scope($options['locale'] ?? null),
            'max_urls' => $maxUrls,
            'timeout_ms' => max(1, min(10_000, (int) config('seo_intel.detector_foundation.timeout_ms', 2_000))),
            'max_evidence_age_seconds' => max(60, min(604_800, (int) config('seo_intel.detector_foundation.max_evidence_age_seconds', 3_600))),
            'expected_policy_version' => PageFamilyPolicyRegistry::VERSION,
            'now' => $now->toIso8601String(),
        ]);
        if (($artifact['complete'] ?? false) !== true) {
            return $this->blocked($dryRun, 'detector_artifact_incomplete');
        }

        $firstReceipt = $this->materializer->materialize($artifact, execute: ! $dryRun, now: $now->toIso8601String());
        $rerunReceipt = $dryRun
            ? null
            : $this->materializer->materialize($artifact, execute: true, now: $now->toIso8601String());
        $readback = $dryRun
            ? [
                'performed' => false,
                'issue_rows' => null,
                'opportunity_rows' => null,
                'duplicate_rows' => null,
            ]
            : $this->readback((string) $artifact['artifact_hash']);

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: ! $dryRun,
            writesCommitted: (bool) ($firstReceipt['writes_committed'] ?? false),
            externalCallsAttempted: false,
            itemsSeen: count($snapshot['jobs']),
            issues: $snapshot['issues'],
            metadata: [
                'source' => $snapshot['metadata'],
                'source_fingerprint' => $sourceFingerprint,
                'artifact' => [
                    'schema_version' => $artifact['schema_version'],
                    'artifact_hash' => $artifact['artifact_hash'],
                    'registry_hash' => $artifact['registry_hash'],
                    'processed_result_count' => $artifact['processed_result_count'],
                    'processed_url_count' => $artifact['processed_url_count'],
                    'outcome_counts' => $artifact['outcome_counts'],
                ],
                'first_receipt' => $firstReceipt,
                'idempotent_rerun_receipt' => $rerunReceipt,
                'readback' => $readback,
                'bounds' => [
                    'max_urls' => $maxUrls,
                    'timeout_ms' => (int) config('seo_intel.detector_foundation.timeout_ms', 2_000),
                ],
                'read_only_gsc' => true,
                'search_submission_allowed' => false,
                'external_api_calls_attempted' => false,
                'public_html_crawl_attempted' => false,
                'cms_mutation_attempted' => false,
                'content_rewrite_attempted' => false,
                'raw_sensitive_fields_output' => false,
            ],
        );
    }

    private function maxUrls(mixed $value): int
    {
        $default = max(1, (int) config('seo_intel.detector_foundation.default_max_urls', 10));
        $maximum = max(1, min(500, (int) config('seo_intel.detector_foundation.maximum_max_urls', 50)));

        return min($maximum, max(1, $value === null || $value === '' ? $default : (int) $value));
    }

    private function scope(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $value) !== 1) {
            throw new InvalidArgumentException('Detector collector scope is invalid.');
        }

        return $value;
    }

    /** @return array{performed:bool,issue_rows:int,opportunity_rows:int,duplicate_rows:int} */
    private function readback(string $artifactHash): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $issueRows = $connection->table('seo_issue_queue')->where('artifact_hash', $artifactHash)->count();
        $opportunityRows = $connection->table('seo_detector_opportunities')->where('artifact_hash', $artifactHash)->count();
        $issueDuplicates = $connection->table('seo_issue_queue')
            ->select('issue_uid')
            ->groupBy('issue_uid')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $opportunityDuplicates = $connection->table('seo_detector_opportunities')
            ->select('opportunity_uid')
            ->groupBy('opportunity_uid')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        return [
            'performed' => true,
            'issue_rows' => $issueRows,
            'opportunity_rows' => $opportunityRows,
            'duplicate_rows' => $issueDuplicates + $opportunityDuplicates,
        ];
    }

    private function now(mixed $value): CarbonImmutable
    {
        try {
            return $value === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse((string) $value)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Detector collector timestamp is invalid.');
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function sourceFingerprint(array $snapshot): string
    {
        $binding = $snapshot;
        foreach ($binding['jobs'] ?? [] as $index => $job) {
            if (is_array($job)) {
                unset($binding['jobs'][$index]['evidence']['evidence_observed_at']);
            }
        }

        return hash('sha256', json_encode(
            $this->sortRecursively($binding),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
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

    private function blocked(bool $dryRun, string $reason): SeoIntelCollectorResult
    {
        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'blocked',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            issues: [$reason],
            metadata: [
                'read_only_gsc' => true,
                'search_submission_allowed' => false,
            ],
        );
    }
}
