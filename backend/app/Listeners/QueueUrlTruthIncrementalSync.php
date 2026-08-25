<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PublicAuthorityChanged;
use App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth;

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

        if ((bool) config('seo_intel.incremental_sync_inline', false)) {
            SyncPublicAuthorityUrlTruth::dispatchSync(...$arguments);

            return;
        }

        SyncPublicAuthorityUrlTruth::dispatch(...$arguments);
    }
}
