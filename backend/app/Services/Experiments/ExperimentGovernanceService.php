<?php

declare(strict_types=1);

namespace App\Services\Experiments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ExperimentGovernanceService
{
    private const ROLLOUTS_TABLE = 'scoring_model_rollouts';

    private const AUDITS_TABLE = 'experiment_rollout_audits';

    private const GUARDRAILS_TABLE = 'experiment_guardrails';

    /**
     * @return array{rollout:array<string,mixed>,audit_id:string}|null
     */
    public function approveRollout(int $orgId, string $rolloutId, ?int $actorUserId, ?string $reason = null): ?array
    {
        return $this->mutateRolloutState($orgId, $rolloutId, $actorUserId, 'approve', true, false, $reason);
    }

    /**
     * @return array{rollout:array<string,mixed>,audit_id:string}|null
     */
    public function pauseRollout(int $orgId, string $rolloutId, ?int $actorUserId, ?string $reason = null): ?array
    {
        return $this->mutateRolloutState($orgId, $rolloutId, $actorUserId, 'pause', false, false, $reason);
    }

    /**
     * @return array{rollout:array<string,mixed>,audit_id:string}|null
     */
    public function rollbackRollout(int $orgId, string $rolloutId, ?int $actorUserId, ?string $reason = null): ?array
    {
        return $this->mutateRolloutState($orgId, $rolloutId, $actorUserId, 'rollback', false, true, $reason);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *   rollout:array<string,mixed>,
     *   guardrail:array<string,mixed>,
     *   audit_id:string
     * }|null
     */
    public function upsertGuardrail(int $orgId, string $rolloutId, ?int $actorUserId, array $payload): ?array
    {
        $this->assertGovernanceTablesReady();

        $rollout = $this->findRollout($orgId, $rolloutId);
        if ($rollout === null) {
            return null;
        }

        $metricKey = strtolower(trim((string) ($payload['metric_key'] ?? '')));
        if ($metricKey === '') {
            throw new \InvalidArgumentException('metric_key is required.');
        }

        $operator = $this->normalizeOperator((string) ($payload['operator'] ?? ''));
        $threshold = $this->toFloat($payload['threshold'] ?? null);
        if ($threshold === null) {
            throw new \InvalidArgumentException('threshold must be numeric.');
        }

        $windowMinutes = $this->toPositiveInt($payload['window_minutes'] ?? 60, 60);
        $minSampleSize = $this->toPositiveInt($payload['min_sample_size'] ?? 30, 30);
        $autoRollback = $this->toBool($payload['auto_rollback'] ?? true, true);
        $isActive = $this->toBool($payload['is_active'] ?? true, true);
        $reason = $this->normalizeReason($payload['reason'] ?? null);
        $experimentKey = trim((string) ($rollout->experiment_key ?? ''));
        $now = now();

        $existing = DB::table(self::GUARDRAILS_TABLE)
            ->where('org_id', $orgId)
            ->where('rollout_id', $rolloutId)
            ->where('metric_key', $metricKey)
            ->first();

        $guardrailId = trim((string) ($existing->id ?? ''));
        if ($guardrailId === '') {
            $guardrailId = (string) Str::uuid();
            DB::table(self::GUARDRAILS_TABLE)->insert([
                'id' => $guardrailId,
                'org_id' => $orgId,
                'rollout_id' => $rolloutId,
                'experiment_key' => $experimentKey !== '' ? $experimentKey : null,
                'metric_key' => $metricKey,
                'operator' => $operator,
                'threshold' => $threshold,
                'window_minutes' => $windowMinutes,
                'min_sample_size' => $minSampleSize,
                'auto_rollback' => $autoRollback,
                'is_active' => $isActive,
                'last_evaluated_at' => null,
                'last_triggered_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table(self::GUARDRAILS_TABLE)
                ->where('id', $guardrailId)
                ->update([
                    'experiment_key' => $experimentKey !== '' ? $experimentKey : null,
                    'operator' => $operator,
                    'threshold' => $threshold,
                    'window_minutes' => $windowMinutes,
                    'min_sample_size' => $minSampleSize,
                    'auto_rollback' => $autoRollback,
                    'is_active' => $isActive,
                    'updated_at' => $now,
                ]);
        }

        $guardrail = DB::table(self::GUARDRAILS_TABLE)->where('id', $guardrailId)->first();
        if ($guardrail === null) {
            throw new \RuntimeException('guardrail write failed.');
        }

        $auditId = $this->writeAudit(
            $orgId,
            $rolloutId,
            $experimentKey !== '' ? $experimentKey : null,
            'guardrail_upsert',
            'ok',
            $reason,
            [
                'guardrail_id' => $guardrailId,
                'metric_key' => $metricKey,
                'operator' => $operator,
                'threshold' => $threshold,
                'window_minutes' => $windowMinutes,
                'min_sample_size' => $minSampleSize,
                'auto_rollback' => $autoRollback,
                'is_active' => $isActive,
            ],
            $actorUserId
        );

        return [
            'rollout' => $this->formatRollout($rollout),
            'guardrail' => $this->formatGuardrail($guardrail),
            'audit_id' => $auditId,
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{
     *   rollout:array<string,mixed>,
     *   guardrails:list<array<string,mixed>>,
     *   rolled_back:bool,
     *   triggered_count:int,
     *   audit_id:?string
     * }|null
     */
    public function evaluateGuardrails(
        int $orgId,
        string $rolloutId,
        ?int $actorUserId,
        array $metrics,
        ?string $reason = null
    ): ?array {
        $this->assertGovernanceTablesReady();

        $rollout = $this->findRollout($orgId, $rolloutId);
        if ($rollout === null) {
            return null;
        }

        $guardrails = DB::table(self::GUARDRAILS_TABLE)
            ->where('org_id', $orgId)
            ->where('rollout_id', $rolloutId)
            ->where('is_active', true)
            ->orderBy('metric_key')
            ->get()
            ->all();

        $now = now();
        $triggeredCount = 0;
        $autoRollbackTriggered = false;
        $evaluatedGuardrails = [];

        foreach ($guardrails as $guardrail) {
            $metricKey = strtolower(trim((string) ($guardrail->metric_key ?? '')));
            $operator = $this->normalizeOperator((string) ($guardrail->operator ?? 'gte'));
            $threshold = (float) ($guardrail->threshold ?? 0);
            $minSampleSize = max(1, (int) ($guardrail->min_sample_size ?? 1));
            $autoRollback = (bool) ($guardrail->auto_rollback ?? false);
            $metricState = $this->extractMetricState($metrics, $metricKey);

            $sampleSize = $metricState['sample_size'];
            $hasEnoughSamples = $sampleSize === null || $sampleSize >= $minSampleSize;
            $triggered = false;
            $status = 'missing_metric';

            if ($metricState['has_value']) {
                if (! $hasEnoughSamples) {
                    $status = 'insufficient_sample';
                } else {
                    $triggered = $this->compare((float) $metricState['value'], $operator, $threshold);
                    $status = $triggered ? 'triggered' : 'ok';
                }
            }

            DB::table(self::GUARDRAILS_TABLE)
                ->where('id', (string) ($guardrail->id ?? ''))
                ->update([
                    'last_evaluated_at' => $now,
                    'last_triggered_at' => $triggered ? $now : ($guardrail->last_triggered_at ?? null),
                    'updated_at' => $now,
                ]);

            $this->writeAudit(
                $orgId,
                $rolloutId,
                $this->nullableString($rollout->experiment_key ?? null),
                'guardrail_evaluate',
                $status,
                $this->normalizeReason($reason),
                [
                    'guardrail_id' => (string) ($guardrail->id ?? ''),
                    'metric_key' => $metricKey,
                    'operator' => $operator,
                    'threshold' => $threshold,
                    'actual_value' => $metricState['value'],
                    'sample_size' => $sampleSize,
                    'min_sample_size' => $minSampleSize,
                    'has_enough_samples' => $hasEnoughSamples,
                    'auto_rollback' => $autoRollback,
                    'triggered' => $triggered,
                ],
                $actorUserId
            );

            if ($triggered) {
                $triggeredCount++;
            }
            if ($triggered && $autoRollback) {
                $autoRollbackTriggered = true;
            }

            $evaluatedGuardrails[] = [
                'id' => (string) ($guardrail->id ?? ''),
                'metric_key' => $metricKey,
                'operator' => $operator,
                'threshold' => $threshold,
                'actual_value' => $metricState['value'],
                'sample_size' => $sampleSize,
                'min_sample_size' => $minSampleSize,
                'auto_rollback' => $autoRollback,
                'triggered' => $triggered,
                'status' => $status,
            ];
        }

        $autoRollbackAuditId = null;
        $rolledBack = false;
        if ($autoRollbackTriggered && $this->toBool($rollout->is_active ?? false, false)) {
            DB::table(self::ROLLOUTS_TABLE)
                ->where('id', $rolloutId)
                ->where('org_id', $orgId)
                ->update([
                    'is_active' => false,
                    'ends_at' => $now,
                    'updated_at' => $now,
                ]);

            $autoRollbackAuditId = $this->writeAudit(
                $orgId,
                $rolloutId,
                $this->nullableString($rollout->experiment_key ?? null),
                'auto_rollback',
                'triggered',
                $this->normalizeReason($reason) ?? 'guardrail threshold breached',
                [
                    'triggered_count' => $triggeredCount,
                    'guardrail_count' => count($evaluatedGuardrails),
                ],
                $actorUserId
            );
            $rolledBack = true;
        }

        $freshRollout = $this->findRollout($orgId, $rolloutId);
        if ($freshRollout === null) {
            return null;
        }

        return [
            'rollout' => $this->formatRollout($freshRollout),
            'guardrails' => $evaluatedGuardrails,
            'rolled_back' => $rolledBack,
            'triggered_count' => $triggeredCount,
            'audit_id' => $autoRollbackAuditId,
        ];
    }

    /**
     * @return array{rollout:array<string,mixed>,audit_id:string}|null
     */
    private function mutateRolloutState(
        int $orgId,
        string $rolloutId,
        ?int $actorUserId,
        string $action,
        bool $isActive,
        bool $closeWindow,
        ?string $reason
    ): ?array {
        $this->assertGovernanceTablesReady();

        $rollout = $this->findRollout($orgId, $rolloutId);
        if ($rollout === null) {
            return null;
        }

        $now = now();
        $updates = [
            'is_active' => $isActive,
            'updated_at' => $now,
        ];
        if ($isActive && $rollout->starts_at === null) {
            $updates['starts_at'] = $now;
        }
        if ($closeWindow) {
            $updates['ends_at'] = $now;
        }

        DB::table(self::ROLLOUTS_TABLE)
            ->where('id', $rolloutId)
            ->where('org_id', $orgId)
            ->update($updates);

        $freshRollout = $this->findRollout($orgId, $rolloutId);
        if ($freshRollout === null) {
            throw new \RuntimeException('rollout update failed.');
        }

        $auditId = $this->writeAudit(
            $orgId,
            $rolloutId,
            $this->nullableString($freshRollout->experiment_key ?? null),
            $action,
            'ok',
            $this->normalizeReason($reason),
            [
                'is_active' => (bool) ($freshRollout->is_active ?? false),
                'starts_at' => $freshRollout->starts_at !== null ? (string) $freshRollout->starts_at : null,
                'ends_at' => $freshRollout->ends_at !== null ? (string) $freshRollout->ends_at : null,
            ],
            $actorUserId
        );

        return [
            'rollout' => $this->formatRollout($freshRollout),
            'audit_id' => $auditId,
        ];
    }

    private function assertGovernanceTablesReady(): void
    {
        foreach ([self::ROLLOUTS_TABLE, self::AUDITS_TABLE, self::GUARDRAILS_TABLE] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException($table.' table is not ready.');
            }
        }
    }

    private function findRollout(int $orgId, string $rolloutId): ?object
    {
        $rolloutId = trim($rolloutId);
        if ($orgId <= 0 || $rolloutId === '') {
            return null;
        }

        return DB::table(self::ROLLOUTS_TABLE)
            ->where('id', $rolloutId)
            ->where('org_id', $orgId)
            ->first();
    }

    /**
     * @param  array<string,mixed>|null  $meta
     */
    private function writeAudit(
        int $orgId,
        string $rolloutId,
        ?string $experimentKey,
        string $action,
        string $status,
        ?string $reason,
        ?array $meta,
        ?int $actorUserId
    ): string {
        $auditId = (string) Str::uuid();
        $now = now();
        DB::table(self::AUDITS_TABLE)->insert([
            'id' => $auditId,
            'org_id' => $orgId,
            'rollout_id' => $rolloutId,
            'experiment_key' => $experimentKey,
            'action' => strtolower(trim($action)),
            'status' => strtolower(trim($status)) !== '' ? strtolower(trim($status)) : 'ok',
            'reason' => $reason,
            'meta_json' => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $auditId;
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array{has_value:bool,value:?float,sample_size:?int}
     */
    private function extractMetricState(array $metrics, string $metricKey): array
    {
        if ($metricKey === '' || ! array_key_exists($metricKey, $metrics)) {
            return ['has_value' => false, 'value' => null, 'sample_size' => null];
        }

        $raw = $metrics[$metricKey];
        if (is_array($raw)) {
            $value = $this->toFloat($raw['value'] ?? $raw['metric_value'] ?? null);
            $sampleSize = $this->toPositiveInt($raw['sample_size'] ?? null, null);

            return [
                'has_value' => $value !== null,
                'value' => $value,
                'sample_size' => $sampleSize,
            ];
        }

        $value = $this->toFloat($raw);

        return [
            'has_value' => $value !== null,
            'value' => $value,
            'sample_size' => null,
        ];
    }

    private function compare(float $actual, string $operator, float $threshold): bool
    {
        return match ($operator) {
            'gt' => $actual > $threshold,
            'gte' => $actual >= $threshold,
            'lt' => $actual < $threshold,
            'lte' => $actual <= $threshold,
            default => $actual >= $threshold,
        };
    }

    private function normalizeOperator(string $operator): string
    {
        $normalized = strtolower(trim($operator));

        return match ($normalized) {
            '>', 'gt' => 'gt',
            '>=', 'gte' => 'gte',
            '<', 'lt' => 'lt',
            '<=', 'lte' => 'lte',
            default => throw new \InvalidArgumentException('operator must be one of: gt,gte,lt,lte'),
        };
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || ! is_numeric($value)) {
                return null;
            }

            return (float) $value;
        }

        return null;
    }

    private function toPositiveInt(mixed $value, ?int $default): ?int
    {
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
                return $default;
            }

            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : $default;
        }

        return $default;
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function normalizeReason(mixed $reason): ?string
    {
        $normalized = trim((string) $reason);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > 191) {
            $normalized = mb_substr($normalized, 0, 191, 'UTF-8');
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatRollout(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'org_id' => (int) ($row->org_id ?? 0),
            'scale_code' => (string) ($row->scale_code ?? ''),
            'model_key' => (string) ($row->model_key ?? ''),
            'experiment_key' => $this->nullableString($row->experiment_key ?? null),
            'experiment_variant' => $this->nullableString($row->experiment_variant ?? null),
            'rollout_percent' => (int) ($row->rollout_percent ?? 0),
            'priority' => (int) ($row->priority ?? 0),
            'is_active' => $this->toBool($row->is_active ?? false, false),
            'starts_at' => $row->starts_at !== null ? (string) $row->starts_at : null,
            'ends_at' => $row->ends_at !== null ? (string) $row->ends_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatGuardrail(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'org_id' => (int) ($row->org_id ?? 0),
            'rollout_id' => (string) ($row->rollout_id ?? ''),
            'experiment_key' => $this->nullableString($row->experiment_key ?? null),
            'metric_key' => (string) ($row->metric_key ?? ''),
            'operator' => (string) ($row->operator ?? 'gte'),
            'threshold' => (float) ($row->threshold ?? 0),
            'window_minutes' => (int) ($row->window_minutes ?? 0),
            'min_sample_size' => (int) ($row->min_sample_size ?? 0),
            'auto_rollback' => $this->toBool($row->auto_rollback ?? false, false),
            'is_active' => $this->toBool($row->is_active ?? false, false),
            'last_evaluated_at' => $row->last_evaluated_at !== null ? (string) $row->last_evaluated_at : null,
            'last_triggered_at' => $row->last_triggered_at !== null ? (string) $row->last_triggered_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        ];
    }
}
