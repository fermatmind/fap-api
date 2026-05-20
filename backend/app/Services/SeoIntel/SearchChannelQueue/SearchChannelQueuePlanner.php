<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SearchChannelQueuePlanner
{
    public function __construct(
        private readonly SearchChannelQueueEligibilityEvaluator $eligibility,
        private readonly SearchChannelQueueChannelMapper $channels,
        private readonly SearchChannelQueueIdempotency $idempotency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(?string $channel = null, ?string $pageType = null, int $limit = 20): array
    {
        $sourceUnavailableReason = null;
        $rows = [];

        try {
            $connectionName = (string) config('seo_intel.connection', 'seo_intel');

            if (! Schema::connection($connectionName)->hasTable('seo_urls')) {
                $sourceUnavailableReason = 'seo_urls_table_missing';
            } else {
                $query = DB::connection($connectionName)
                    ->table('seo_urls')
                    ->orderBy('locale')
                    ->orderBy('page_entity_type')
                    ->orderBy('canonical_url_hash')
                    ->limit(max(1, min($limit, 500)));

                if ($pageType !== null && $pageType !== '') {
                    $query->where('page_entity_type', $pageType);
                }

                $rows = $query->get()
                    ->map(fn (object $row): array => (array) $row)
                    ->all();
            }
        } catch (\Throwable) {
            $sourceUnavailableReason = 'seo_urls_source_unavailable';
        }

        $planned = [];
        $blocked = [];
        $eligibleUrlCount = 0;
        $channelBreakdown = [];
        $pageTypeBreakdown = [];
        $reasonCodeBreakdown = [];
        $selectedChannels = $this->channels->channels($channel);

        if ($selectedChannels === [] && $channel !== null && $channel !== '') {
            $reasonCodeBreakdown['channel_not_allowed'] = 1;
        }

        foreach ($rows as $row) {
            $result = $this->eligibility->evaluate($row);
            $pageEntityType = (string) ($row['page_entity_type'] ?? 'unknown');
            $pageTypeBreakdown[$pageEntityType] = ($pageTypeBreakdown[$pageEntityType] ?? 0) + 1;

            if (! $result->eligible) {
                $blocked[] = [
                    'canonical_url_hash' => hash('sha256', (string) ($row['canonical_url'] ?? '')),
                    'locale' => (string) ($row['locale'] ?? ''),
                    'page_entity_type' => $pageEntityType,
                    'eligibility_state' => $result->eligibilityState,
                    'reason_codes' => $result->reasonCodes,
                ];

                foreach ($result->reasonCodes as $reasonCode) {
                    $reasonCodeBreakdown[$reasonCode] = ($reasonCodeBreakdown[$reasonCode] ?? 0) + 1;
                }

                continue;
            }

            $eligibleUrlCount++;

            foreach ($selectedChannels as $selectedChannel) {
                $channelBreakdown[$selectedChannel] = ($channelBreakdown[$selectedChannel] ?? 0) + 1;
                $planned[] = $this->plannedItem($row, $result, $selectedChannel);
            }
        }

        ksort($channelBreakdown);
        ksort($pageTypeBreakdown);
        ksort($reasonCodeBreakdown);

        return [
            'source_unavailable_reason' => $sourceUnavailableReason,
            'candidate_count' => count($rows),
            'eligible_count' => $eligibleUrlCount,
            'blocked_count' => count($blocked),
            'planned_queue_count' => count($planned),
            'planned_items' => $planned,
            'blocked_items' => $blocked,
            'channel_breakdown' => $channelBreakdown,
            'page_type_breakdown' => $pageTypeBreakdown,
            'reason_code_breakdown' => $reasonCodeBreakdown,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function plannedItem(array $row, SearchChannelQueueEligibilityResult $result, string $channel): array
    {
        $canonicalUrl = (string) $row['canonical_url'];
        $locale = (string) $row['locale'];
        $pageType = (string) $row['page_entity_type'];
        $entityId = isset($row['entity_id_or_slug']) ? (string) $row['entity_id_or_slug'] : null;
        $metadata = $this->metadata($row);
        $sourceTable = (string) ($metadata['source_table'] ?? $this->sourceTableFromLastmod($row['lastmod_source'] ?? null));
        $urlHash = (string) ($row['canonical_url_hash'] ?? hash('sha256', $canonicalUrl));

        return [
            'canonical_url' => $canonicalUrl,
            'locale' => $locale,
            'page_entity_type' => $pageType,
            'entity_type' => $pageType,
            'entity_id' => $entityId,
            'source_authority' => (string) $row['source_authority'],
            'source_table' => $sourceTable !== '' ? $sourceTable : null,
            'channel' => $channel,
            'eligibility_state' => $result->eligibilityState,
            'approval_state' => 'pending',
            'execution_state' => 'dry_run_ready',
            'indexability_state' => (string) $row['indexability_state'],
            'claim_boundary_state' => $result->claimBoundaryState,
            'private_flow' => (bool) ($row['is_private_flow'] ?? false),
            'reason_codes' => $result->reasonCodes,
            'lastmod' => $row['lastmod_at'] ?? null,
            'content_hash' => is_string($metadata['content_hash'] ?? null) ? $metadata['content_hash'] : null,
            'url_hash' => $urlHash,
            'idempotency_key' => $this->idempotency->key($canonicalUrl, $locale, $channel),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function metadata(array $row): array
    {
        $metadata = $row['metadata_json'] ?? [];

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function sourceTableFromLastmod(mixed $lastmodSource): ?string
    {
        $source = trim((string) $lastmodSource);

        if ($source === '') {
            return null;
        }

        return str_contains($source, '.') ? explode('.', $source, 2)[0] : $source;
    }
}
