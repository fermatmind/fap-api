<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Jobs\Ops\BackfillFmTokenHashJob;
use Illuminate\Console\Command;

class BackfillFmTokenHash extends Command
{
    protected $signature = 'ops:backfill-fm-token-hash
        {--sync : Run immediately in current process}';

    protected $description = 'Backfill fm_tokens.token_hash in throttled batches';

    public function handle(): int
    {
        if ((bool) $this->option('sync')) {
            (new BackfillFmTokenHashJob())->handle();
            $this->info('fm token hash backfill completed (sync)');
            return self::SUCCESS;
        }

        BackfillFmTokenHashJob::dispatch()->onQueue('ops');
        $this->info('fm token hash backfill dispatched');

        return self::SUCCESS;
    }
}
