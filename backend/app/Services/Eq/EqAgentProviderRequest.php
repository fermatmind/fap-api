<?php

declare(strict_types=1);

namespace App\Services\Eq;

final class EqAgentProviderRequest
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $deterministicPayload
     */
    public function __construct(
        public readonly array $context,
        public readonly array $deterministicPayload,
        public readonly string $message,
        public readonly ?string $intent,
        public readonly string $locale,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function safeContext(): array
    {
        return [
            'locale' => $this->locale,
            'intent_context' => $this->arrayOrEmpty($this->context['intent_context'] ?? null),
            'report_context' => $this->arrayOrEmpty($this->context['report_context'] ?? null),
            'resolved_assets' => $this->arrayOrEmpty($this->context['resolved_assets'] ?? null),
            'agent_knowledge' => [
                'authority' => data_get($this->context, 'agent_knowledge.authority', []),
                'forbidden_claims' => data_get($this->context, 'agent_knowledge.forbidden_claims', []),
                'escalation_flags' => data_get($this->context, 'agent_knowledge.escalation_flags', []),
                'locale_policy' => data_get($this->context, 'agent_knowledge.locale_policy', []),
            ],
            'guardrails' => $this->arrayOrEmpty($this->context['guardrails'] ?? null),
            'current_runtime_boundary' => [
                'schema' => (string) ($this->deterministicPayload['schema'] ?? 'eq.agent_runtime_response.v1'),
                'mode' => (string) ($this->deterministicPayload['mode'] ?? 'deterministic_read_only'),
                'source_asset_ids' => data_get($this->deterministicPayload, 'assistant_response.source_asset_ids', []),
                'boundary_claim_ids' => data_get($this->deterministicPayload, 'assistant_response.boundary_claim_ids', []),
                'next_module' => $this->arrayOrEmpty($this->deterministicPayload['next_module'] ?? null),
                'safety' => $this->arrayOrEmpty($this->deterministicPayload['safety'] ?? null),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
