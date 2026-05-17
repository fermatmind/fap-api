<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class CrawlerLogDailyAggregator
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     lines_seen: int,
     *     lines_parsed: int,
     *     bot_family_counts: array<string, int>,
     *     source_engine_counts: array<string, int>,
     *     private_flow_hits: int,
     *     noindex_hits: int,
     *     daily_rows: list<array<string, mixed>>
     * }
     */
    public function aggregate(array $rows): array
    {
        $botFamilyCounts = [];
        $sourceEngineCounts = [];
        $privateFlowHits = 0;
        $noindexHits = 0;
        $dailyRows = [];

        foreach ($rows as $row) {
            $botFamily = (string) ($row['bot_family'] ?? 'unknown_bot');
            $sourceEngine = (string) ($row['source_engine'] ?? 'unknown');
            $botFamilyCounts[$botFamily] = ($botFamilyCounts[$botFamily] ?? 0) + 1;
            $sourceEngineCounts[$sourceEngine] = ($sourceEngineCounts[$sourceEngine] ?? 0) + 1;

            if ((bool) ($row['private_flow_hit'] ?? false)) {
                $privateFlowHits++;
            }

            if ((bool) ($row['noindex_hit'] ?? false)) {
                $noindexHits++;
            }

            $key = implode('|', [
                $row['report_date'] ?? 'unknown',
                $row['path_hash'] ?? 'unknown',
                $sourceEngine,
                $botFamily,
                (string) ($row['status_code'] ?? 'unknown'),
                (string) ($row['response_time_bucket'] ?? 'unknown'),
            ]);

            if (! isset($dailyRows[$key])) {
                $dailyRows[$key] = $row + ['crawl_count' => 0];
            }

            $dailyRows[$key]['crawl_count'] = ((int) $dailyRows[$key]['crawl_count']) + 1;
        }

        return [
            'lines_seen' => count($rows),
            'lines_parsed' => count($rows),
            'bot_family_counts' => $botFamilyCounts,
            'source_engine_counts' => $sourceEngineCounts,
            'private_flow_hits' => $privateFlowHits,
            'noindex_hits' => $noindexHits,
            'daily_rows' => array_values($dailyRows),
        ];
    }
}
