<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Services\Career\Transition\CareerTransitionContractBuilder;
use PHPUnit\Framework\TestCase;

final class CareerTransitionContractBuilderTest extends TestCase
{
    public function test_it_builds_additive_transition_contract_fields_from_normalized_payload(): void
    {
        $payload = TransitionPathPayload::from([
            'steps' => [
                TransitionPathPayload::STEP_SKILL_OVERLAP,
                TransitionPathPayload::STEP_TASK_OVERLAP,
                TransitionPathPayload::STEP_TOOL_OVERLAP,
            ],
            'rationale_codes' => [
                TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
                TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET,
            ],
            'tradeoff_codes' => [
                TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED,
                TransitionPathPayload::TRADEOFF_HIGHER_TRAINING_REQUIRED,
            ],
        ]);

        $contract = app(CareerTransitionContractBuilder::class)->build($payload);

        $this->assertSame([
            TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
            TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET,
        ], $contract['rationale_codes'] ?? null);
        $this->assertSame([
            TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED,
            TransitionPathPayload::TRADEOFF_HIGHER_TRAINING_REQUIRED,
        ], $contract['tradeoff_codes'] ?? null);
        $this->assertIsString($contract['why_this_path'] ?? null);
        $this->assertIsString($contract['what_is_lost'] ?? null);
        $this->assertSame([
            'days_0_30',
            'days_31_60',
            'days_61_90',
        ], array_column($contract['bridge_steps_90d'] ?? [], 'time_horizon'));
        $this->assertSame([
            TransitionPathPayload::STEP_SKILL_OVERLAP,
            TransitionPathPayload::STEP_TASK_OVERLAP,
            TransitionPathPayload::STEP_TOOL_OVERLAP,
        ], array_column($contract['bridge_steps_90d'] ?? [], 'step_key'));
    }

    public function test_it_omits_fields_when_payload_has_no_stable_transition_explainability_truth(): void
    {
        $payload = TransitionPathPayload::from([
            'steps' => [],
            'rationale_codes' => [],
            'tradeoff_codes' => [],
        ]);

        $this->assertSame([], app(CareerTransitionContractBuilder::class)->build($payload));
    }
}
