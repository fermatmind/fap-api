<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDisplaySurfaceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: true),
        );
    }

    public function test_current_page_replaces_the_retired_display_surface(): void
    {
        foreach (['zh-CN', 'en'] as $locale) {
            $response = $this->getJson('/api/v0.5/career/jobs/actors?locale='.$locale)
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', 'actors')
                ->assertJsonPath('career_page.subject.canonical_slug', 'actors')
                ->assertJsonPath('career_page.locale', $locale)
                ->assertJsonMissingPath('display_surface_v1');

            self::assertMatchesRegularExpression(
                '/\A[a-f0-9]{64}\z/',
                (string) $response->json('career_page.source_content_sha256'),
            );
        }
    }

    public function test_enhanced_current_page_exposes_only_public_content(): void
    {
        $response = $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('career_page.content.content_state', 'enhanced')
            ->assertJsonPath('career_page.content.contract_version', 'career.detail.content.v3')
            ->assertJsonMissingPath('career_page.content.authoring_structure');

        $encoded = json_encode($response->json('career_page'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"visibility":"internal"', $encoded);
        self::assertStringNotContainsString('display_surface_v1', $encoded);
    }

    public function test_legacy_current_page_keeps_its_public_identity_without_inventing_body_content(): void
    {
        $this->getJson('/api/v0.5/career/jobs/health-educators?locale=en')
            ->assertOk()
            ->assertJsonPath('career_page.content.content_state', 'legacy')
            ->assertJsonPath('career_page.content.blocks', [])
            ->assertJsonPath('career_page.subject.canonical_slug', 'health-educators')
            ->assertJsonMissingPath('display_surface_v1');
    }
}
