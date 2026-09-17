<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDetailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_job_detail_uses_the_current_file_authority(): void
    {
        $this->publication(true);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh')
            ->assertJsonPath('bundle_kind', 'career_job_detail')
            ->assertJsonPath('bundle_version', 'career.detail.page.v1')
            ->assertJsonPath('identity.canonical_slug', 'accountants-and-auditors')
            ->assertJsonPath('career_page.contract_version', 'career.detail.page.v1')
            ->assertJsonPath('career_page.content.contract_version', 'career.detail.content.v3')
            ->assertJsonPath('career_page.content.content_state', 'enhanced')
            ->assertJsonPath('seo_contract.canonical_path', '/zh/career/jobs/accountants-and-auditors')
            ->assertJsonMissingPath('display_surface_v1')
            ->assertJsonMissingPath('truth_layer');
    }

    public function test_requested_locale_is_preserved_without_cross_locale_fallback(): void
    {
        $this->publication(true);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=en')
            ->assertOk()
            ->assertJsonPath('career_page.locale', 'en')
            ->assertJsonPath('career_page.subject.canonical_slug', 'accountants-and-auditors')
            ->assertJsonPath('locale_policy.requested_locale', 'en')
            ->assertJsonPath('seo_contract.canonical_path', '/en/career/jobs/accountants-and-auditors');
    }

    public function test_unpublished_identity_remains_not_found(): void
    {
        $this->publication(false);

        $this->getJson('/api/v0.5/career/jobs/actors?locale=en')->assertNotFound();
    }

    public function test_missing_authoritative_file_fails_closed(): void
    {
        $this->publication(true);

        $this->getJson('/api/v0.5/career/jobs/not-a-current-career?locale=en')
            ->assertStatus(503)
            ->assertJsonPath('error', 'CAREER_PAGE_UNAVAILABLE');
    }

    private function publication(bool $published): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: $published),
        );
    }
}
