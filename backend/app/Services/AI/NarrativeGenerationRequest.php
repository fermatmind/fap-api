<?php

declare(strict_types=1);

namespace App\Services\AI;

final class NarrativeGenerationRequest
{
    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $surface,
        public readonly string $scaleCode,
        public readonly string $locale,
        public readonly array $authority,
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'surface' => $this->surface,
            'scale_code' => $this->scaleCode,
            'locale' => $this->locale,
            'authority' => $this->authority,
            'context' => $this->safeContext(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'surface' => $this->surface,
            'scale_code' => $this->scaleCode,
            'locale' => $this->locale,
            'authority' => $this->authority,
            'context' => $this->safeContext(),
        ];
    }

    public function budgetSubject(): string
    {
        $userId = trim((string) ($this->context['user_id'] ?? ''));
        if ($userId !== '') {
            return sprintf('narrative:%s:%s:user:%s', strtolower($this->scaleCode), $this->surface, $userId);
        }

        $anonId = trim((string) ($this->context['anon_id'] ?? ''));
        if ($anonId !== '') {
            return sprintf('narrative:%s:%s:anon:%s', strtolower($this->scaleCode), $this->surface, $anonId);
        }

        $attemptId = trim((string) ($this->context['attempt_id'] ?? ''));
        if ($attemptId !== '') {
            return sprintf('narrative:%s:%s:attempt:%s', strtolower($this->scaleCode), $this->surface, $attemptId);
        }

        return sprintf('narrative:%s:%s:unknown', strtolower($this->scaleCode), $this->surface);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeContext(): array
    {
        $safe = [];

        foreach (['type_code', 'identity', 'engine_version', 'schema_version', 'dynamic_sections_version'] as $key) {
            $value = $this->context[$key] ?? null;
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                $safe[$key] = $normalized;
            }
        }

        return $safe;
    }
}
