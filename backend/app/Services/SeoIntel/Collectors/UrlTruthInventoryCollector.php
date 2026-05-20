<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
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
        $canary = (bool) ($options['canary'] ?? false);
        $limit = $this->boundedLimit($options['limit'] ?? null, $canary);
        $localeFilter = $this->stringOption($options['locale'] ?? null);
        $pageTypeFilter = $this->stringOption($options['page_type'] ?? null);
        $boundProvided = $canary || (($options['limit'] ?? null) !== null && ($options['limit'] ?? '') !== '');
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

            if ($record->isPrivateFlow || $this->isPrivateFlowUrl($record->canonicalUrl)) {
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

            if ($localeFilter !== null && $record->locale !== $localeFilter) {
                continue;
            }

            if ($pageTypeFilter !== null && $record->pageEntityType !== $pageTypeFilter) {
                continue;
            }

            $validRecords[] = $record;
        }

        usort($validRecords, static fn (UrlTruthInventoryRecord $a, UrlTruthInventoryRecord $b): int => strcmp(
            $a->canonicalUrlHash(),
            $b->canonicalUrlHash(),
        ));

        if ($limit !== null) {
            $validRecords = array_values(array_slice($validRecords, 0, $limit));
        }

        if (
            $writesAllowed
            && ! $dryRun
            && (bool) config('seo_intel.url_truth_inventory.write_requires_bound', true)
            && ! $boundProvided
        ) {
            $issues[] = 'url_truth_inventory_write_requires_bound';

            return $this->result(
                records: $records,
                validRecords: $validRecords,
                dryRun: $dryRun,
                writesAllowed: $writesAllowed,
                writesAttempted: false,
                writesCommitted: false,
                issues: $issues,
                skippedPrivateFlows: $skippedPrivateFlows,
                skippedForbiddenEntityTypes: $skippedForbiddenEntityTypes,
                skippedForbiddenSourceAuthorities: $skippedForbiddenSourceAuthorities,
                canary: $canary,
                limit: $limit,
                localeFilter: $localeFilter,
                pageTypeFilter: $pageTypeFilter,
                status: 'blocked',
            );
        }

        $writesAttempted = $writesAllowed && ! $dryRun && count($validRecords) > 0;
        $writesCommitted = false;

        if ($writesAttempted) {
            (new UrlTruthInventoryRecordWriter)->write($validRecords);
            $writesCommitted = true;
        }

        return $this->result(
            records: $records,
            validRecords: $validRecords,
            dryRun: $dryRun,
            writesAllowed: $writesAllowed,
            writesAttempted: $writesAttempted,
            writesCommitted: $writesCommitted,
            issues: $issues,
            skippedPrivateFlows: $skippedPrivateFlows,
            skippedForbiddenEntityTypes: $skippedForbiddenEntityTypes,
            skippedForbiddenSourceAuthorities: $skippedForbiddenSourceAuthorities,
            canary: $canary,
            limit: $limit,
            localeFilter: $localeFilter,
            pageTypeFilter: $pageTypeFilter,
        );
    }

    /**
     * @param  list<mixed>  $records
     * @param  list<UrlTruthInventoryRecord>  $validRecords
     * @param  list<string>  $issues
     */
    private function result(
        array $records,
        array $validRecords,
        bool $dryRun,
        bool $writesAllowed,
        bool $writesAttempted,
        bool $writesCommitted,
        array $issues,
        int $skippedPrivateFlows,
        int $skippedForbiddenEntityTypes,
        int $skippedForbiddenSourceAuthorities,
        bool $canary,
        ?int $limit,
        ?string $localeFilter,
        ?string $pageTypeFilter,
        string $status = 'success',
    ): SeoIntelCollectorResult {
        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: $status,
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
                'canary' => $canary,
                'limit' => $limit,
                'locale_filter' => $localeFilter,
                'page_type_filter' => $pageTypeFilter,
                'source_authority_breakdown' => $this->sourceAuthorityBreakdown($validRecords),
                'target_tables' => ['seo_urls', 'seo_url_entities'],
                'write_requires_bound' => (bool) config('seo_intel.url_truth_inventory.write_requires_bound', true),
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
                'node2_local_db_data_source' => false,
                'frontend_fallback_data_source' => false,
                'static_llms_fallback_graph_truth' => false,
                'search_url_submission' => false,
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
            'raw_order_no',
            'raw_attempt_id',
            'raw_ip',
            'raw_cookie',
            'raw_user_agent',
            'token',
            'api_key',
            'secret',
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

    private function isPrivateFlowUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $pathWithoutLocale = preg_replace('#^/(en|zh)(?=/|$)#', '', $path) ?: $path;

        foreach (['/take', '/result', '/orders', '/order', '/share', '/pay', '/checkout', '/report-private', '/report/private'] as $fragment) {
            if ($pathWithoutLocale === $fragment || str_starts_with($pathWithoutLocale, $fragment.'/')) {
                return true;
            }
        }

        return false;
    }

    private function boundedLimit(mixed $rawLimit, bool $canary): ?int
    {
        $max = max(1, (int) config('seo_intel.url_truth_inventory.canary_max_limit', 50));

        if ($rawLimit !== null && $rawLimit !== '') {
            return min($max, max(1, (int) $rawLimit));
        }

        if ($canary) {
            $default = max(1, (int) config('seo_intel.url_truth_inventory.canary_default_limit', 10));

            return min($max, $default);
        }

        return null;
    }

    private function stringOption(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @return array<string, int>
     */
    private function sourceAuthorityBreakdown(array $records): array
    {
        $breakdown = [];

        foreach ($records as $record) {
            $breakdown[$record->sourceAuthority] = ($breakdown[$record->sourceAuthority] ?? 0) + 1;
        }

        ksort($breakdown);

        return $breakdown;
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
