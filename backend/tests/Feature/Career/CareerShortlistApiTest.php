<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

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
}
