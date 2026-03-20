<?php

declare(strict_types=1);

namespace App\Services\AI;

final class ControlledGenerationRuntime
{
    public const CONTRACT_VERSION = 'narrative_runtime_contract.v1';

    /**
     * @var list<string>
     */
    private const TRUTH_GUARD_FIELDS = [
        'type_code',
        'identity',
        'variant_keys',
        'trait_vector',
        'trait_bands',
        'dominant_traits',
        'scene_fingerprint',
        'working_life_v1',
        'cross_assessment_v1',
        'user_state',
        'orchestration',
        'continuity',
        'career_focus_key',
        'career_journey_keys',
        'career_action_priority_keys',
    ];

    public function __construct(
        private readonly BudgetLedger $budgetLedger,
        private readonly NullNarrativeProvider $nullProvider,
        private readonly MockNarrativeProvider $mockProvider,
    ) {
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildContract(
        string $surface,
        string $scaleCode,
        string $locale,
        array $authority,
        array $context = [],
    ): array {
        $request = new NarrativeGenerationRequest(
            surface: trim($surface),
            scaleCode: strtoupper(trim($scaleCode)),
            locale: trim($locale) !== '' ? trim($locale) : 'zh-CN',
            authority: $this->normalizeAuthority($authority),
            context: $context,
        );

        if (! $this->isEnabled()) {
            return $this->nullProvider->generate($request)->toContractArray(
                self::CONTRACT_VERSION,
                $request,
                self::TRUTH_GUARD_FIELDS,
            );
        }

        $providerName = $this->configuredProviderName();
        $provider = $this->resolveProvider($providerName);
        if (! $provider instanceof NarrativeProviderInterface) {
            return $this->fallbackResponse($request, $providerName, 'AI_PROVIDER_UNAVAILABLE')
                ->toContractArray(self::CONTRACT_VERSION, $request, self::TRUTH_GUARD_FIELDS);
        }

        try {
            $this->guardBudget($request, $providerName);
            $response = $provider->generate($request);
            $this->incrementBudget($request, $response);
        } catch (BudgetLedgerException $e) {
            $response = $this->fallbackResponse($request, $providerName, $e->errorCode());
        } catch (\Throwable $e) {
            $response = $this->fallbackResponse($request, $providerName, 'AI_RUNTIME_FAILED');
        }

        return $response->toContractArray(self::CONTRACT_VERSION, $request, self::TRUTH_GUARD_FIELDS);
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, mixed>
     */
    private function normalizeAuthority(array $authority): array
    {
        $normalized = [];

        foreach ([
            'type_code',
            'identity',
            'engine_version',
            'schema_version',
            'dynamic_sections_version',
            'explainability_summary',
            'action_plan_summary',
            'work_style_summary',
            'trait_vector',
            'trait_bands',
            'dominant_traits',
            'ordered_section_keys',
            'scene_fingerprint',
            'variant_keys',
            'working_life_v1',
            'cross_assessment_v1',
            'user_state',
            'orchestration',
            'continuity',
            'career_focus_key',
            'career_journey_keys',
            'career_action_priority_keys',
            'reading_focus_key',
            'action_focus_key',
        ] as $key) {
            if (! array_key_exists($key, $authority)) {
                continue;
            }

            $value = $authority[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            } elseif (is_array($value) && $value === []) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function isEnabled(): bool
    {
        return (bool) config('ai.enabled', true)
            && (bool) config('ai.narrative.enabled', false);
    }

    private function configuredProviderName(): string
    {
        $provider = trim((string) config('ai.narrative.provider', config('ai.provider', 'mock')));

        return $provider !== '' ? strtolower($provider) : 'mock';
    }

    private function resolveProvider(string $providerName): ?NarrativeProviderInterface
    {
        return match ($providerName) {
            'null', 'off' => $this->nullProvider,
            'mock' => $this->mockProvider,
            default => null,
        };
    }

    private function guardBudget(NarrativeGenerationRequest $request, string $providerName): void
    {
        if (!(bool) config('ai.breaker_enabled', true)) {
            return;
        }

        $tokens = $this->estimateTokens($request);
        $costUsd = $this->estimateCostUsd($tokens);
        $model = trim((string) config('ai.narrative.model', config('ai.model', 'mock-model')));
        $subject = $request->budgetSubject();

        $this->budgetLedger->checkAndThrow($providerName, $model, $subject, $tokens, $costUsd, 'day');
        $this->budgetLedger->checkAndThrow($providerName, $model, $subject, $tokens, $costUsd, 'month');
    }

    private function incrementBudget(NarrativeGenerationRequest $request, NarrativeGenerationResponse $response): void
    {
        if (!(bool) config('ai.breaker_enabled', true)) {
            return;
        }

        $model = trim((string) config('ai.narrative.model', config('ai.model', 'mock-model')));
        $this->budgetLedger->incrementTokens(
            $response->providerName,
            $model,
            $request->budgetSubject(),
            $response->tokensIn,
            $response->tokensOut,
            $response->costUsd,
        );
    }

    private function estimateTokens(NarrativeGenerationRequest $request): int
    {
        $encoded = json_encode($request->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return max(1, (int) ceil(strlen($encoded) / 4));
    }

    private function estimateCostUsd(int $tokens): float
    {
        $rate = (float) config('ai.cost_per_1k_tokens_usd', 0.0);

        return $rate > 0 ? round(($tokens / 1000.0) * $rate, 6) : 0.0;
    }

    private function fallbackResponse(
        NarrativeGenerationRequest $request,
        string $providerName,
        string $errorCode
    ): NarrativeGenerationResponse {
        $summary = $this->extractSummaryText($request->authority['action_plan_summary'] ?? null);
        if ($summary === '') {
            $summary = $this->extractSummaryText($request->authority['explainability_summary'] ?? null);
        }
        if ($summary === '') {
            $summary = $this->extractSummaryText($request->authority['work_style_summary'] ?? null);
        }

        $sectionKeys = $this->extractNarrativeSectionKeys($request->authority, 4);

        $output = [
            'narrative_intro' => 'Deterministic narrative fallback is active.',
            'narrative_summary' => $summary !== '' ? $summary : 'Structured authority remains available while the narrative runtime is degraded.',
            'section_narrative_keys' => $sectionKeys,
        ];

        $fingerprint = hash('sha256', json_encode([
            'contract_version' => self::CONTRACT_VERSION,
            'runtime_mode' => 'fallback',
            'provider' => $providerName,
            'surface' => $request->surface,
            'scale_code' => $request->scaleCode,
            'locale' => $request->locale,
            'authority' => $request->fingerprintPayload(),
            'output' => $output,
            'error_code' => $errorCode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return new NarrativeGenerationResponse(
            runtimeMode: 'fallback',
            providerName: $providerName,
            modelVersion: trim((string) config('ai.narrative.model', config('ai.model', 'mock-model'))),
            promptVersion: trim((string) config('ai.narrative.prompt_version', config('ai.prompt_version', 'v1.0.0'))),
            failOpenMode: trim((string) config('ai.narrative.fail_open_mode', 'deterministic')),
            narrativeFingerprint: $fingerprint,
            output: $output,
            errorCode: $errorCode,
        );
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return list<string>
     */
    public function extractNarrativeSectionKeys(array $authority, int $limit = 4): array
    {
        $variantKeys = $authority['variant_keys'] ?? [];
        $sectionKeys = [];

        if (is_array($variantKeys)) {
            $isList = array_is_list($variantKeys);
            foreach ($variantKeys as $key => $value) {
                $candidate = null;

                if (! $isList && is_string($key) && trim($key) !== '') {
                    $candidate = trim($key);
                } elseif (is_scalar($value)) {
                    $candidate = trim((string) $value);
                }

                if ($candidate === null || $candidate === '') {
                    continue;
                }

                $sectionKeys[] = $candidate;
                if (count($sectionKeys) >= $limit) {
                    return $sectionKeys;
                }
            }
        }

        foreach (['career_journey_keys', 'ordered_section_keys'] as $fallbackKey) {
            $values = $authority[$fallbackKey] ?? [];
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $candidate = trim((string) $value);
                if ($candidate === '' || in_array($candidate, $sectionKeys, true)) {
                    continue;
                }

                $sectionKeys[] = $candidate;
                if (count($sectionKeys) >= $limit) {
                    return $sectionKeys;
                }
            }
        }

        return $sectionKeys;
    }

    private function extractSummaryText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach (['headline', 'summary', 'label'] as $key) {
            $candidate = trim((string) ($value[$key] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach (['reasons', 'actions', 'bullets'] as $key) {
            $items = $value[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_scalar($item)) {
                    continue;
                }

                $candidate = trim((string) $item);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }
}
