<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ResultPageV2\BigFiveV2PilotRuntimeObservability;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class BigFiveV2PilotRuntimeObservabilityTest extends TestCase
{
    public function test_validation_failure_log_is_internal_safe_and_count_only(): void
    {
        Log::spy();
        $attempt = new Attempt([
            'id' => 'attempt-observability-validation',
            'scale_code' => 'BIG5_OCEAN',
            'answers_summary_json' => ['meta' => ['form_code' => 'big5_90']],
        ]);
        $result = new Result(['id' => 'result-observability-validation']);

        app(BigFiveV2PilotRuntimeObservability::class)->recordPayloadValidationFailed($attempt, $result, [
            'internal validation detail should not be logged verbatim',
        ]);

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['payload_validation_failed'] ?? null) === true
                && ($context['payload_validation_error_count'] ?? null) === 1
                && ! array_key_exists('errors', $context)
                && ! array_key_exists('attempt_id', $context)
                && ! array_key_exists('payload_body', $context)
                && strlen((string) ($context['attempt_hash'] ?? '')) === 64),
        )->once();
    }
}
