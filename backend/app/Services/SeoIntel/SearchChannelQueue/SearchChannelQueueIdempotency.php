<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * @return array{id:int, approval_state:string, execution_state:string}|null
     */
    public function existingQueueItem(string $canonicalUrl, string $locale, string $channel, mixed $sourceLastmod = null, ?string $sourceContentHash = null): ?array
    {
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');

        try {
            if (! Schema::connection($connectionName)->hasTable('seo_search_channel_queue_items')) {
                return null;
            }

            $rows = DB::connection($connectionName)
                ->table('seo_search_channel_queue_items')
                ->where('url_hash', hash('sha256', $canonicalUrl))
                ->where('locale', $locale)
                ->where('channel', $channel)
                ->orderByDesc('id')
                ->get(['id', 'approval_state', 'execution_state', 'lastmod', 'content_hash', 'updated_at']);

            $sourceTimestamp = $this->timestamp($sourceLastmod);
            $normalizedSourceContentHash = $this->normalizedContentHash($sourceContentHash);

            foreach ($rows as $row) {
                if ($this->submittedItemIsOlderThanSource($row, $sourceTimestamp, $normalizedSourceContentHash)) {
                    continue;
                }

                return [
                    'id' => (int) $row->id,
                    'approval_state' => (string) $row->approval_state,
                    'execution_state' => (string) $row->execution_state,
                ];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function submittedItemIsOlderThanSource(object $row, ?int $sourceTimestamp, ?string $sourceContentHash): bool
    {
        if ((string) $row->execution_state !== 'submitted') {
            return false;
        }

        $itemContentHash = $this->normalizedContentHash($row->content_hash ?? null);
        if ($sourceContentHash !== null && $itemContentHash !== null && ! hash_equals($itemContentHash, $sourceContentHash)) {
            return true;
        }

        if ($sourceTimestamp === null) {
            return false;
        }

        $itemTimestamp = $this->timestamp($row->lastmod ?? null) ?? $this->timestamp($row->updated_at ?? null);

        return $itemTimestamp === null || $sourceTimestamp > $itemTimestamp;
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
}
