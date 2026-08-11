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
        {--org= : Required non-negative organization id}
        {--scale=* : Optional repeatable scale-code filter}
        {--locale=* : Optional repeatable locale filter}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read an organization-scoped privacy-safe backend measurement funnel without database or external writes.';

    public function handle(MeasurementFunnelReadModel $readModel): int
    {
        $orgId = $this->orgId();
        if ($orgId === null) {
            $this->emit([
                'schema_version' => MeasurementFunnelReadModel::SCHEMA_VERSION,
                'task' => MeasurementFunnelReadModel::TASK,
                'ok' => false,
                'status' => 'blocked',
                'issues' => ['org_id_invalid'],
                'org_id' => null,
                'rows' => [],
                'read_only' => true,
            ]);

            return self::FAILURE;
        }

        try {
            $report = $readModel->report(
                $orgId,
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
                'org_id' => $orgId,
                'rows' => [],
                'read_only' => true,
            ];
        }

        $this->emit($report);

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function emit(array $report): void
    {
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
    }

    private function orgId(): ?int
    {
        $value = filter_var(trim((string) $this->option('org')), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return is_int($value) ? $value : null;
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
