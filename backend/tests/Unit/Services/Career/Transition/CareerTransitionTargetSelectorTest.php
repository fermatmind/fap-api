<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career\Transition;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use App\Services\Career\Transition\CareerTransitionTargetSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionTargetSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_selects_a_same_family_publish_ready_non_self_target(): void
    {
        $snapshot = $this->sourceSnapshot();
        $source = $snapshot->occupation()->firstOrFail();
        $candidate = $this->sameFamilyOccupation($source, 'backend-platform-engineer', 'Backend Platform Engineer');

        $this->mockReadiness([
            (string) $candidate->canonical_slug => $this->readinessRow($candidate->canonical_slug),
        ]);

        $selected = app(CareerTransitionTargetSelector::class)->selectForSnapshot($snapshot);

        $this->assertInstanceOf(Occupation::class, $selected);
        $this->assertSame($candidate->id, $selected?->id);
        $this->assertNotSame($source->id, $selected?->id);
        $this->assertSame($source->family_id, $selected?->family_id);
    }

    public function test_it_returns_null_when_only_self_or_unsafe_targets_exist(): void
    {
        $snapshot = $this->sourceSnapshot();
        $source = $snapshot->occupation()->firstOrFail();
        $unsafeSameFamily = $this->sameFamilyOccupation($source, 'backend-architect-unsafe', 'Backend Architect Unsafe');
        $otherFamily = $this->otherFamilyOccupation('registered-nurses', 'Registered Nurses');

        $this->mockReadiness([
            (string) $unsafeSameFamily->canonical_slug => $this->readinessRow($unsafeSameFamily->canonical_slug, status: 'blocked_override_eligible', indexEligible: false),
            (string) $otherFamily->canonical_slug => $this->readinessRow($otherFamily->canonical_slug),
        ]);

        $selected = app(CareerTransitionTargetSelector::class)->selectForSnapshot($snapshot);

        $this->assertNull($selected);
    }

    public function test_it_filters_by_index_eligibility_reviewer_status_and_crosswalk_mode(): void
    {
        $snapshot = $this->sourceSnapshot();
        $source = $snapshot->occupation()->firstOrFail();

        $indexBlocked = $this->sameFamilyOccupation($source, 'backend-index-blocked', 'Backend Index Blocked');
        $reviewBlocked = $this->sameFamilyOccupation($source, 'backend-review-blocked', 'Backend Review Blocked');
        $crosswalkBlocked = $this->sameFamilyOccupation($source, 'backend-crosswalk-blocked', 'Backend Crosswalk Blocked');

        $this->mockReadiness([
            (string) $indexBlocked->canonical_slug => $this->readinessRow($indexBlocked->canonical_slug, indexEligible: false),
            (string) $reviewBlocked->canonical_slug => $this->readinessRow($reviewBlocked->canonical_slug, reviewerStatus: 'pending'),
            (string) $crosswalkBlocked->canonical_slug => $this->readinessRow($crosswalkBlocked->canonical_slug, crosswalkMode: 'family_proxy'),
        ]);

        $this->assertNull(app(CareerTransitionTargetSelector::class)->selectForSnapshot($snapshot));
    }

    public function test_it_breaks_ties_deterministically_by_crosswalk_safety_then_canonical_order(): void
    {
        $snapshot = $this->sourceSnapshot();
        $source = $snapshot->occupation()->firstOrFail();

        $trustInherited = $this->sameFamilyOccupation($source, 'backend-zeta', 'Backend Zeta');
        $exactAlpha = $this->sameFamilyOccupation($source, 'backend-alpha', 'Backend Alpha');
        $exactOmega = $this->sameFamilyOccupation($source, 'backend-omega', 'Backend Omega');

        $this->mockReadiness([
            (string) $trustInherited->canonical_slug => $this->readinessRow($trustInherited->canonical_slug, crosswalkMode: 'trust_inheritance'),
            (string) $exactAlpha->canonical_slug => $this->readinessRow($exactAlpha->canonical_slug, crosswalkMode: 'exact'),
            (string) $exactOmega->canonical_slug => $this->readinessRow($exactOmega->canonical_slug, crosswalkMode: 'exact'),
        ]);

        $selected = app(CareerTransitionTargetSelector::class)->selectForSnapshot($snapshot);

        $this->assertInstanceOf(Occupation::class, $selected);
        $this->assertSame($exactAlpha->id, $selected?->id);
    }

    private function sourceSnapshot(): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-source',
        ]);

        return $chain['recommendationSnapshot'];
    }

    private function sameFamilyOccupation(Occupation $source, string $slug, string $title): Occupation
    {
        return Occupation::query()->create([
            'family_id' => $source->family_id,
            'parent_id' => null,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => $source->truth_market,
            'display_market' => $source->display_market,
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
            'structural_stability' => $source->structural_stability,
            'task_prototype_signature' => $source->task_prototype_signature,
            'market_semantics_gap' => $source->market_semantics_gap,
            'regulatory_divergence' => $source->regulatory_divergence,
            'toolchain_divergence' => $source->toolchain_divergence,
            'skill_gap_threshold' => $source->skill_gap_threshold,
            'trust_inheritance_scope' => $source->trust_inheritance_scope,
        ]);
    }

    private function otherFamilyOccupation(string $slug, string $title): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'healthcare-'.$slug,
            'title_en' => 'Healthcare',
            'title_zh' => '医疗',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'parent_id' => null,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
            'structural_stability' => 0.82,
            'task_prototype_signature' => ['analysis' => 0.5],
            'market_semantics_gap' => 0.12,
            'regulatory_divergence' => 0.11,
            'toolchain_divergence' => 0.17,
            'skill_gap_threshold' => 0.35,
            'trust_inheritance_scope' => ['allow_task_truth' => true],
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsBySlug
     */
    private function mockReadiness(array $rowsBySlug): void
    {
        $lookup = Mockery::mock(CareerTransitionPreviewReadinessLookup::class);
        foreach ($rowsBySlug as $slug => $row) {
            $lookup->shouldReceive('bySlug')->with($slug)->andReturn($row);
        }
        $lookup->shouldReceive('bySlug')->andReturn(null);

        $this->app->instance(CareerTransitionPreviewReadinessLookup::class, $lookup);
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessRow(
        string $canonicalSlug,
        string $status = 'publish_ready',
        bool $indexEligible = true,
        string $reviewerStatus = 'approved',
        string $crosswalkMode = 'exact',
    ): array {
        return [
            'occupation_uuid' => 'uuid-'.$canonicalSlug,
            'canonical_slug' => $canonicalSlug,
            'canonical_title_en' => $canonicalSlug,
            'status' => $status,
            'blocker_type' => null,
            'remediation_class' => null,
            'authority_override_supplied' => false,
            'review_required' => false,
            'crosswalk_mode' => $crosswalkMode,
            'reviewer_status' => $reviewerStatus,
            'index_state' => $indexEligible ? 'indexable' : 'noindex',
            'index_eligible' => $indexEligible,
            'reason_codes' => [$status],
        ];
    }
}
