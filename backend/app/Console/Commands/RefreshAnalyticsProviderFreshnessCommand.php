<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\ProviderFreshness\ProviderFreshnessService;
use Illuminate\Console\Command;

final class RefreshAnalyticsProviderFreshnessCommand extends Command
{
    protected $signature = 'analytics:refresh-provider-freshness {--json : Emit a machine-readable snapshot} {--dry-run : Fetch and reconcile without writing cache}';

    protected $description = 'Refresh read-only GA4/Baidu aggregate freshness and reconcile it with backend global activity.';

    public function handle(ProviderFreshnessService $service): int
    {
        $snapshot = $service->refresh(! (bool) $this->option('dry-run'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('Provider freshness: '.($snapshot['reconciliation']['status'] ?? 'unknown'));
        }

        return in_array($snapshot['reconciliation']['status'] ?? 'unknown', ['degraded', 'stale', 'investigate'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
