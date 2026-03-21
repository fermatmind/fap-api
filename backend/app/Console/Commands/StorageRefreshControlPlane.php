<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\StorageControlPlaneRefreshService;
use Illuminate\Console\Command;

final class StorageRefreshControlPlane extends Command
{
    protected $signature = 'storage:refresh-control-plane
        {--dry-run : Execute the fixed safe dry-run refresh batch}
        {--json : Emit the full refresh payload as JSON}';

    protected $description = 'Manually refresh safe storage control-plane dry-run truth and then snapshot it.';

    public function __construct(
        private readonly StorageControlPlaneRefreshService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) $this->option('dry-run')) {
            $this->error('--dry-run is required. PR-28 only implements manual dry-run orchestration.');

            return self::FAILURE;
        }

        $payload = $this->service->run();

        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode control-plane refresh json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return (string) ($payload['status'] ?? 'failure') === 'success'
                ? self::SUCCESS
                : self::FAILURE;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->line('status='.(string) ($payload['status'] ?? 'failure'));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('schema='.(string) ($payload['schema'] ?? ''));
        $this->line('generated_at='.(string) ($payload['generated_at'] ?? ''));
        $this->line('step_count='.(int) ($payload['step_count'] ?? 0));
        $this->line('successful_step_count='.(int) ($summary['successful_step_count'] ?? 0));
        $this->line('failed_step='.(string) ($payload['failed_step'] ?? ''));
        $this->line('snapshot_path='.(string) ($payload['snapshot_path'] ?? ''));

        return (string) ($payload['status'] ?? 'failure') === 'success'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
