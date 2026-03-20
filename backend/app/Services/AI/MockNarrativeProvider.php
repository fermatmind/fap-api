<?php

declare(strict_types=1);

namespace App\Services\AI;

final class MockNarrativeProvider implements NarrativeProviderInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function generate(NarrativeGenerationRequest $request): NarrativeGenerationResponse
    {
        $typeCode = trim((string) ($request->authority['type_code'] ?? $request->context['type_code'] ?? ''));
        $identity = trim((string) ($request->authority['identity'] ?? $request->context['identity'] ?? ''));
        $focusKey = trim((string) data_get($request->authority, 'working_life_v1.career_focus_key', ''))
            ?: trim((string) ($request->authority['action_focus_key'] ?? ''))
            ?: trim((string) data_get($request->authority, 'orchestration.primary_focus_key', ''));

        $summary = trim((string) ($request->authority['action_plan_summary'] ?? ''));
        if ($summary === '') {
            $summary = trim((string) ($request->authority['explainability_summary'] ?? ''));
        }
        if ($summary === '') {
            $summary = trim((string) ($request->authority['work_style_summary'] ?? ''));
        }

        $sectionKeys = array_values(array_slice(array_keys((array) ($request->authority['variant_keys'] ?? [])), 0, 6));
        if ($sectionKeys === []) {
            $sectionKeys = array_values(array_slice((array) data_get($request->authority, 'working_life_v1.career_journey_keys', []), 0, 4));
        }

        $introParts = array_values(array_filter([
            $typeCode !== '' ? $typeCode : null,
            $identity !== '' ? "identity {$identity}" : null,
            $focusKey !== '' ? "focus {$focusKey}" : null,
        ]));

        $intro = $introParts !== []
            ? 'Controlled narrative runtime ready for '.implode(' / ', $introParts).'.'
            : 'Controlled narrative runtime ready from structured authority.';

        $output = [
            'narrative_intro' => $intro,
            'narrative_summary' => $summary !== '' ? $summary : 'Structured authority is available for controlled narrative assembly.',
            'section_narrative_keys' => $sectionKeys,
        ];

        $payload = [
            'provider' => $this->name(),
            'surface' => $request->surface,
            'scale_code' => $request->scaleCode,
            'locale' => $request->locale,
            'authority' => $request->fingerprintPayload(),
            'output' => $output,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $tokensIn = max(1, (int) ceil(strlen($encoded) / 4));
        $tokensOut = max(1, (int) ceil(strlen(json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') / 4));
        $costPer1k = (float) config('ai.cost_per_1k_tokens_usd', 0.0);

        return new NarrativeGenerationResponse(
            runtimeMode: 'mock',
            providerName: $this->name(),
            modelVersion: trim((string) config('ai.narrative.model', config('ai.model', 'mock-model'))),
            promptVersion: trim((string) config('ai.narrative.prompt_version', config('ai.prompt_version', 'v1.0.0'))),
            failOpenMode: trim((string) config('ai.narrative.fail_open_mode', 'deterministic')),
            narrativeFingerprint: hash('sha256', $encoded),
            output: $output,
            errorCode: null,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            costUsd: round((($tokensIn + $tokensOut) / 1000.0) * $costPer1k, 6),
        );
    }
}
