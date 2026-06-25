<?php

declare(strict_types=1);

namespace App\Services\Eq;

use Illuminate\Support\Facades\Log;

final class EqAgentProviderManager
{
    public function __construct(
        private OpenAiEqAgentProviderClient $openAiProvider,
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
}
