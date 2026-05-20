<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

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
}
