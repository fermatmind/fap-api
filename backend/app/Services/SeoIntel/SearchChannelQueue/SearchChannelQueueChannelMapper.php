<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

final class SearchChannelQueueChannelMapper
{
    /**
     * @return list<string>
     */
    public function channels(?string $channel = null): array
    {
        $allowed = array_values(config('seo_intel.search_channel_queue.allowed_channels', []));

        if ($channel === null || $channel === '') {
            return $allowed;
        }

        return in_array($channel, $allowed, true) ? [$channel] : [];
    }
}
