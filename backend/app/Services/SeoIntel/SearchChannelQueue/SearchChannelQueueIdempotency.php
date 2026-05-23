<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SearchChannelQueueIdempotency
{
    public function key(string $canonicalUrl, string $locale, string $channel): string
    {
        return hash('sha256', implode('|', [
            strtolower(trim($canonicalUrl)),
            strtolower(trim($locale)),
            strtolower(trim($channel)),
        ]));
    }

    /**
     * @return array{id:int, approval_state:string, execution_state:string}|null
     */
    public function existingQueueItem(string $canonicalUrl, string $locale, string $channel): ?array
    {
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');

        try {
            if (! Schema::connection($connectionName)->hasTable('seo_search_channel_queue_items')) {
                return null;
            }

            $row = DB::connection($connectionName)
                ->table('seo_search_channel_queue_items')
                ->where('idempotency_key', $this->key($canonicalUrl, $locale, $channel))
                ->select(['id', 'approval_state', 'execution_state'])
                ->first();

            if ($row === null) {
                return null;
            }

            return [
                'id' => (int) $row->id,
                'approval_state' => (string) $row->approval_state,
                'execution_state' => (string) $row->execution_state,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
