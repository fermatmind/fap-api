<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PublicAuthorityChanged;
use App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth;
use App\Services\SeoIntel\UrlTruth\IncrementalUrlTruthSyncService;

final class QueueUrlTruthIncrementalSync
{
    public function handle(PublicAuthorityChanged $event): void
    {
        if (! (bool) config('seo_intel.enabled', false)) {
            return;
        }

        $scoped = ! (bool) config('seo_intel.write_enabled', false);
        if ($scoped && ! in_array($event->pageEntityType, ['article', 'career_guide', 'career_job'], true)) {
            return;
        }
        if ($scoped && (bool) config('seo_intel.incremental_sync_inline', false)) {
            throw new \RuntimeException('SCOPED_URL_TRUTH_INLINE_FORBIDDEN');
        }
        $arguments = [
            $event->pageEntityType,
            $event->entityIdentity,
            $event->locale,
            $event->revision,
            $event->change,
            $scoped,
        ];

        $job = new SyncPublicAuthorityUrlTruth(...$arguments);
        if ((bool) config('seo_intel.incremental_sync_inline', false)) {
            $job->handle(app(IncrementalUrlTruthSyncService::class));

            return;
        }

        dispatch($job);
    }
}
