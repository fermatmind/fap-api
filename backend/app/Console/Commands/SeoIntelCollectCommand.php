<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SeoIntelCollectorManager;
use Illuminate\Console\Command;

final class SeoIntelCollectCommand extends Command
{
    protected $signature = 'seo-intel:collect
        {--collector=noop : Collector name to run}
        {--dry-run : Force dry-run mode}
        {--no-write : Prevent writes even when future collectors are enabled}
        {--json : Output safe machine-readable JSON}
        {--limit= : Bound collectors that support deterministic canaries}
        {--locale= : Filter collectors that support locale scoping}
        {--page-type= : Filter collectors that support page entity type scoping}
        {--canary : Use a deterministic bounded canary subset when supported}
        {--materialize-detector-queues : Authorize only detector_foundation queue materialization and idempotent readback}
        {--gsc-live-preflight : Run the GSC read-only live adapter credential/readiness preflight without live API calls}
        {--gsc-live-read : Execute a bounded GSC read-only live read without writes, queues, or submissions}
        {--start-date= : GSC live read start date, YYYY-MM-DD}
        {--end-date= : GSC live read end date, YYYY-MM-DD}
        {--dimensions= : Comma-separated GSC dimensions, default query,page}';

    protected $description = 'Run a bounded Search Intelligence collector.';

    public function handle(SeoIntelCollectorManager $manager): int
    {
        $collector = (string) $this->option('collector');
        $materializeDetectorQueues = (bool) $this->option('materialize-detector-queues');
        if ($materializeDetectorQueues && ($collector !== 'detector_foundation' || $this->option('dry-run') || $this->option('no-write'))) {
            $this->error('Detector queue materialization requires collector=detector_foundation without --dry-run or --no-write.');

            return self::FAILURE;
        }
        $dryRun = $materializeDetectorQueues
            ? false
            : ($this->option('dry-run') || (bool) config('seo_intel.dry_run_default', true));

        $result = $manager->collect($collector, [
            'dry_run' => $dryRun,
            'no_write' => (bool) $this->option('no-write'),
            'limit' => $this->option('limit'),
            'locale' => $this->option('locale'),
            'page_type' => $this->option('page-type'),
            'canary' => (bool) $this->option('canary'),
            'detector_materialization_authorized' => $materializeDetectorQueues,
            'gsc_live_preflight' => (bool) $this->option('gsc-live-preflight'),
            'gsc_live_read' => (bool) $this->option('gsc-live-read'),
            'start_date' => $this->option('start-date'),
            'end_date' => $this->option('end-date'),
            'dimensions' => $this->option('dimensions'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('collector='.$result->collector);
            $this->line('status='.$result->status);
            $this->line('dry_run='.($result->dryRun ? '1' : '0'));
            $this->line('writes_attempted='.($result->writesAttempted ? '1' : '0'));
            $this->line('writes_committed='.($result->writesCommitted ? '1' : '0'));
            $this->line('external_calls_attempted='.($result->externalCallsAttempted ? '1' : '0'));
        }

        return $result->status === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
