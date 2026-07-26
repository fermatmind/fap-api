<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\SearchToResultFunnelReadModel;
use Illuminate\Console\Command;
use Throwable;

final class SeoIntelSearchToResultFunnelReportCommand extends Command
{
    protected $signature = 'seo-intel:search-to-result-funnel-report
        {--from= : Required inclusive report date, YYYY-MM-DD}
        {--to= : Required inclusive report date, YYYY-MM-DD}
        {--page-family= : Optional backend URL Truth page family}
        {--source-engine= : Optional source engine}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read the privacy-safe GSC page-to-product funnel without database or external writes.';

    public function handle(SearchToResultFunnelReadModel $readModel): int
    {
        try {
            $report = $readModel->report(
                (string) $this->option('from'),
                (string) $this->option('to'),
                $this->nullableOption('page-family'),
                $this->nullableOption('source-engine'),
            );
        } catch (Throwable) {
            $report = [
                'schema_version' => SearchToResultFunnelReadModel::SCHEMA_VERSION,
                'task' => SearchToResultFunnelReadModel::TASK,
                'ok' => false,
                'status' => 'blocked',
                'issues' => ['search_to_result_funnel_read_failed'],
                'rows' => [],
                'read_only' => true,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('status='.(string) ($report['status'] ?? 'blocked'));
            $this->line('row_count='.(string) ($report['row_count'] ?? 0));
            $this->line('impressions='.(string) data_get($report, 'totals.impressions', 0));
            $this->line('start_test_count='.(string) data_get($report, 'totals.start_test_count', 0));
            $this->line('complete_test_count='.(string) data_get($report, 'totals.complete_test_count', 0));
            $this->line('view_result_count='.(string) data_get($report, 'totals.view_result_count', 0));
            $this->line('valid_product_start_count='.(string) data_get(
                $report,
                'totals.valid_product_start_count',
                0,
            ));

            foreach ((array) ($report['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }
}
