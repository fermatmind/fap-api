<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Jobs\Ops\BackfillPiiEncryptionJob;
use Illuminate\Console\Command;

class BackfillPiiEncryption extends Command
{
    protected $signature = 'ops:backfill-pii-encryption
        {--scope=all : Backfill scope: all|users|email_outbox}
        {--chunk=1000 : Chunk size}
        {--sleep-ms=50 : Pause between chunks in milliseconds}
        {--sync : Run immediately in current process}';

    protected $description = 'Backfill encrypted/hash PII tracks for users and email_outbox';

    public function handle(): int
    {
        $job = new BackfillPiiEncryptionJob(
            scope: trim((string) $this->option('scope')),
            chunk: (int) $this->option('chunk'),
            sleepMs: (int) $this->option('sleep-ms')
        );

        if ((bool) $this->option('sync')) {
            $job->handle();
            $this->info('pii encryption backfill completed (sync)');

            return self::SUCCESS;
        }

        BackfillPiiEncryptionJob::dispatch(
            scope: trim((string) $this->option('scope')),
            chunk: (int) $this->option('chunk'),
            sleepMs: (int) $this->option('sleep-ms')
        )->onQueue('ops');

        $this->info('pii encryption backfill dispatched');

        return self::SUCCESS;
    }
}
