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
        {--cohort : Read the fixed-start 168-hour cohort instead of calendar activity}
        {--as-of= : Optional cohort observation cutoff in ISO 8601}
        {--previous-snapshot= : Optional prior privacy-safe cohort JSON report for revision comparison}
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
            $arguments = [
                $orgId,
                (string) $this->option('from'),
                (string) $this->option('to'),
                $this->arrayOption('scale'),
                $this->arrayOption('locale'),
            ];
            if ((bool) $this->option('cohort')) {
                $previous = null;
                $path = trim((string) $this->option('previous-snapshot'));
                if ($path !== '') {
                    if (! is_file($path) || filesize($path) > 4 * 1024 * 1024) {
                        throw new \InvalidArgumentException('Invalid previous snapshot.');
                    }
                    $previous = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($previous)) {
                        throw new \InvalidArgumentException('Invalid previous snapshot.');
                    }
                }
                $report = $readModel->startCohort(...[...$arguments, $this->option('as-of') ?: null, $previous]);
            } else {
                if ($this->option('as-of') || $this->option('previous-snapshot')) {
                    throw new \InvalidArgumentException('Cohort options require --cohort.');
                }
                $report = $readModel->report(...$arguments);
            }
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
            if (($report['schema_version'] ?? null) === \App\Services\Analytics\MeasurementStartCohortReadModel::VERSION) {
                foreach (['status', 'definition_version', 'as_of', 'snapshot_version'] as $field) {
                    $this->line($field.'='.(string) ($report[$field] ?? 'unknown'));
                }
                foreach ((array) ($report['totals'] ?? []) as $metric => $value) {
                    $this->line($metric.'='.(string) $value);
                }
                $this->line('finalized=false');
                $this->line('processing_grace_hours=unknown');

                return;
            }
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
