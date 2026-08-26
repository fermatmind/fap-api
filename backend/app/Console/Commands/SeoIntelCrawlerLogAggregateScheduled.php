<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogScheduledAggregateCollector;
use Illuminate\Console\Command;

final class SeoIntelCrawlerLogAggregateScheduled extends Command
{
    protected $signature = 'seo-intel:crawler-log-aggregate-scheduled {--json : Output sanitized machine-readable JSON}';

    protected $description = 'Collect a gated sanitized crawler aggregate from the approved production source.';

    public function handle(CrawlerLogScheduledAggregateCollector $collector): int
    {
        $result = $collector->collect();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status='.(string) ($result['status'] ?? 'MEASUREMENT_HOLD'));
        }

        return ($result['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
