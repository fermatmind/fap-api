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
        if (! (bool) config('seo_intel.enabled', false) || ! (bool) config('seo_intel.write_enabled', false)) {
            return;
        }

        $arguments = [
            $event->pageEntityType,
            $event->entityIdentity,
            $event->locale,
            $event->revision,
            $event->change,
        ];

        $job = new SyncPublicAuthorityUrlTruth(...$arguments);
        if ((bool) config('seo_intel.incremental_sync_inline', false)) {
            $job->handle(app(IncrementalUrlTruthSyncService::class));

            return;
        }

        dispatch($job);
    }
}
