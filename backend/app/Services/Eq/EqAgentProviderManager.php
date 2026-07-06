<?php

declare(strict_types=1);

namespace App\Services\Eq;

use App\Services\Abuse\RateLimiter;
use Illuminate\Support\Facades\Log;

final class EqAgentProviderManager
{
    public function __construct(
        private OpenAiEqAgentProviderClient $openAiProvider,
        private RateLimiter $rateLimiter,
    ) {}

    public function enabled(): bool
    {
        if ((bool) config('ai.eq_agent.llm_enabled', false) !== true) {
            return false;
        }

        if ((bool) config('ai.eq_agent.staging_only', true) && app()->environment('production')) {
            return false;
        }

        return $this->providerName() === 'openai';
    }

    public function providerName(): string
    {
        return strtolower(trim((string) config('ai.eq_agent.provider', 'openai')));
    }

    public function unavailableReason(): ?string
    {
        if (! $this->enabled()) {
            return 'eq_agent_llm_disabled';
        }

        return $this->openAiProvider->unavailableReason();
    }

    public function tryGenerate(EqAgentProviderRequest $request): ?EqAgentProviderResponse
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! $this->openAiProvider->isConfigured()) {
            return null;
        }

        if (! $this->consumeAttemptBudget($request)) {
            Log::notice('EQ_AGENT_PROVIDER_THROTTLED', [
                'provider' => $this->providerName(),
                'reason' => 'attempt_rate_limit',
            ]);

            return null;
        }

        try {
            return $this->openAiProvider->generate($request);
        } catch (\Throwable $e) {
            Log::warning('EQ_AGENT_PROVIDER_FALLBACK', [
                'provider' => $this->providerName(),
                'reason' => $e::class,
            ]);

            return null;
        }
    }

    private function consumeAttemptBudget(EqAgentProviderRequest $request): bool
    {
        $limit = max(0, (int) config('ai.eq_agent.provider_limits.max_calls_per_attempt_hour', 6));
        if ($limit <= 0) {
            return true;
        }

        $decaySeconds = max(60, (int) config('ai.eq_agent.provider_limits.attempt_decay_seconds', 3600));
        $attemptId = trim((string) ($request->context['attempt_id'] ?? $request->deterministicPayload['attempt_id'] ?? ''));
        $subject = $attemptId !== '' ? $attemptId : 'unknown';

        return $this->rateLimiter->hit('eq_agent_provider:attempt:'.sha1($subject), $limit, $decaySeconds);
    }
}
