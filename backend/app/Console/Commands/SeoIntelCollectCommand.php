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
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Run a disabled-by-default Search Intelligence collector skeleton.';

    public function handle(SeoIntelCollectorManager $manager): int
    {
        $dryRun = $this->option('dry-run') || (bool) config('seo_intel.dry_run_default', true);
        $collector = (string) $this->option('collector');

        $result = $manager->collect($collector, [
            'dry_run' => $dryRun,
            'no_write' => (bool) $this->option('no-write'),
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
