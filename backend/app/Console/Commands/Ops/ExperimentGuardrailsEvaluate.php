<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Experiments\ExperimentKpiEvaluator;
use Illuminate\Console\Command;

final class ExperimentGuardrailsEvaluate extends Command
{
    protected $signature = 'ops:experiment-guardrails-evaluate
        {--org-id= : Org id to evaluate}
        {--rollout-id= : Optional rollout id; when omitted, evaluate all active rollouts in org}
        {--window-minutes=60 : KPI lookback window in minutes}
        {--actor-user-id= : Optional actor user id written into audits}
        {--reason= : Optional reason message}
        {--strict=0 : Exit non-zero when any rollout is auto rolled back}
        {--json=1 : Output JSON payload}';

    protected $description = 'Evaluate experiment guardrails with DB KPI metrics and auto rollback bridge.';

    public function __construct(private readonly ExperimentKpiEvaluator $kpiEvaluator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $orgId = $this->parseOrgId($this->option('org-id'));
        if ($orgId <= 0) {
            $this->error('invalid --org-id, expected positive integer');

            return self::FAILURE;
        }

        $windowMinutes = max(1, (int) $this->option('window-minutes'));
        $actorUserId = $this->parseOptionalInt($this->option('actor-user-id'));
        $reason = $this->normalizeString($this->option('reason'));
        $rolloutId = $this->normalizeString($this->option('rollout-id'));

        $results = [];

        try {
            if ($rolloutId !== null) {
                $evaluated = $this->kpiEvaluator->evaluateRollout(
                    $orgId,
                    $rolloutId,
                    $actorUserId,
                    $windowMinutes,
                    $reason
                );

                if ($evaluated === null) {
                    $this->error('rollout not found or governance tables not ready');

                    return self::FAILURE;
                }

                $results[] = $evaluated;
            } else {
                $results = $this->kpiEvaluator->evaluateActiveRollouts(
                    $orgId,
                    $actorUserId,
                    $windowMinutes,
                    $reason
                );
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rolledBackCount = 0;
        foreach ($results as $result) {
            if ((bool) ($result['rolled_back'] ?? false)) {
                $rolledBackCount++;
            }
        }

        $payload = [
            'ok' => true,
            'org_id' => $orgId,
            'window_minutes' => $windowMinutes,
            'evaluated_count' => count($results),
            'rolled_back_count' => $rolledBackCount,
            'results' => $results,
        ];

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('experiment_guardrails_evaluate');
            $this->line(sprintf(
                'org_id=%d window_minutes=%d evaluated=%d rolled_back=%d',
                $orgId,
                $windowMinutes,
                count($results),
                $rolledBackCount
            ));

            foreach ($results as $index => $result) {
                $rollout = is_array($result['rollout'] ?? null) ? $result['rollout'] : [];
                $funnel = is_array($result['funnel'] ?? null) ? $result['funnel'] : [];
                $stageCounts = is_array($funnel['stage_counts'] ?? null) ? $funnel['stage_counts'] : [];
                $rolloutLabel = trim((string) ($rollout['id'] ?? '')) ?: 'rollout_'.($index + 1);

                $this->line(sprintf(
                    '%s start_test=%d submit_attempt=%d checkout_start=%d payment_succeeded=%d report_ready=%d',
                    $rolloutLabel,
                    (int) ($stageCounts['start_test'] ?? 0),
                    (int) ($stageCounts['submit_attempt'] ?? 0),
                    (int) ($stageCounts['checkout_start'] ?? 0),
                    (int) ($stageCounts['payment_succeeded'] ?? 0),
                    (int) ($stageCounts['report_ready'] ?? 0)
                ));
            }
        }

        if ($this->isTruthy($this->option('strict')) && $rolledBackCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parseOrgId(mixed $value): int
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return 0;
        }

        return (int) $normalized;
    }

    private function parseOptionalInt(mixed $value): ?int
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    private function normalizeString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
