<?php

declare(strict_types=1);

namespace App\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\Occupation;
use App\Models\OccupationSkillGraph;

final class CareerTransitionStepAuthor
{
    /**
     * @return list<string>
     */
    public function authorForOccupation(Occupation $sourceOccupation): array
    {
        $skillGraph = $sourceOccupation->skillGraphs()
            ->orderBy('stack_key')
            ->orderByDesc('updated_at')
            ->first();

        if (! $skillGraph instanceof OccupationSkillGraph) {
            return [];
        }

        $labels = [];

        foreach ($this->graphFieldMap() as $label => $field) {
            if ($this->graphHasAuthoritativeSignal($skillGraph->{$field} ?? null)) {
                $labels[] = $label;
            }
        }

        return TransitionPathPayload::from(['steps' => $labels])->steps;
    }

    /**
     * @return array<string, string>
     */
    private function graphFieldMap(): array
    {
        return [
            TransitionPathPayload::STEP_SKILL_OVERLAP => 'skill_overlap_graph',
            TransitionPathPayload::STEP_TASK_OVERLAP => 'task_overlap_graph',
            TransitionPathPayload::STEP_TOOL_OVERLAP => 'tool_overlap_graph',
        ];
    }

    private function graphHasAuthoritativeSignal(mixed $graph): bool
    {
        if (! is_array($graph) || $graph === []) {
            return false;
        }

        foreach ($graph as $value) {
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }
}
