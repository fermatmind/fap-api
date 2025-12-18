<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

class FapWeeklyReport extends Command
{
    /**
     * 用法：
     *   php artisan fap:weekly-report
     *   php artisan fap:weekly-report --days=7 --by-type
     */
    protected $signature = 'fap:weekly-report
        {--days=7 : Lookback window in days (UTC)}
        {--by-type : Also show breakdown by type_code}
        {--limit=32 : Max rows for by-type table}';

    protected $description = 'Weekly funnel stats (attempt-deduped): result_view / share_click / share_generate';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        // ✅ 用 UTC 口径，和你手动 SQL 的 UTC_TIMESTAMP 保持一致
        $since = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        /**
         * per_attempt：每个 attempt_id 只记 0/1（去重漏斗）
         * - type_code：从 meta_json 里取（空串转 NULL）
         * - rv/sc/sg：用 MAX(bool) 得到 0/1
         */
        $perAttemptSql = "
            SELECT
              attempt_id,
              MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.type_code')), '')) AS type_code,
              MAX(event_code='result_view')    AS rv,
              MAX(event_code='share_click')    AS sc,
              MAX(event_code='share_generate') AS sg
            FROM events
            WHERE occurred_at >= ?
              AND event_code IN ('result_view','share_click','share_generate')
            GROUP BY attempt_id
        ";

        /**
         * ✅ overall（漏斗内口径）
         * - 分母：rv=1 的 attempt
         * - sc_attempts：只统计 rv=1 的 attempt 中 sc=1
         * - sg_attempts：只统计 rv=1 的 attempt 中 sg=1
         * - sg/sc：只在 rv=1 且 sc=1 的 attempt 中统计 sg
         */
        $overallSql = "
            SELECT
              SUM(rv) AS result_view_attempts,

              SUM(CASE WHEN rv=1 THEN sc ELSE 0 END) AS share_click_attempts,
              SUM(CASE WHEN rv=1 THEN sg ELSE 0 END) AS share_generate_attempts,

              ROUND(
                SUM(CASE WHEN rv=1 THEN sc ELSE 0 END) / NULLIF(SUM(rv),0),
                4
              ) AS share_click_rate,

              ROUND(
                SUM(CASE WHEN rv=1 AND sc=1 THEN sg ELSE 0 END) / NULLIF(SUM(CASE WHEN rv=1 THEN sc ELSE 0 END),0),
                4
              ) AS share_generate_per_click,

              ROUND(
                SUM(CASE WHEN rv=1 THEN sg ELSE 0 END) / NULLIF(SUM(rv),0),
                4
              ) AS share_generate_rate
            FROM ({$perAttemptSql}) t
        ";

        $overall = DB::selectOne($overallSql, [$since]);

        $this->info("FAP Weekly Report (attempt-deduped, funnel-only)");
        $this->line("Window (UTC): last {$days} day(s), since {$since}");

        $this->table(
            [
                'result_view_attempts',
                'share_click_attempts',
                'share_generate_attempts',
                'share_click_rate',
                'share_generate_per_click',
                'share_generate_rate',
            ],
            [[
                (int) ($overall->result_view_attempts ?? 0),
                (int) ($overall->share_click_attempts ?? 0),
                (int) ($overall->share_generate_attempts ?? 0),
                $overall->share_click_rate,
                $overall->share_generate_per_click,
                $overall->share_generate_rate,
            ]]
        );

        // by type_code（可选）
        if ($this->option('by-type')) {
            /**
             * ✅ by-type（漏斗内口径）
             * 注意：这里的 rate 也按漏斗内做，避免历史缺 rv 的数据冲爆比率
             */
            $byTypeSql = "
                SELECT
                  type_code,

                  SUM(rv) AS result_view_attempts,
                  SUM(CASE WHEN rv=1 THEN sc ELSE 0 END) AS share_click_attempts,
                  SUM(CASE WHEN rv=1 THEN sg ELSE 0 END) AS share_generate_attempts,

                  ROUND(
                    SUM(CASE WHEN rv=1 THEN sc ELSE 0 END) / NULLIF(SUM(rv),0),
                    4
                  ) AS share_click_rate,

                  ROUND(
                    SUM(CASE WHEN rv=1 AND sc=1 THEN sg ELSE 0 END) / NULLIF(SUM(CASE WHEN rv=1 THEN sc ELSE 0 END),0),
                    4
                  ) AS share_generate_per_click,

                  ROUND(
                    SUM(CASE WHEN rv=1 THEN sg ELSE 0 END) / NULLIF(SUM(rv),0),
                    4
                  ) AS share_generate_rate
                FROM ({$perAttemptSql}) t
                WHERE type_code IS NOT NULL AND type_code <> ''
                GROUP BY type_code
                HAVING SUM(rv) > 0
                ORDER BY share_generate_rate DESC, result_view_attempts DESC
                LIMIT {$limit}
            ";

            $rows = DB::select($byTypeSql, [$since]);

            $this->line("");
            $this->info("Breakdown by type_code (top {$limit})");

            $tableRows = array_map(function ($r) {
                return [
                    $r->type_code,
                    (int) $r->result_view_attempts,
                    (int) $r->share_click_attempts,
                    (int) $r->share_generate_attempts,
                    $r->share_click_rate,
                    $r->share_generate_per_click,
                    $r->share_generate_rate,
                ];
            }, $rows);

            $this->table(
                ['type_code', 'rv_attempts', 'sc_attempts', 'sg_attempts', 'sc/rv', 'sg/sc', 'sg/rv'],
                $tableRows
            );
        }

        return self::SUCCESS;
    }
}