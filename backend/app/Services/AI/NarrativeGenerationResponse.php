<?php

declare(strict_types=1);

namespace App\Services\AI;

final class NarrativeGenerationResponse
{
    /**
     * @param  array<string, mixed>  $output
     */
    public function __construct(
        public readonly string $runtimeMode,
        public readonly string $providerName,
        public readonly string $modelVersion,
        public readonly string $promptVersion,
        public readonly string $failOpenMode,
        public readonly string $narrativeFingerprint,
        public readonly array $output,
        public readonly ?string $errorCode = null,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly float $costUsd = 0.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toContractArray(string $contractVersion, NarrativeGenerationRequest $request, array $truthGuardFields): array
    {
        return [
            'version' => $contractVersion,
            'runtime_mode' => $this->runtimeMode,
            'provider_name' => $this->providerName,
            'model_version' => $this->modelVersion,
            'prompt_version' => $this->promptVersion,
            'fail_open_mode' => $this->failOpenMode,
            'narrative_fingerprint' => $this->narrativeFingerprint,
            'request' => [
                'surface' => $request->surface,
                'scale_code' => $request->scaleCode,
                'locale' => $request->locale,
                'authority_keys' => array_values(array_keys($request->authority)),
            ],
            'response' => [
                'narrative_intro' => trim((string) ($this->output['narrative_intro'] ?? '')),
                'narrative_summary' => trim((string) ($this->output['narrative_summary'] ?? '')),
                'section_narrative_keys' => array_values((array) ($this->output['section_narrative_keys'] ?? [])),
            ],
            'output_present' => [
                'narrative_intro' => trim((string) ($this->output['narrative_intro'] ?? '')) !== '',
                'narrative_summary' => trim((string) ($this->output['narrative_summary'] ?? '')) !== '',
                'section_narrative_keys' => ((array) ($this->output['section_narrative_keys'] ?? [])) !== [],
            ],
            'truth_guard_fields' => $truthGuardFields,
            'error_code' => $this->errorCode,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd' => round($this->costUsd, 6),
        ];
    }
}
