<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\Occupation;
use App\Models\OccupationSkillGraph;
use App\Services\Career\Transition\CareerTransitionStepAuthor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionStepAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_authors_deterministic_machine_coded_step_labels_from_authoritative_overlap_graphs(): void
    {
        $occupation = $this->seedOccupationWithGraphs([
            'skill_overlap_graph' => ['distributed_systems' => 0.82],
            'task_overlap_graph' => ['api_contracts' => 0.91],
            'tool_overlap_graph' => ['kubernetes' => 0.71],
        ]);

        $steps = app(CareerTransitionStepAuthor::class)->authorForOccupation($occupation);

        $this->assertSame([
            TransitionPathPayload::STEP_SKILL_OVERLAP,
            TransitionPathPayload::STEP_TASK_OVERLAP,
            TransitionPathPayload::STEP_TOOL_OVERLAP,
        ], $steps);
    }

    public function test_it_only_emits_allowed_internal_step_labels(): void
    {
        $occupation = $this->seedOccupationWithGraphs([
            'skill_overlap_graph' => ['self_baseline' => 1.0],
            'task_overlap_graph' => ['self_baseline' => 1.0],
            'tool_overlap_graph' => ['self_baseline' => 1.0],
        ]);

        $steps = app(CareerTransitionStepAuthor::class)->authorForOccupation($occupation);

        $this->assertSame(TransitionPathPayload::allowedStepLabels(), $steps);
    }

    public function test_it_returns_an_empty_step_list_when_no_authoritative_overlap_graph_exists(): void
    {
        $occupation = $this->seedOccupationWithoutGraphs();

        $steps = app(CareerTransitionStepAuthor::class)->authorForOccupation($occupation);

        $this->assertSame([], $steps);
    }

    /**
     * @param  array<string, array<string, float>>  $graphs
     */
    private function seedOccupationWithGraphs(array $graphs): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();

        $chain['skillGraph']->delete();

        OccupationSkillGraph::query()->create([
            'occupation_id' => $chain['occupation']->id,
            'stack_key' => 'core',
            'skill_overlap_graph' => $graphs['skill_overlap_graph'] ?? [],
            'task_overlap_graph' => $graphs['task_overlap_graph'] ?? [],
            'tool_overlap_graph' => $graphs['tool_overlap_graph'] ?? [],
        ]);

        return $chain['occupation']->fresh();
    }

    private function seedOccupationWithoutGraphs(): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();

        $chain['skillGraph']->delete();

        return $chain['occupation']->fresh();
    }
}
