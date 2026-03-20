<?php

declare(strict_types=1);

namespace App\Services\AI;

final class NullNarrativeProvider implements NarrativeProviderInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function generate(NarrativeGenerationRequest $request): NarrativeGenerationResponse
    {
        return new NarrativeGenerationResponse(
            runtimeMode: 'off',
            providerName: $this->name(),
            modelVersion: trim((string) config('ai.narrative.model', config('ai.model', 'mock-model'))),
            promptVersion: trim((string) config('ai.narrative.prompt_version', config('ai.prompt_version', 'v1.0.0'))),
            failOpenMode: 'off',
            narrativeFingerprint: hash('sha256', json_encode([
                'provider' => $this->name(),
                'surface' => $request->surface,
                'scale_code' => $request->scaleCode,
                'locale' => $request->locale,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            output: [
                'narrative_intro' => '',
                'narrative_summary' => '',
                'section_narrative_keys' => [],
            ],
        );
    }
}
