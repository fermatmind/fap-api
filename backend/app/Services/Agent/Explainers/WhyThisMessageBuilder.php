<?php

namespace App\Services\Agent\Explainers;

final class WhyThisMessageBuilder
{
    public function build(string $triggerType, array $metrics, array $sourceRefs, array $policy): array
    {
        return [
            'trigger_type' => $triggerType,
            'metrics' => $metrics,
            'source_refs' => $sourceRefs,
            'policies' => $policy,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
