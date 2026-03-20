<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\ControlledNarrativeLayerService;
use Tests\TestCase;

final class ControlledNarrativeLayerServiceTest extends TestCase
{
    public function test_it_builds_a_controlled_narrative_contract_from_runtime_metadata(): void
    {
        $contract = app(ControlledNarrativeLayerService::class)->buildFromRuntimeContract([
            'version' => 'narrative_runtime_contract.v1',
            'runtime_mode' => 'mock',
            'provider_name' => 'mock',
            'model_version' => 'mock-narrative-model',
            'prompt_version' => 'prompt.9d.v1',
            'fail_open_mode' => 'deterministic',
            'narrative_fingerprint' => 'abc123',
            'response' => [
                'narrative_intro' => 'Narrative intro.',
                'narrative_summary' => 'Narrative summary.',
                'section_narrative_keys' => ['growth.next_actions', 'career.next_step'],
            ],
            'truth_guard_fields' => ['type_code', 'variant_keys'],
        ]);

        $this->assertSame('controlled_narrative.v1', $contract['version']);
        $this->assertSame('controlled_narrative.v1', $contract['narrative_contract_version']);
        $this->assertSame('narrative_runtime_contract.v1', $contract['runtime_contract_version']);
        $this->assertSame('mock', $contract['runtime_mode']);
        $this->assertSame('Narrative intro.', $contract['narrative_intro']);
        $this->assertSame('Narrative summary.', $contract['narrative_summary']);
        $this->assertSame(['growth.next_actions', 'career.next_step'], $contract['section_narrative_keys']);
        $this->assertSame(true, $contract['enabled']);
        $this->assertContains('variant_keys', $contract['truth_guard_fields']);
    }
}
