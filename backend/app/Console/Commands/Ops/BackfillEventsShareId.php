<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Jobs\Ops\BackfillEventsShareIdJob;
use Illuminate\Console\Command;

class BackfillEventsShareId extends Command
{
    protected $signature = 'ops:backfill-events-share-id
        {--sync : Run immediately in current process}';

    protected $description = 'Backfill events.share_id from legacy meta_json payload';

    public function handle(): int
    {
        if ((bool) $this->option('sync')) {
            (new BackfillEventsShareIdJob())->handle();
            $this->info('events share_id backfill completed (sync)');

            return self::SUCCESS;
        }

        BackfillEventsShareIdJob::dispatch()->onQueue('ops');
        $this->info('events share_id backfill dispatched');

        return self::SUCCESS;
    }
}
