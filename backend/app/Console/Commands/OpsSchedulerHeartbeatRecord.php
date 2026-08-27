<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\SchedulerHeartbeatService;
use Illuminate\Console\Command;

final class OpsSchedulerHeartbeatRecord extends Command
{
    protected $signature = 'ops:scheduler-heartbeat-record
        {--status= : started, completed, or overlap}
        {--exit-code= : Required only for completed}
        {--json : Emit sanitized JSON}';

    protected $description = 'Atomically record the managed cron scheduler heartbeat';

    public function handle(SchedulerHeartbeatService $service): int
    {
        $status = (string) $this->option('status');
        $rawExitCode = $this->option('exit-code');
        $exitCode = is_numeric($rawExitCode) ? (int) $rawExitCode : null;

        try {
            $payload = $service->record($status, $exitCode);
        } catch (\Throwable $exception) {
            $payload = ['ok' => false, 'reason' => 'record_failed'];
            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('scheduler_heartbeat_record_failed');
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('scheduler_heartbeat_recorded status='.$payload['status']);
        }

        return self::SUCCESS;
    }
}
