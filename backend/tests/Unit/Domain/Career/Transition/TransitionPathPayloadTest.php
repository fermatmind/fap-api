<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use PHPUnit\Framework\TestCase;

final class TransitionPathPayloadTest extends TestCase
{
    public function test_it_accepts_missing_payload_and_returns_an_empty_normalized_shape(): void
    {
        $this->assertSame([], TransitionPathPayload::from(null)->toArray());
        $this->assertSame([], TransitionPathPayload::from('invalid')->toArray());
        $this->assertSame([], TransitionPathPayload::from([])->toArray());
    }

    public function test_it_accepts_steps_as_a_list_of_strings(): void
    {
        $payload = TransitionPathPayload::from([
            'steps' => [' skill_overlap ', 'task_overlap', 'tool_overlap'],
            'rationale_codes' => ['skill_overlap', 'same_family_target'],
            'tradeoff_codes' => ['higher_entry_education_required'],
            'delta' => [
                'entry_education_delta' => [
                    'source_value' => "Bachelor's degree",
                    'target_value' => "Master's degree",
                    'direction' => 'higher',
                ],
            ],
        ]);

        $this->assertSame([
            'steps' => [
                'skill_overlap',
                'task_overlap',
                'tool_overlap',
            ],
            'rationale_codes' => [
                'skill_overlap',
                'same_family_target',
            ],
            'tradeoff_codes' => [
                'higher_entry_education_required',
            ],
            'delta' => [
                'entry_education_delta' => [
                    'source_value' => "Bachelor's degree",
                    'target_value' => "Master's degree",
                    'direction' => 'higher',
                ],
            ],
        ], $payload->toArray());
    }

    public function test_it_strips_invalid_steps_and_ignores_unsupported_or_narrative_fields(): void
    {
        $payload = TransitionPathPayload::from([
            'steps' => ['skill_overlap', 'deepen system design', 42, '', null, ' task_overlap ', 'skill_overlap'],
            'rationale_codes' => ['same_family_target', 'best_next_move', null],
            'tradeoff_codes' => ['higher_entry_education_required', 'sacrifice_salary'],
            'delta' => [
                'entry_education_delta' => [
                    'source_value' => "Bachelor's degree",
                    'target_value' => "Master's degree",
                    'direction' => 'higher',
                ],
                'salary_delta' => [
                    'source_value' => '100000',
                    'target_value' => '90000',
                    'direction' => 'lower',
                ],
            ],
            'why_this_path' => 'fixture-only narrative',
            'what_is_lost' => 'fixture-only tradeoff copy',
            'bridge_steps_90d' => ['not authoritative'],
        ]);

        $this->assertSame([
            'steps' => [
                'skill_overlap',
                'task_overlap',
            ],
            'rationale_codes' => [
                'same_family_target',
            ],
            'tradeoff_codes' => [
                'higher_entry_education_required',
            ],
            'delta' => [
                'entry_education_delta' => [
                    'source_value' => "Bachelor's degree",
                    'target_value' => "Master's degree",
                    'direction' => 'higher',
                ],
            ],
        ], $payload->toArray());
    }
}
