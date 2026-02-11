<?php

namespace App\Console\Commands\Ops;

use App\Jobs\Ops\BackfillOrgIdJob;
use Illuminate\Console\Command;

class RunBackfillOrgId extends Command
{
    protected $signature = 'ops:run-backfill-org-id
        {table : Target table name}
        {--id-column=id : Primary key / cursor column}
        {--org-id-column=org_id : Org id column}
        {--batch-size=1000 : Chunk size}
        {--progress-key= : Override progress key}
        {--sync : Run immediately in current process}';

    protected $description = 'Run throttled + resumable org_id backfill safely';

    public function handle(): int
    {
        $table = trim((string) $this->argument('table'));
        $idColumn = trim((string) $this->option('id-column'));
        $orgIdColumn = trim((string) $this->option('org-id-column'));
        $batchSize = (int) $this->option('batch-size');
        $progressKey = trim((string) $this->option('progress-key'));

        if ($table === '' || $idColumn === '' || $orgIdColumn === '') {
            $this->error('table / id-column / org-id-column cannot be empty');
            return self::FAILURE;
        }

        if ($batchSize <= 0) {
            $this->error('batch-size must be > 0');
            return self::FAILURE;
        }

        $job = new BackfillOrgIdJob(
            table: $table,
            idColumn: $idColumn,
            orgIdColumn: $orgIdColumn,
            batchSize: $batchSize,
            progressKey: $progressKey !== '' ? $progressKey : null
        );

        if ((bool) $this->option('sync')) {
            $job->handle();
            $this->info('backfill completed (sync)');
            return self::SUCCESS;
        }

        dispatch($job);
        $this->info('backfill dispatched');

        return self::SUCCESS;
    }
}
