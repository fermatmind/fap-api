<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;

/**
 * @review-surface search_submission_queue_approval
 */
final class SearchChannelQueueIdempotency
{
    public function key(string $canonicalUrl, string $locale, string $channel, ?string $sourceVersion = null): string
    {
        $parts = [
            strtolower(trim($canonicalUrl)),
            strtolower(trim($locale)),
            strtolower(trim($channel)),
        ];

        if ($sourceVersion !== null && trim($sourceVersion) !== '') {
            $parts[] = 'source-version:'.trim($sourceVersion);
        }

        return hash('sha256', implode('|', $parts));
    }

    public function existingQueueItem(string $canonicalUrl, string $locale, string $channel, mixed $sourceLastmod = null, ?string $sourceContentHash = null): ?array
    {
        return $this->duplicateStatus($canonicalUrl, $locale, $channel, $sourceLastmod, $sourceContentHash)['blocking_item'];
    }

    /**
     * @return array{
     *     blocking_item:array{id:int, approval_state:string, execution_state:string}|null,
     *     stale_submitted_items:list<array{id:int, approval_state:string, execution_state:string, stale_reason:string}>
     * }
     */
    public function duplicateStatus(string $canonicalUrl, string $locale, string $channel, mixed $sourceLastmod = null, ?string $sourceContentHash = null, ?string $sourceVersion = null): array
    {
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');

        try {
            if (! \App\Support\SchemaBaseline::tableExists('seo_search_channel_queue_items', $connectionName)) {
                return $this->emptyStatus();
            }

            $rows = DB::connection($connectionName)
                ->table('seo_search_channel_queue_items')
                ->where('url_hash', hash('sha256', $canonicalUrl))
                ->where('locale', $locale)
                ->where('channel', $channel)
                ->orderByDesc('id')
                ->get(['id', 'page_entity_type', 'source_authority', 'approval_state', 'execution_state', 'lastmod', 'content_hash', 'updated_at', 'idempotency_key']);

            $sourceTimestamp = $this->timestamp($sourceLastmod);
            $normalizedSourceContentHash = $this->normalizedContentHash($sourceContentHash);
            $staleSubmittedItems = [];

            foreach ($rows as $row) {
                // A never-submitted legacy draft without a content version must not pin a new
                // Article decision. Keep versioned drafts and uncertain attempts blocking.
                if ($this->unsubmittedLegacyArticleDraft($row, $canonicalUrl, $locale, $channel, $normalizedSourceContentHash, $sourceVersion, $connectionName)) {
                    continue;
                }
                $staleReason = $this->submittedItemStaleReason($row, $sourceTimestamp, $normalizedSourceContentHash, $sourceVersion === null ? null : $this->key($canonicalUrl, $locale, $channel, $sourceVersion));
                if ($staleReason !== null) {
                    $staleSubmittedItems[] = [
                        'id' => (int) $row->id,
                        'approval_state' => (string) $row->approval_state,
                        'execution_state' => (string) $row->execution_state,
                        'stale_reason' => $staleReason,
                    ];

                    continue;
                }

                return [
                    'blocking_item' => [
                        'id' => (int) $row->id,
                        'approval_state' => (string) $row->approval_state,
                        'execution_state' => (string) $row->execution_state,
                    ],
                    'stale_submitted_items' => $staleSubmittedItems,
                ];
            }

            return [
                'blocking_item' => null,
                'stale_submitted_items' => $staleSubmittedItems,
            ];
        } catch (\Throwable) {
            return $this->emptyStatus();
        }
    }

    private function unsubmittedLegacyArticleDraft(object $row, string $url, string $locale, string $channel, ?string $contentHash, ?string $version, string $connection): bool
    {
        if ($row->page_entity_type !== 'article' || $row->source_authority !== 'backend_cms'
            || $row->execution_state !== 'dry_run_ready' || ! in_array($row->approval_state, ['pending', 'approved'], true)
            || $row->content_hash !== null || ! $this->legacyDraftKeyMatches($row, $url, $locale, $channel)
            || preg_match('/\Aarticle-decision:[1-9][0-9]*:content:([a-f0-9]{64})\z/', $version ?? '', $match) !== 1
            || $contentHash === null || ! hash_equals($match[1], $contentHash)) {
            return false;
        }

        try {
            if (! \App\Support\SchemaBaseline::tableExists('seo_search_channel_queue_events', $connection)) {
                return false;
            }
            $events = DB::connection($connection)->table('seo_search_channel_queue_events')
                ->where('queue_item_id', $row->id)->pluck('event_type')->all();

            return in_array('queue_item_planned', $events, true)
                && array_diff($events, ['queue_item_planned', 'queue_item_existing_preserved', 'search_channel_queue_approved']) === [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function legacyDraftKeyMatches(object $row, string $url, string $locale, string $channel): bool
    {
        $key = (string) $row->idempotency_key;
        if (hash_equals($this->key($url, $locale, $channel), $key)) {
            return true;
        }
        $lastmod = trim((string) $row->lastmod);

        return $lastmod !== '' && hash_equals($this->key($url, $locale, $channel, 'lastmod:'.$lastmod), $key);
    }

    private function submittedItemStaleReason(object $row, ?int $sourceTimestamp, ?string $sourceContentHash, ?string $expectedKey): ?string
    {
        if ((string) $row->execution_state !== 'submitted') {
            return null;
        }

        if ($expectedKey !== null && ! hash_equals($expectedKey, (string) $row->idempotency_key)) {
            return 'existing_submitted_item_stale_by_source_version';
        }

        $itemContentHash = $this->normalizedContentHash($row->content_hash ?? null);
        if ($sourceContentHash !== null && ($itemContentHash === null || ! hash_equals($itemContentHash, $sourceContentHash))) {
            return 'existing_submitted_item_stale_by_content_hash';
        }

        if ($sourceTimestamp === null) {
            return null;
        }

        $itemTimestamp = $this->timestamp($row->lastmod ?? null) ?? $this->timestamp($row->updated_at ?? null);

        return $itemTimestamp === null || $sourceTimestamp > $itemTimestamp
            ? 'existing_submitted_item_stale_by_lastmod'
            : null;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private function normalizedContentHash(mixed $value): ?string
    {
        $hash = strtolower(trim((string) $value));

        return $hash === '' ? null : $hash;
    }

    /**
     * @return array{
     *     blocking_item:null,
     *     stale_submitted_items:list<array{id:int, approval_state:string, execution_state:string, stale_reason:string}>
     * }
     */
    private function emptyStatus(): array
    {
        return [
            'blocking_item' => null,
            'stale_submitted_items' => [],
        ];
    }
}
