<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Observability;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutDecision;
use Illuminate\Support\Facades\Log;

final class BigFiveV2ProductionRolloutTelemetry
{
    public const EVENT = 'BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_TELEMETRY';

    public function recordRolloutDecision(
        Attempt $attempt,
        ?Result $result,
        BigFiveV2ProductionRolloutDecision $decision,
    ): void {
        $metric = $decision->allowed ? 'rollout_attach' : 'rollout_deny';
        $payload = $this->context($attempt, $result, [
            'metric_name' => $metric,
            'rollout_allowed' => $decision->allowed,
            'rollout_attach_count' => $decision->allowed ? 1 : 0,
            'rollout_deny_count' => $decision->allowed ? 0 : 1,
            'fail_closed_count' => $decision->allowed ? 0 : 1,
            'rollout_reason' => $decision->reason,
            'rollout_matched_by' => $decision->matchedBy,
            'rollout_percentage' => (int) ($decision->context['rollout_percentage'] ?? 0),
            'percentage_rollout_evaluated' => $decision->matchedBy === 'rollout_percentage'
                || str_contains($decision->reason, 'percentage'),
            'rollout_error_count' => count($decision->errors),
        ]);

        $decision->allowed ? Log::info(self::EVENT, $payload) : Log::warning(self::EVENT, $payload);
    }

    public function recordRouteLookupFailure(Attempt $attempt, ?Result $result, string $combinationKeyHash): void
    {
        Log::warning(self::EVENT, $this->context($attempt, $result, [
            'metric_name' => 'route_lookup_failure',
            'route_lookup_failed' => true,
            'route_lookup_failure_count' => 1,
            'combination_key_hash' => $this->safeHash($combinationKeyHash),
            'payload_attached' => false,
            'fail_closed_count' => 1,
        ]));
    }

    public function recordSelectorSuppression(
        Attempt $attempt,
        ?Result $result,
        int $suppressedRefCount,
        int $unresolvedRefCount,
    ): void {
        Log::info(self::EVENT, $this->context($attempt, $result, [
            'metric_name' => 'selector_suppression',
            'selector_suppression_count' => max(0, $suppressedRefCount),
            'selector_unresolved_ref_count' => max(0, $unresolvedRefCount),
            'selector_suppression_observed' => $suppressedRefCount > 0 || $unresolvedRefCount > 0,
        ]));
    }

    public function recordComposerFailure(Attempt $attempt, ?Result $result, \Throwable $exception): void
    {
        Log::warning(self::EVENT, $this->context($attempt, $result, [
            'metric_name' => 'composer_failure',
            'composer_failed' => true,
            'composer_failure_count' => 1,
            'exception_class' => $exception::class,
            'payload_attached' => false,
            'fail_closed_count' => 1,
        ]));
    }

    /**
     * @param  list<string>  $errors
     */
    public function recordPayloadValidationFailure(Attempt $attempt, ?Result $result, array $errors): void
    {
        Log::warning(self::EVENT, $this->context($attempt, $result, [
            'metric_name' => 'payload_validation_failure',
            'payload_validation_failed' => true,
            'payload_validation_error_count' => count($errors),
            'payload_attached' => false,
            'fail_closed_count' => 1,
        ]));
    }

    /**
     * @param  array<string,mixed>  $state
     */
    public function recordFailClosed(Attempt $attempt, ?Result $result, string $reason, array $state = []): void
    {
        Log::warning(self::EVENT, $this->context($attempt, $result, [
            'metric_name' => 'fail_closed',
            'fail_closed_count' => 1,
            'fail_closed_reason' => $reason,
            'payload_attached' => false,
            'route_lookup_failed' => (bool) ($state['route_lookup_failed'] ?? false),
            'composer_failed' => (bool) ($state['composer_failed'] ?? false),
            'payload_validation_failed' => (bool) ($state['payload_validation_failed'] ?? false),
        ]));
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function context(Attempt $attempt, ?Result $result, array $extra): array
    {
        return [
            'attempt_hash' => $this->hashIdentifier((string) ($attempt->id ?? '')),
            'result_hash' => $result === null ? null : $this->hashIdentifier((string) ($result->id ?? '')),
            'user_hash' => $this->hashIdentifier((string) ($attempt->user_id ?? '')),
            'anon_hash' => $this->hashIdentifier((string) ($attempt->anon_id ?? '')),
            'tenant_hash' => $this->hashIdentifier((string) ($attempt->org_id ?? '')),
            'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
            'form_code' => trim((string) data_get($attempt->answers_summary_json, 'meta.form_code', '')),
            'locale' => trim((string) ($attempt->locale ?? '')),
            'environment' => app()->environment(),
            'telemetry_schema_version' => 'big5_v2_production_rollout_telemetry_v0_1',
        ] + $extra;
    }

    private function hashIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return '';
        }

        return hash('sha256', $identifier);
    }

    private function safeHash(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
            return strtolower($value);
        }

        return hash('sha256', $value);
    }
}
