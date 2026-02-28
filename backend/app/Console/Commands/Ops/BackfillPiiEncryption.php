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
        {--rotate-key-version= : Rotate encrypted payloads to target key_version}
        {--batch= : Rotation audit batch reference}
        {--sync : Run immediately in current process}';

    protected $description = 'Backfill encrypted/hash PII tracks for users and email_outbox';

    public function handle(): int
    {
        $rotateKeyVersion = $this->resolveRotateKeyVersion();
        if ($rotateKeyVersion !== null && ! (bool) $this->option('sync')) {
            $this->error('--rotate-key-version requires --sync');

            return self::FAILURE;
        }

        $job = new BackfillPiiEncryptionJob(
            scope: trim((string) $this->option('scope')),
            chunk: (int) $this->option('chunk'),
            sleepMs: (int) $this->option('sleep-ms'),
            rotateKeyVersion: $rotateKeyVersion,
            batchRef: $this->resolveBatchRef()
        );

        if ((bool) $this->option('sync')) {
            $job->handle();
            $this->info('pii encryption backfill completed (sync)');

            return self::SUCCESS;
        }

        BackfillPiiEncryptionJob::dispatch(
            scope: trim((string) $this->option('scope')),
            chunk: (int) $this->option('chunk'),
            sleepMs: (int) $this->option('sleep-ms'),
            rotateKeyVersion: $rotateKeyVersion,
            batchRef: $this->resolveBatchRef()
        )->onQueue('ops');

        $this->info('pii encryption backfill dispatched');

        return self::SUCCESS;
    }

    private function resolveRotateKeyVersion(): ?int
    {
        $raw = trim((string) ($this->option('rotate-key-version') ?? ''));
        if ($raw === '') {
            return null;
        }

        $version = (int) $raw;

        return $version > 0 ? $version : null;
    }

    private function resolveBatchRef(): ?string
    {
        $batch = trim((string) ($this->option('batch') ?? ''));

        return $batch !== '' ? $batch : null;
    }
}
