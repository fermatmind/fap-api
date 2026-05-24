<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MbtiUrlTruthCleanupService
{
    public const PRESET = 'mbti-fix-02-www-research-apex';

    public const RETIRED_INDEXABILITY_STATE = 'superseded_canonical';

    public const RETIRED_AUTHORITY_STATUS = 'superseded_canonical';

    private const EN_RESEARCH_WWW = 'https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report';

    private const ZH_RESEARCH_WWW = 'https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report';

    private const EN_RESEARCH_APEX = 'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report';

    private const ZH_RESEARCH_APEX = 'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report';

    private const ZH_MBTI_APEX = 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types';

    private const EN_MBTI_SUBMITTED = 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types';

    private const EN_MBTI_SUBMITTED_QUEUE_ITEM_ID = 2;

    /**
     * @return array<string, mixed>
     */
    public function run(?string $preset, bool $execute, bool $dryRun, bool $noWrite): array
    {
        $issues = [];
        $writesCommitted = false;
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');
        $connection = DB::connection($connectionName);
        $queueItem2Before = null;
        $queueItem2After = null;
        $queueItem2SafetyIssues = [];

        $result = $this->baseResult($dryRun, $noWrite, $execute);

        if ($preset !== self::PRESET) {
            $issues[] = 'unsupported_or_missing_preset';

            return $this->finish($result, $issues, 'blocked');
        }

        if ($execute && ($dryRun || $noWrite)) {
            $issues[] = 'execute_conflicts_with_dry_run_or_no_write';
        }

        if (! $this->hasTable($connectionName, 'seo_urls') || ! $this->hasTable($connectionName, 'seo_url_entities')) {
            $issues[] = 'seo_url_truth_tables_missing';
        }

        $queueTableExists = $this->hasTable($connectionName, 'seo_search_channel_queue_items');
        $candidates = $this->candidateMap();
        $apexResearchRecords = array_values(array_filter([
            $candidates[self::EN_RESEARCH_APEX] ?? null,
            $candidates[self::ZH_RESEARCH_APEX] ?? null,
        ]));
        $zhMbtiRecord = $candidates[self::ZH_MBTI_APEX] ?? null;

        $result['apex_research_candidates_found'] = count($apexResearchRecords) === 2;
        $result['zh_mbti_candidate_found'] = $zhMbtiRecord instanceof UrlTruthInventoryRecord;

        if (! $result['apex_research_candidates_found']) {
            $issues[] = 'apex_research_candidates_missing';
        }

        if (! $result['zh_mbti_candidate_found']) {
            $issues[] = 'zh_mbti_candidate_missing';
        }

        $oldRows = [];
        $apexRows = [];

        if (! in_array('seo_url_truth_tables_missing', $issues, true)) {
            $oldRows = $this->urlRows([self::EN_RESEARCH_WWW, self::ZH_RESEARCH_WWW]);
            $apexRows = $this->urlRows([self::EN_RESEARCH_APEX, self::ZH_RESEARCH_APEX]);
            $activeOldRows = $this->activeRows($oldRows);
            $activeApexRows = $this->activeRows($apexRows);

            $result['old_www_rows_found'] = count($oldRows);

            if (count($oldRows) !== 2) {
                $issues[] = 'old_www_rows_missing';
            }

            if ($activeOldRows !== [] && $activeApexRows !== []) {
                $issues[] = 'active_www_and_apex_research_rows_conflict';
            }

            if ($queueTableExists && $this->oldWwwQueueItemCount() > 0) {
                $issues[] = 'old_www_search_channel_queue_item_exists';
            }

            if ($queueTableExists) {
                $queueItem2Before = $this->queueItem2();
                $queueItem2SafetyIssues = $this->queueItem2SafetyIssues($queueItem2Before);
                $issues = array_merge($issues, $queueItem2SafetyIssues);
            } else {
                $issues[] = 'search_channel_queue_table_missing';
            }
        }

        $writeRequested = $execute && ! $dryRun && ! $noWrite;
        $blockingIssues = $this->blockingIssues($issues);

        if ($writeRequested && $blockingIssues !== []) {
            return $this->finish($result, $issues, 'blocked');
        }

        if ($writeRequested) {
            $connection->transaction(function () use (
                $connection,
                $oldRows,
                $apexResearchRecords,
                $zhMbtiRecord,
                &$result,
                &$writesCommitted
            ): void {
                $result['old_www_rows_retired'] = $this->retireOldRows($connection, $oldRows);
                $result['seo_url_entities_updated'] += $this->retireOldEntityMappings($connection, $oldRows);

                foreach ($apexResearchRecords as $record) {
                    $this->upsertRecord($connection, $record);
                    $result['apex_research_rows_written']++;
                    $result['seo_url_entities_updated']++;
                }

                if ($zhMbtiRecord instanceof UrlTruthInventoryRecord) {
                    $this->upsertRecord($connection, $zhMbtiRecord);
                    $result['zh_mbti_row_written'] = true;
                    $result['seo_url_entities_updated']++;
                }

                $writesCommitted = true;
            });

        }

        if ($queueTableExists) {
            $queueItem2After = $this->queueItem2();
        }

        $result['writes_committed'] = $writesCommitted;
        $result['queue_item_2_untouched'] = $queueItem2SafetyIssues === []
            && $this->queueItemUnchanged($queueItem2Before, $queueItem2After);

        if (! $result['queue_item_2_untouched']) {
            $issues[] = 'queue_item_2_changed_or_unverified';
        }

        $result['duplicate_cluster_prevented'] = $this->duplicateClusterPrevented($writeRequested);

        return $this->finish(
            result: $result,
            issues: $issues,
            status: $issues === [] ? ($writeRequested ? 'success' : 'dry_run_ready') : 'blocked',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseResult(bool $dryRun, bool $noWrite, bool $execute): array
    {
        return [
            'runtime' => 'mbti_url_truth_cleanup',
            'status' => 'dry_run_ready',
            'preset' => self::PRESET,
            'dry_run' => $dryRun,
            'no_write' => $noWrite,
            'execute_attempted' => $execute,
            'writes_committed' => false,
            'old_www_rows_found' => 0,
            'old_www_rows_retired' => 0,
            'apex_research_candidates_found' => false,
            'apex_research_rows_written' => 0,
            'zh_mbti_candidate_found' => false,
            'zh_mbti_row_written' => false,
            'seo_url_entities_updated' => 0,
            'queue_item_2_untouched' => true,
            'search_channel_enqueue_attempted' => false,
            'live_submission_attempted' => false,
            'external_api_call_attempted' => false,
            'sitemap_llms_authority_used' => false,
            'frontend_fallback_authority_used' => false,
            'duplicate_cluster_prevented' => false,
            'idempotency_key' => hash('sha256', self::PRESET.'|'.implode('|', $this->expectedUrls())),
            'issues' => [],
            'next_task' => 'BACKEND-DEPLOY-READINESS｜Deploy MBTI cleanup queue-item safety fix',
            'targets' => [
                'stale_www_urls' => [self::EN_RESEARCH_WWW, self::ZH_RESEARCH_WWW],
                'replacement_apex_urls' => [self::EN_RESEARCH_APEX, self::ZH_RESEARCH_APEX],
                'zh_mbti_apex_url' => self::ZH_MBTI_APEX,
                'already_submitted_urls_excluded' => [self::EN_MBTI_SUBMITTED],
            ],
        ];
    }

    /**
     * @return array<string, UrlTruthInventoryRecord>
     */
    private function candidateMap(): array
    {
        $records = (new BackendAuthorityUrlTruthSource)->candidates();
        $map = [];

        foreach ($records as $record) {
            $map[$record->canonicalUrl] = $record;
        }

        return $map;
    }

    /**
     * @param  list<string>  $urls
     * @return list<object>
     */
    private function urlRows(array $urls): array
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_urls')
            ->whereIn('canonical_url', $urls)
            ->orderBy('locale')
            ->get()
            ->all();
    }

    /**
     * @param  list<object>  $rows
     * @return list<object>
     */
    private function activeRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (object $row): bool => (string) ($row->indexability_state ?? '') === 'indexable'
        ));
    }

    private function oldWwwQueueItemCount(): int
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_items')
            ->whereIn('canonical_url', [self::EN_RESEARCH_WWW, self::ZH_RESEARCH_WWW])
            ->whereNotIn('execution_state', ['cancelled', 'failed', 'retired', 'superseded', 'archived'])
            ->count();
    }

    /**
     * @param  list<object>  $oldRows
     */
    private function retireOldRows(mixed $connection, array $oldRows): int
    {
        $retired = 0;

        foreach ($oldRows as $row) {
            if ((string) ($row->indexability_state ?? '') === self::RETIRED_INDEXABILITY_STATE) {
                continue;
            }

            $metadata = $this->metadata($row->metadata_json ?? null);
            $metadata['superseded_canonical'] = true;
            $metadata['superseded_by'] = str_contains((string) $row->canonical_url, '/en/')
                ? self::EN_RESEARCH_APEX
                : self::ZH_RESEARCH_APEX;
            $metadata['retired_by'] = 'seo-intel:mbti-url-truth-cleanup';

            $connection->table('seo_urls')
                ->where('canonical_url_hash', (string) $row->canonical_url_hash)
                ->where('locale', (string) $row->locale)
                ->update([
                    'indexability_state' => self::RETIRED_INDEXABILITY_STATE,
                    'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

            $retired++;
        }

        return $retired;
    }

    /**
     * @param  list<object>  $oldRows
     */
    private function retireOldEntityMappings(mixed $connection, array $oldRows): int
    {
        $updated = 0;

        foreach ($oldRows as $row) {
            $updated += $connection->table('seo_url_entities')
                ->where('canonical_url_hash', (string) $row->canonical_url_hash)
                ->where('locale', (string) $row->locale)
                ->where('page_entity_type', (string) $row->page_entity_type)
                ->where('entity_id_or_slug', (string) $row->entity_id_or_slug)
                ->update([
                    'authority_status' => self::RETIRED_AUTHORITY_STATUS,
                    'updated_at' => now(),
                ]);
        }

        return $updated;
    }

    private function upsertRecord(mixed $connection, UrlTruthInventoryRecord $record): void
    {
        $hash = $record->canonicalUrlHash();
        $now = now();

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
                'metadata_json' => json_encode($record->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if ($record->entityIdOrSlug === null || $record->entityIdOrSlug === '') {
            return;
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
                'attributes_json' => json_encode($record->attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function queueItem2(): ?array
    {
        $row = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_items')
            ->where('id', self::EN_MBTI_SUBMITTED_QUEUE_ITEM_ID)
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<string, mixed>|null  $queueItem
     * @return list<string>
     */
    private function queueItem2SafetyIssues(?array $queueItem): array
    {
        if ($queueItem === null) {
            return ['queue_item_2_missing'];
        }

        $issues = [];

        if ((string) ($queueItem['canonical_url'] ?? '') !== self::EN_MBTI_SUBMITTED) {
            $issues[] = 'queue_item_2_url_mismatch';
        }

        if ((string) ($queueItem['channel'] ?? '') !== 'indexnow') {
            $issues[] = 'queue_item_2_channel_mismatch';
        }

        if ((string) ($queueItem['approval_state'] ?? '') !== 'approved') {
            $issues[] = 'queue_item_2_approval_state_mismatch';
        }

        if ((string) ($queueItem['execution_state'] ?? '') !== 'submitted') {
            $issues[] = 'queue_item_2_execution_state_mismatch';
        }

        if (in_array((string) ($queueItem['canonical_url'] ?? ''), $this->expectedUrls(), true)) {
            $issues[] = 'queue_item_2_in_cleanup_target_set';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function queueItemUnchanged(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return false;
        }

        return $before === $after;
    }

    private function duplicateClusterPrevented(bool $writeRequested): bool
    {
        if (! $writeRequested) {
            return true;
        }

        $oldActive = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_urls')
            ->whereIn('canonical_url', [self::EN_RESEARCH_WWW, self::ZH_RESEARCH_WWW])
            ->where('indexability_state', 'indexable')
            ->count();

        $apexActive = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_urls')
            ->whereIn('canonical_url', [self::EN_RESEARCH_APEX, self::ZH_RESEARCH_APEX])
            ->where('indexability_state', 'indexable')
            ->count();

        return $oldActive === 0 && $apexActive === 2;
    }

    /**
     * @param  list<string>  $issues
     * @return list<string>
     */
    private function blockingIssues(array $issues): array
    {
        return array_values(array_filter(
            $issues,
            static fn (string $issue): bool => ! in_array($issue, [], true)
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function finish(array $result, array $issues, string $status): array
    {
        $result['status'] = $status;
        $result['issues'] = array_values(array_unique($issues));

        return $result;
    }

    private function hasTable(string $connectionName, string $table): bool
    {
        try {
            return Schema::connection($connectionName)->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @return list<string>
     */
    private function expectedUrls(): array
    {
        return [
            self::EN_RESEARCH_WWW,
            self::ZH_RESEARCH_WWW,
            self::EN_RESEARCH_APEX,
            self::ZH_RESEARCH_APEX,
            self::ZH_MBTI_APEX,
        ];
    }
}
