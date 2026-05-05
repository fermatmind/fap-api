<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ResultPageV2\Access\BigFiveV2PilotAccessDecision;
use Illuminate\Support\Facades\Log;

final class BigFiveV2PilotRuntimeObservability
{
    public const EVENT = 'BIG5_RESULT_PAGE_V2_PILOT_RUNTIME_OBSERVABILITY';

    /**
     * @param  array<string,mixed>  $state
     */
    public function recordFlagOff(Attempt $attempt, ?Result $result, array $state = []): void
    {
        Log::info(self::EVENT, $this->context($attempt, $result, [
            'pilot_flag_state' => 'disabled',
            'access_gate_decision' => 'not_evaluated',
            'payload_attached' => false,
            'payload_validation_failed' => false,
            'fallback_reason' => (string) ($state['fallback_reason'] ?? 'pilot_runtime_disabled'),
        ] + $state));
    }

    public function recordAccessDecision(Attempt $attempt, Result $result, BigFiveV2PilotAccessDecision $decision): void
    {
        Log::info(self::EVENT, $this->context($attempt, $result, [
            'pilot_flag_state' => 'enabled',
            'access_gate_decision' => $decision->allowed ? 'allow' : 'deny',
            'access_gate_reason' => $decision->reason,
            'access_gate_matched_rule' => $decision->matchedRule,
            'payload_attached' => false,
            'payload_validation_failed' => false,
            'fallback_reason' => $decision->allowed ? null : $decision->reason,
        ]));
    }

    /**
     * @param  list<string>  $errors
     */
    public function recordPayloadValidationFailed(Attempt $attempt, Result $result, array $errors): void
    {
        Log::warning(self::EVENT, $this->context($attempt, $result, [
            'pilot_flag_state' => 'enabled',
            'access_gate_decision' => 'allow',
            'payload_attached' => false,
            'payload_validation_failed' => true,
            'payload_validation_error_count' => count($errors),
            'fallback_reason' => 'payload_validation_failed',
        ]));
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    public function recordPayloadAttached(Attempt $attempt, Result $result, array $metrics): void
    {
        Log::info(self::EVENT, $this->context($attempt, $result, [
            'pilot_flag_state' => 'enabled',
            'access_gate_decision' => 'allow',
            'payload_attached' => true,
            'payload_validation_failed' => false,
            'fallback_reason' => null,
        ] + $metrics));
    }

    public function recordPayloadGenerationFailed(Attempt $attempt, Result $result, \Throwable $exception): void
    {
        Log::warning(self::EVENT, $this->context($attempt, $result, [
            'pilot_flag_state' => 'enabled',
            'access_gate_decision' => 'allow',
            'payload_attached' => false,
            'payload_validation_failed' => false,
            'fallback_reason' => 'payload_generation_failed',
            'exception_class' => $exception::class,
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
            'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
            'form_code' => trim((string) data_get($attempt->answers_summary_json, 'meta.form_code', '')),
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
}
