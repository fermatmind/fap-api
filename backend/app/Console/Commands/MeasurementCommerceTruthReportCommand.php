<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\MeasurementCommerceTruthReadModel;
use Illuminate\Console\Command;
use Throwable;

final class MeasurementCommerceTruthReportCommand extends Command
{
    protected $signature = 'analytics:measurement-commerce-truth-report
        {--from= : Required inclusive report date, YYYY-MM-DD}
        {--to= : Required inclusive report date, YYYY-MM-DD}
        {--scale=* : Optional repeatable scale-code filter}
        {--form=* : Optional repeatable form-code filter}
        {--locale=* : Optional repeatable locale filter}
        {--channel=* : Optional repeatable safe commerce-channel filter}
        {--provider=* : Optional repeatable safe provider-class filter}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read privacy-safe commerce business truth without database or external writes.';

    public function handle(MeasurementCommerceTruthReadModel $readModel): int
    {
        try {
            $report = $readModel->report(
                (string) $this->option('from'),
                (string) $this->option('to'),
                [
                    'scale_code' => $this->arrayOption('scale'),
                    'form_code' => $this->arrayOption('form'),
                    'locale' => $this->arrayOption('locale'),
                    'commerce_channel' => $this->arrayOption('channel'),
                    'provider_class' => $this->arrayOption('provider'),
                ],
            );
        } catch (Throwable) {
            $report = [
                'schema_version' => MeasurementCommerceTruthReadModel::SCHEMA_VERSION,
                'task' => MeasurementCommerceTruthReadModel::TASK,
                'ok' => false,
                'status' => 'blocked',
                'issues' => ['measurement_commerce_truth_read_failed'],
                'totals' => [],
                'rows' => [],
                'read_only' => true,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($report['status'] ?? 'blocked'));
            foreach ([
                'order_created_count',
                'payment_succeeded_count',
                'refund_count',
                'report_unlock_count',
                'report_ready_count',
                'active_entitlement_count',
                'payment_event_coverage_status',
                'entitlement_coverage_status',
                'report_ready_coverage_status',
                'duplicate_or_conflict_count',
            ] as $metric) {
                $value = data_get($report, 'totals.'.$metric);
                $this->line($metric.'='.($value === null ? 'null' : (string) $value));
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
