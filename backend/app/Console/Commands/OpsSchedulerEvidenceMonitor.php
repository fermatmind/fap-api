<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\SchedulerEvidenceMonitorService;
use Illuminate\Console\Command;

final class OpsSchedulerEvidenceMonitor extends Command
{
    protected $signature = 'ops:scheduler-evidence-monitor
        {--notify : Send deduplicated transition alerts}
        {--json : Emit the sanitized monitor receipt}';

    protected $description = 'Read and verify scheduler heartbeat and natural weekly evidence';

    public function handle(SchedulerEvidenceMonitorService $service): int
    {
        try {
            $receipt = $service->evaluate((bool) $this->option('notify'));
        } catch (\Throwable) {
            $receipt = [
                'schema_version' => SchedulerEvidenceMonitorService::CONTRACT_VERSION,
                'status' => 'fail',
                'reason' => 'monitor_failed',
                'read_only' => true,
                'deployment_action' => false,
                'lkg_action' => false,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('scheduler_evidence_monitor status='.(string) ($receipt['status'] ?? 'fail'));
        }

        return ($receipt['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
