<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFoundationLineageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function lineage_chain_can_link_projection_context_recommendation_and_transition_rows(): void
    {
        $chain = CareerFoundationFixture::seedMinimalChain();

        $recommendation = RecommendationSnapshot::query()->findOrFail($chain['recommendationSnapshot']->id);
        $transitionPath = TransitionPath::query()->findOrFail($chain['transitionPath']->id);

        $this->assertSame($chain['childProjection']->id, $recommendation->profileProjection?->id);
        $this->assertSame($chain['contextSnapshot']->id, $recommendation->contextSnapshot?->id);
        $this->assertSame($chain['occupation']->id, $recommendation->occupation?->id);
        $this->assertSame($recommendation->id, $transitionPath->recommendationSnapshot?->id);
        $this->assertSame($chain['occupation']->id, $transitionPath->fromOccupation?->id);
        $this->assertSame($chain['occupation']->id, $transitionPath->toOccupation?->id);
    }
}
