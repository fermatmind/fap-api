<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\MeasurementFailureCohortReadModel;
use Illuminate\Console\Command;
use Throwable;

final class MeasurementFailureCohortsReportCommand extends Command
{
    protected $signature = 'analytics:measurement-failure-cohorts-report
        {--from= : Required inclusive report date, YYYY-MM-DD}
        {--to= : Required inclusive report date, YYYY-MM-DD}
        {--scale=* : Optional repeatable scale-code filter}
        {--form=* : Optional repeatable form-code filter}
        {--locale=* : Optional repeatable locale filter}
        {--device=* : Optional repeatable safe device-class filter}
        {--browser=* : Optional repeatable safe browser-class filter}
        {--endpoint=* : Optional repeatable safe endpoint-class filter}
        {--status=* : Optional repeatable safe status-group filter}
        {--error=* : Optional repeatable safe error-class filter}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read privacy-safe assessment failure cohorts without database or external writes.';

    public function handle(MeasurementFailureCohortReadModel $readModel): int
    {
        try {
            $report = $readModel->report(
                (string) $this->option('from'),
                (string) $this->option('to'),
                [
                    'scale_code' => $this->arrayOption('scale'),
                    'form_code' => $this->arrayOption('form'),
                    'locale' => $this->arrayOption('locale'),
                    'device_class' => $this->arrayOption('device'),
                    'browser_class' => $this->arrayOption('browser'),
                    'endpoint_class' => $this->arrayOption('endpoint'),
                    'status_group' => $this->arrayOption('status'),
                    'error_class' => $this->arrayOption('error'),
                ],
            );
        } catch (Throwable) {
            $report = [
                'schema_version' => MeasurementFailureCohortReadModel::SCHEMA_VERSION,
                'task' => MeasurementFailureCohortReadModel::TASK,
                'ok' => false,
                'status' => 'blocked',
                'issues' => ['measurement_failure_cohort_read_failed'],
                'rows' => [],
                'cohorts' => [],
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
            foreach (['questions_load_failure', 'submit_failure'] as $eventName) {
                foreach ([
                    'eligible_attempt_count',
                    'failed_attempt_count',
                    'retrying_attempt_count',
                    'eventual_success_attempt_count',
                    'terminal_failure_attempt_count',
                    'failure_event_count',
                    'duplicate_failure_event_count',
                    'unattributed_failure_event_count',
                    'eligible_failure_rate',
                    'eventual_success_rate',
                    'recovery_time_p50_seconds',
                    'recovery_time_p95_seconds',
                    'coverage_status',
                ] as $metric) {
                    $value = data_get($report, 'cohorts.'.$eventName.'.'.$metric);
                    $this->line($eventName.'.'.$metric.'='.($value === null ? 'null' : (string) $value));
                }
            }
            foreach ((array) ($report['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
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
