<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\ControlledGenerationRuntime;
use Tests\TestCase;

final class ControlledGenerationRuntimeTest extends TestCase
{
    public function test_runtime_off_returns_null_provider_contract_without_touching_truth(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.narrative.enabled', false);
        config()->set('ai.breaker_enabled', false);

        $contract = app(ControlledGenerationRuntime::class)->buildContract(
            'mbti.report',
            'MBTI',
            'zh-CN',
            [
                'type_code' => 'INTJ-A',
                'identity' => 'A',
                'variant_keys' => ['overview' => 'overview:clear'],
            ],
        );

        $this->assertSame('narrative_runtime_contract.v1', $contract['version']);
        $this->assertSame('off', $contract['runtime_mode']);
        $this->assertSame('null', $contract['provider_name']);
        $this->assertSame('', $contract['response']['narrative_intro']);
        $this->assertSame('', $contract['response']['narrative_summary']);
        $this->assertContains('variant_keys', $contract['truth_guard_fields']);
    }

    public function test_runtime_mock_provider_returns_versioned_contract_and_stable_fingerprint(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.narrative.enabled', true);
        config()->set('ai.narrative.provider', 'mock');
        config()->set('ai.narrative.model', 'mock-narrative-model');
        config()->set('ai.narrative.prompt_version', 'prompt.9d0.v1');
        config()->set('ai.breaker_enabled', false);

        $runtime = app(ControlledGenerationRuntime::class);
        $authority = [
            'type_code' => 'INTJ-A',
            'identity' => 'A',
            'explainability_summary' => 'Structured explainability is available.',
            'action_plan_summary' => 'Use one repeatable next step.',
            'variant_keys' => [
                'overview' => 'overview:clear',
                'growth.next_actions' => 'growth.next_actions:repeatable',
            ],
            'working_life_v1' => ['career_focus_key' => 'career.next_step'],
        ];

        $first = $runtime->buildContract('mbti.report', 'MBTI', 'zh-CN', $authority);
        $second = $runtime->buildContract('mbti.report', 'MBTI', 'zh-CN', $authority);

        $this->assertSame('mock', $first['runtime_mode']);
        $this->assertSame('mock', $first['provider_name']);
        $this->assertSame('mock-narrative-model', $first['model_version']);
        $this->assertSame('prompt.9d0.v1', $first['prompt_version']);
        $this->assertNotSame('', trim((string) $first['response']['narrative_intro']));
        $this->assertNotSame('', trim((string) $first['response']['narrative_summary']));
        $this->assertNotEmpty($first['response']['section_narrative_keys']);
        $this->assertSame($first['narrative_fingerprint'], $second['narrative_fingerprint']);
    }

    public function test_runtime_fails_open_to_deterministic_fallback_when_provider_is_unavailable(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.narrative.enabled', true);
        config()->set('ai.narrative.provider', 'openai');
        config()->set('ai.breaker_enabled', false);
        config()->set('ai.narrative.fail_open_mode', 'deterministic');

        $contract = app(ControlledGenerationRuntime::class)->buildContract(
            'mbti.report',
            'MBTI',
            'zh-CN',
            [
                'type_code' => 'INTJ-A',
                'action_plan_summary' => 'Canonical action summary stays intact.',
                'variant_keys' => ['growth.next_actions' => 'growth.next_actions:repeatable'],
            ],
        );

        $this->assertSame('fallback', $contract['runtime_mode']);
        $this->assertSame('openai', $contract['provider_name']);
        $this->assertSame('deterministic', $contract['fail_open_mode']);
        $this->assertSame('AI_PROVIDER_UNAVAILABLE', $contract['error_code']);
        $this->assertSame('Canonical action summary stays intact.', $contract['response']['narrative_summary']);
    }
}
