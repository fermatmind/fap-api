<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class FapWeeklyReport extends Command
{
    /**
     * 命令签名：
     * php artisan fap:weekly-report
     * 或者：php artisan fap:weekly-report --days=30
     */
    protected $signature = 'fap:weekly-report {--days=7 : Lookback days}';

    /**
     * 命令描述：php artisan list 里会显示
     */
    protected $description = 'Show FAP event stats for last N days (default 7).';

    /**
     * 命令主入口
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('Option --days must be a positive integer.');
            return 1;
        }

        $since = now()->subDays($days);

        // 聚合统计
        $stats = Event::select('event_code', DB::raw('COUNT(*) as total'))
            ->where('occurred_at', '>=', $since)
            ->groupBy('event_code')
            ->orderBy('event_code')
            ->pluck('total', 'event_code')
            ->toArray();

        if (empty($stats)) {
            $this->info("No events in the last {$days} days (since {$since->toDateTimeString()}).");
            return 0;
        }

        // 组织成表格
        $rows = [];
        $sum  = 0;
        foreach ($stats as $code => $count) {
            $rows[] = [$code, $count];
            $sum += $count;
        }

        $this->info("FAP events in last {$days} days (since {$since->toDateTimeString()}):");
        $this->table(['Event code', 'Count'], $rows);
        $this->line("Total events: {$sum}");

        return 0;
    }
}