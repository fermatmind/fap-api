<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\StorageControlPlaneSnapshotService;
use Illuminate\Console\Command;

final class StorageControlPlaneSnapshot extends Command
{
    protected $signature = 'storage:control-plane-snapshot
        {--json : Emit the full control-plane snapshot payload as JSON}';

    protected $description = 'Persist a read-only storage control-plane snapshot and audit the capture.';

    public function __construct(
        private readonly StorageControlPlaneSnapshotService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->service->createSnapshot();

        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode control-plane snapshot json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return self::SUCCESS;
        }

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('snapshot='.(string) ($payload['snapshot_path'] ?? ''));
        $this->line('schema_version='.(string) ($payload['snapshot_schema'] ?? ''));
        $this->line('generated_at='.(string) ($payload['generated_at'] ?? ''));

        return self::SUCCESS;
    }
}
