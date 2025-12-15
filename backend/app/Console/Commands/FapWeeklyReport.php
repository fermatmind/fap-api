<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FapWeeklyReport extends Command
{
    /**
     * 命令签名：
     *   php artisan fap:weekly-report --days=7
     *   php artisan fap:weekly-report          (默认 7 天)
     */
    protected $signature = 'fap:weekly-report {--days=7}';

    protected $description = 'Show simple weekly stats from events table for Fermat Assessment Platform';

    public function handle(): int
    {
        // 1）解析天数参数
        $days = (int) $this->option('days') ?: 7;
        if ($days < 1) {
            $days = 1;
        }

        // 2）计算时间范围：今天 + 往前 (days - 1) 天
        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $this->line('');
        $this->info("FAP events in last {$days} days (since {$start->toDateTimeString()}):");
        $this->line(str_repeat('-', 60));

        // 3）按 event_code 聚合计数
        $stats = Event::whereBetween('occurred_at', [$start, $end])
            ->select('event_code', DB::raw('COUNT(*) as total'))
            ->groupBy('event_code')
            ->orderBy('event_code')
            ->pluck('total', 'event_code');

        // 4）从事件明细里推导几个关键指标
        $scaleView     = $stats['scale_view']     ?? 0;
        $testStart     = $stats['test_start']     ?? 0;
        $testSubmit    = $stats['test_submit']    ?? 0;
        $resultView    = $stats['result_view']    ?? 0;
        $shareGenerate = $stats['share_generate'] ?? 0;

        // 总事件数
        $totalEvents = $stats->sum();

        // 最近 N 天「提交人数」（去重 anon_id）
        $submitUsers = Event::whereBetween('occurred_at', [$start, $end])
            ->where('event_code', 'test_submit')
            ->distinct('anon_id')
            ->count('anon_id');

        // 5）打印一张“D1 周报指标表”
        $headers = ['Metric', 'Value'];

        $rows = [
            ['scale_view (量表页曝光数)',         $scaleView],
            ['test_start (开始作答数)',           $testStart],
            ['test_submit (完成提交数)',          $testSubmit],
            ['result_view (结果页查看数)',        $resultView],
            ['share_generate (分享卡生成数)',     $shareGenerate],
            ['TOTAL events (上述事件总和)',       $totalEvents],
            ['Unique submit anon_ids (提交人数)', $submitUsers],
        ];

        $this->table($headers, $rows);

        // 6）顺手也把原始按 event_code 的明细打一张表（方便以后扩展新事件）
        if ($stats->isNotEmpty()) {
            $this->line('');
            $this->info('Raw events breakdown by event_code:');

            $rawRows = [];
            foreach ($stats as $code => $count) {
                $rawRows[] = [$code, $count];
            }

            $this->table(['event_code', 'count'], $rawRows);
        } else {
            $this->warn('No events found in the given time range.');
        }

        return Command::SUCCESS;
    }
}