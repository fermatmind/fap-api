<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\SchedulerHeartbeatService;
use Illuminate\Console\Command;

final class OpsSchedulerHeartbeatCheck extends Command
{
    protected $signature = 'ops:scheduler-heartbeat-check
        {--max-age-seconds=180 : Maximum accepted heartbeat age}
        {--json : Emit sanitized JSON}';

    protected $description = 'Fail closed unless the managed cron scheduler heartbeat is healthy';

    public function handle(SchedulerHeartbeatService $service): int
    {
        $maxAge = filter_var($this->option('max-age-seconds'), FILTER_VALIDATE_INT);
        if (! is_int($maxAge)) {
            return self::INVALID;
        }

        try {
            $payload = $service->check($maxAge);
        } catch (\Throwable) {
            $payload = ['ok' => false, 'reason' => 'check_failed'];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('scheduler_heartbeat status='.(($payload['ok'] ?? false) ? 'healthy' : 'failed'));
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
