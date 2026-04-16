<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerShortlistItem;
use App\Services\Career\CareerShortlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerShortlistServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_real_shortlist_item_and_is_idempotent_per_visitor_subject_surface(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'shortlist-service-case']);

        $service = app(CareerShortlistService::class);

        $first = $service->add([
            'visitor_key' => 'visitor_shortlist_1',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'shortlist-service-case',
            'source_page_type' => 'career_recommendation_detail',
            'context_snapshot_uuid' => $chain['contextSnapshot']->id,
            'projection_uuid' => $chain['childProjection']->id,
            'recommendation_snapshot_uuid' => $chain['recommendationSnapshot']->id,
        ]);

        $this->assertTrue($first['is_new']);
        $this->assertSame('shortlist-service-case', $first['item']->subject_slug);
        $this->assertSame('job_slug', $first['item']->subject_kind);
        $this->assertSame('career_recommendation_detail', $first['item']->source_page_type);

        $second = $service->add([
            'visitor_key' => 'visitor_shortlist_1',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'shortlist-service-case',
            'source_page_type' => 'career_recommendation_detail',
        ]);

        $this->assertFalse($second['is_new']);
        $this->assertSame($first['item']->id, $second['item']->id);
        $this->assertSame(1, CareerShortlistItem::query()->count());
    }

    public function test_it_resolves_shortlist_state_by_visitor_subject_and_surface(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'shortlist-state-case']);

        $service = app(CareerShortlistService::class);

        $service->add([
            'visitor_key' => 'visitor_shortlist_2',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'shortlist-state-case',
            'source_page_type' => 'career_job_detail',
        ]);

        $state = $service->resolveState('visitor_shortlist_2', 'job_slug', 'shortlist-state-case', 'career_job_detail');

        $this->assertTrue($state['is_shortlisted']);
        $this->assertNotNull($state['latest_item']);
        $this->assertSame('shortlist-state-case', $state['latest_item']->subject_slug);
    }
}
