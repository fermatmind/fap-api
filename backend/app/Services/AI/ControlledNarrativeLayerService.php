<?php

declare(strict_types=1);

namespace App\Services\AI;

final class ControlledNarrativeLayerService
{
    public const VERSION = 'controlled_narrative.v1';

    /**
     * @param  array<string, mixed>  $runtimeContract
     * @return array<string, mixed>
     */
    public function buildFromRuntimeContract(array $runtimeContract): array
    {
        $response = is_array($runtimeContract['response'] ?? null) ? $runtimeContract['response'] : [];

        $intro = trim((string) ($response['narrative_intro'] ?? ''));
        $summary = trim((string) ($response['narrative_summary'] ?? ''));
        $sectionNarrativeKeys = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($response['section_narrative_keys'] ?? null) ? $response['section_narrative_keys'] : []
        )));

        return [
            'version' => self::VERSION,
            'narrative_contract_version' => self::VERSION,
            'runtime_contract_version' => trim((string) ($runtimeContract['version'] ?? '')),
            'runtime_mode' => trim((string) ($runtimeContract['runtime_mode'] ?? 'off')),
            'provider_name' => trim((string) ($runtimeContract['provider_name'] ?? '')),
            'model_version' => trim((string) ($runtimeContract['model_version'] ?? '')),
            'prompt_version' => trim((string) ($runtimeContract['prompt_version'] ?? '')),
            'fail_open_mode' => trim((string) ($runtimeContract['fail_open_mode'] ?? '')),
            'narrative_fingerprint' => trim((string) ($runtimeContract['narrative_fingerprint'] ?? '')),
            'narrative_intro' => $intro,
            'narrative_summary' => $summary,
            'section_narrative_keys' => $sectionNarrativeKeys,
            'enabled' => $intro !== '' || $summary !== '' || $sectionNarrativeKeys !== [],
            'truth_guard_fields' => array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($runtimeContract['truth_guard_fields'] ?? null) ? $runtimeContract['truth_guard_fields'] : []
            ))),
        ];
    }
}
