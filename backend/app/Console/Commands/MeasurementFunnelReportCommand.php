<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\MeasurementFunnelReadModel;
use Illuminate\Console\Command;
use Throwable;

final class MeasurementFunnelReportCommand extends Command
{
    protected $signature = 'analytics:measurement-funnel-report
        {--from= : Required inclusive report date, YYYY-MM-DD}
        {--to= : Required inclusive report date, YYYY-MM-DD}
        {--scale=* : Optional repeatable scale-code filter}
        {--locale=* : Optional repeatable locale filter}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read the privacy-safe backend measurement funnel without database or external writes.';

    public function handle(MeasurementFunnelReadModel $readModel): int
    {
        try {
            $report = $readModel->report(
                (string) $this->option('from'),
                (string) $this->option('to'),
                $this->arrayOption('scale'),
                $this->arrayOption('locale'),
            );
        } catch (Throwable) {
            $report = [
                'schema_version' => MeasurementFunnelReadModel::SCHEMA_VERSION,
                'task' => MeasurementFunnelReadModel::TASK,
                'ok' => false,
                'status' => 'blocked',
                'issues' => ['measurement_funnel_read_failed'],
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
            foreach ([
                'attempt_started_count',
                'test_completed_count',
                'result_ready_count',
                'result_ready_event_count',
                'result_ready_duplicate_event_count',
                'result_ready_event_coverage_status',
            ] as $metric) {
                $this->line($metric.'='.(string) data_get($report, 'totals.'.$metric, 0));
            }
            foreach ((array) ($report['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function arrayOption(string $name): array
    {
        $value = $this->option($name);
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? [] : [$normalized];
    }
}
