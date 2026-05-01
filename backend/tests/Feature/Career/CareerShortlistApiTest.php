<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerShortlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerShortlistApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_and_reads_shortlist_state_through_public_career_endpoints(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'shortlist-api-case']);

        $this->postJson('/api/v0.5/career/shortlist?locale=en', [
            'visitor_key' => 'visitor_shortlist_api',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'shortlist-api-case',
            'source_page_type' => 'career_recommendation_detail',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.subject_kind', 'job_slug')
            ->assertJsonPath('data.subject_slug', 'shortlist-api-case')
            ->assertJsonPath('data.source_page_type', 'career_recommendation_detail')
            ->assertJsonPath('data.is_new', true);

        $this->getJson('/api/v0.5/career/shortlist/state?locale=en&visitor_key=visitor_shortlist_api&subject_kind=job_slug&subject_slug=shortlist-api-case&source_page_type=career_recommendation_detail')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.is_shortlisted', true)
            ->assertJsonPath('data.latest_item.subject_slug', 'shortlist-api-case');
    }

    public function test_it_rejects_shortlist_payloads_with_unknown_or_internal_public_fields(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'shortlist-public-case']);

        $this->postJson('/api/v0.5/career/shortlist?locale=en', [
            'visitor_key' => 'visitor_shortlist_public_case',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'shortlist-public-case',
            'source_page_type' => 'career_recommendation_detail',
            'recommendation_snapshot_uuid' => '11111111-1111-4111-8111-111111111111',
        ])->assertStatus(422);

        $this->postJson('/api/v0.5/career/shortlist?locale=en', [
            'visitor_key' => 'visitor_shortlist_public_case',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'not-a-real-public-job',
            'source_page_type' => 'career_recommendation_detail',
        ])->assertStatus(422);

        $this->postJson('/api/v0.5/career/shortlist?locale=en', [
            'visitor_key' => 'visitor_shortlist_public_case',
            'subject_kind' => 'job_slug',
            'subject_slug' => str_repeat('a', 97),
            'source_page_type' => 'career_recommendation_detail',
        ])->assertStatus(422);

        $this->assertSame(0, CareerShortlistItem::query()->count());
    }
}
