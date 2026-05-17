<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UrlTruthInventoryCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly UrlTruthInventorySource $source,
    ) {}

    public function name(): string
    {
        return 'url_truth_inventory';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $writesAllowed = (bool) ($options['writes_allowed'] ?? false);
        $records = $this->source->candidates();
        $validRecords = [];
        $issues = [];
        $skippedPrivateFlows = 0;
        $skippedForbiddenEntityTypes = 0;
        $skippedForbiddenSourceAuthorities = 0;

        foreach ($records as $record) {
            if (! $record instanceof UrlTruthInventoryRecord) {
                $issues[] = 'invalid_candidate_record';

                continue;
            }

            if ($record->isPrivateFlow) {
                $skippedPrivateFlows++;
                $issues[] = 'skipped_private_flow';

                continue;
            }

            if (! in_array($record->pageEntityType, $this->allowedPageEntityTypes(), true)) {
                $skippedForbiddenEntityTypes++;
                $issues[] = 'skipped_forbidden_page_entity_type:'.$record->pageEntityType;

                continue;
            }

            if (! in_array($record->sourceAuthority, $this->allowedSourceAuthorities(), true)) {
                $skippedForbiddenSourceAuthorities++;
                $issues[] = 'skipped_forbidden_source_authority:'.$record->sourceAuthority;

                continue;
            }

            if ($this->containsForbiddenDetail($record->metadata) || $this->containsForbiddenDetail($record->attributes)) {
                $issues[] = 'skipped_forbidden_detail_key';

                continue;
            }

            $validRecords[] = $record;
        }

        $writesAttempted = $writesAllowed && ! $dryRun && count($validRecords) > 0;
        $writesCommitted = false;

        if ($writesAttempted) {
            $this->writeRecords($validRecords);
            $writesCommitted = true;
        }

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: $writesAttempted,
            writesCommitted: $writesCommitted,
            externalCallsAttempted: false,
            itemsSeen: count($records),
            issues: array_values(array_unique($issues)),
            metadata: [
                'candidate_count' => count($records),
                'planned_url_count' => count($validRecords),
                'planned_entity_count' => count($validRecords),
                'skipped_private_flows' => $skippedPrivateFlows,
                'skipped_forbidden_entity_types' => $skippedForbiddenEntityTypes,
                'skipped_forbidden_source_authorities' => $skippedForbiddenSourceAuthorities,
                'sample_hashes' => $this->sampleHashes($validRecords),
                'writes_allowed' => $writesAllowed,
                'source' => $this->source->metadata(),
                'fetches_public_html' => false,
                'performs_drift_detection' => false,
                'external_api_calls' => false,
                'node2_local_laravel_data_source' => false,
                'frontend_fallback_data_source' => false,
                'static_llms_fallback_graph_truth' => false,
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function allowedPageEntityTypes(): array
    {
        return $this->stringList(config('seo_intel.url_truth_inventory.allowed_page_entity_types', []));
    }

    /**
     * @return list<string>
     */
    public function allowedSourceAuthorities(): array
    {
        return $this->stringList(config('seo_intel.url_truth_inventory.allowed_source_authorities', []));
    }

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     */
    private function writeRecords(array $records): void
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();

        foreach ($records as $record) {
            $hash = $record->canonicalUrlHash();

            $connection->table('seo_urls')->updateOrInsert(
                [
                    'canonical_url_hash' => $hash,
                    'locale' => $record->locale,
                ],
                [
                    'canonical_url' => $record->canonicalUrl,
                    'page_entity_type' => $record->pageEntityType,
                    'entity_id_or_slug' => $record->entityIdOrSlug,
                    'cluster' => $record->cluster,
                    'source_authority' => $record->sourceAuthority,
                    'indexability_state' => $record->indexabilityState,
                    'lastmod_at' => $record->lastmodAt,
                    'lastmod_source' => $record->lastmodSource,
                    'is_private_flow' => false,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'metadata_json' => json_encode($record->metadata, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            if ($record->entityIdOrSlug === null || $record->entityIdOrSlug === '') {
                continue;
            }

            $connection->table('seo_url_entities')->updateOrInsert(
                [
                    'canonical_url_hash' => $hash,
                    'locale' => $record->locale,
                    'page_entity_type' => $record->pageEntityType,
                    'entity_id_or_slug' => $record->entityIdOrSlug,
                ],
                [
                    'entity_source' => $record->entitySource,
                    'authority_status' => $record->authorityStatus,
                    'source_updated_at' => $record->sourceUpdatedAt,
                    'attributes_json' => json_encode($record->attributes, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function containsForbiddenDetail(array $payload): bool
    {
        $forbiddenFragments = [
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_payload',
            'payment_payload',
            'provider_payload',
            'raw_email',
            'raw_ip',
            'raw_cookie',
        ];

        foreach (array_keys($payload) as $key) {
            $normalized = Str::lower((string) $key);
            foreach ($forbiddenFragments as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @return list<string>
     */
    private function sampleHashes(array $records): array
    {
        $limit = (int) config('seo_intel.url_truth_inventory.sample_hash_limit', 5);

        return array_values(array_slice(
            array_map(static fn (UrlTruthInventoryRecord $record): string => $record->canonicalUrlHash(), $records),
            0,
            max(0, $limit)
        ));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => (string) $item, $value),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
