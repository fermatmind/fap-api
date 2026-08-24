<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\GscReadModelSyncService;
use Illuminate\Console\Command;

final class SeoIntelGscSyncCommand extends Command
{
    protected $signature = 'seo-intel:gsc-sync
        {--window=28 : Import window: 7, 28, or 90 days}
        {--search-types=web : Comma-separated readonly Search Analytics types}
        {--full-window : Fetch the complete requested window instead of the incremental overlap date}
        {--json : Emit JSON}';

    protected $description = 'Incrementally import real readonly GSC Search Analytics rows into the SEO read model.';

    public function handle(GscReadModelSyncService $sync): int
    {
        $window = (int) $this->option('window');
        $searchTypes = array_values(array_filter(array_map(
            static fn (string $value): string => trim(mb_strtolower($value, 'UTF-8')),
            explode(',', (string) $this->option('search-types')),
        )));
        $result = $sync->sync($window, $searchTypes, (bool) $this->option('full-window'));
        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ((bool) $this->option('json')) {
            $this->line(is_string($encoded) ? $encoded : '{}');
        } else {
            $this->line('status='.(string) ($result['status'] ?? 'blocked'));
            $this->line('issue='.(string) ($result['issue'] ?? 'none'));
            $this->line('rows_upserted='.(string) ($result['rows_upserted'] ?? 0));
        }

        return ($result['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
