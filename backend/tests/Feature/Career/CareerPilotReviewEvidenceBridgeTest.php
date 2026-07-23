<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\Review\CareerPilotReviewEvidenceBridge;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerPilotReviewEvidenceBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'reviewed-pilot-career';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: true),
        );

        $this->publishBilingualDetails();
        app(PublicCareerAuthorityResponseCache::class)->publishJobIndexReadModelsAtomically([
            'en' => $this->indexPayload(),
            'zh-CN' => $this->indexPayload(),
        ]);
    }

    public function test_package_is_deterministic_bilingual_and_command_is_read_only(): void
    {
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $first = $bridge->buildPackage([self::SLUG]);
        $second = $bridge->buildPackage([self::SLUG, self::SLUG]);

        $this->assertSame($first, $second);
        $this->assertSame(6, $first['target_count']);
        $this->assertSame(
            [
                'career-job:reviewed-pilot-career:en:content',
                'career-job:reviewed-pilot-career:en:seo',
                'career-job:reviewed-pilot-career:en:visible_claims',
                'career-job:reviewed-pilot-career:zh-CN:content',
                'career-job:reviewed-pilot-career:zh-CN:seo',
                'career-job:reviewed-pilot-career:zh-CN:visible_claims',
            ],
            array_column($first['targets'], 'identity'),
        );

        $this->assertSame(0, Artisan::call('career:build-pilot-review-package', [
            '--slugs' => self::SLUG,
            '--json' => true,
        ]));
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_CAREER_PILOT_REVIEW_PACKAGE', $payload['status']);
        $this->assertSame(0, $payload['database_writes']);
        $this->assertFalse($payload['review_evidence_bound']);
        $this->assertDatabaseCount('review_attestations', 0);

        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown')
            ->assertJsonPath('trust_manifest.last_reviewed_at', null);
        $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null);
    }

    public function test_exact_approved_all_evidence_projects_only_public_review_fields(): void
    {
        $package = app(CareerPilotReviewEvidenceBridge::class)->buildPackage([self::SLUG]);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );

        $detail = $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'approved')
            ->assertJsonPath('trust_manifest.reviewer', null);
        $index = $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonPath('items.0.trust_summary.review_state', 'approved')
            ->assertJsonPath('items.0.trust_summary.reviewer', null);

        foreach ([$detail->getContent(), $index->getContent()] as $publicJson) {
            $this->assertStringNotContainsString('attested_by_admin_user_id', $publicJson);
            $this->assertStringNotContainsString('target_set_sha256', $publicJson);
            $this->assertStringNotContainsString('package_sha256', $publicJson);
            $this->assertStringNotContainsString('evidence_sha256', $publicJson);
        }
    }

    public function test_rejected_exception_and_content_drift_fail_closed(): void
    {
        foreach (['rejected', 'approved_with_exceptions'] as $decision) {
            $package = app(CareerPilotReviewEvidenceBridge::class)->buildPackage([self::SLUG]);
            app(CareerSeoReviewAttestationService::class)->createAndBindReview(
                surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
                scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
                scopeIdentity: $package['scope_identity'],
                decision: $decision,
                authoritativeTargets: $package['targets'],
                actorAdminUserId: 1,
                packageSha256: $package['package_sha256'],
                exceptions: $decision === 'approved_with_exceptions' ? [[
                    'target_identity' => CareerPilotReviewEvidenceBridge::SURFACE_ID.':'.$package['targets'][0]['identity'],
                    'reason' => 'Visible copy requires correction.',
                ]] : [],
            );
        }

        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown')
            ->assertJsonPath('trust_manifest.last_reviewed_at', null);

        $package = app(CareerPilotReviewEvidenceBridge::class)->buildPackage([self::SLUG]);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );

        $this->publishBilingualDetails('changed visible English content');

        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown')
            ->assertJsonPath('trust_manifest.last_reviewed_at', null);
    }

    private function publishBilingualDetails(string $englishContent = 'current visible English content'): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobDetailReadModel(self::SLUG, 'en', $this->detailPayload('en', $englishContent));
        $cache->publishJobDetailReadModel(self::SLUG, 'zh-CN', $this->detailPayload('zh-CN', '当前可见中文内容'));
    }

    /** @return array<string,mixed> */
    private function detailPayload(string $locale, string $content): array
    {
        $prefix = $locale === 'en' ? '/en' : '/zh';

        return [
            'bundle_kind' => 'career_job_detail',
            'identity' => ['canonical_slug' => self::SLUG],
            'locale_policy' => ['locale' => $locale],
            'titles' => ['canonical_en' => 'Reviewed Pilot Career', 'canonical_zh' => '审核试点职业'],
            'truth_layer' => ['summary' => 'Source-bounded public fact.'],
            'content_sections' => [['key' => 'overview', 'body_md' => $content]],
            'content_body_md' => $content,
            'trust_manifest' => [
                'reviewer_status' => 'human_reviewed',
                'review_state' => 'approved',
                'last_reviewed_at' => '2026-07-01T00:00:00Z',
                'reviewer' => null,
            ],
            'warnings' => [],
            'claim_permissions' => ['allow_strong_claim' => false],
            'seo_contract' => [
                'canonical_path' => $prefix.'/career/jobs/'.self::SLUG,
                'canonical_target' => $prefix.'/career/jobs/'.self::SLUG,
                'robots_policy' => 'index,follow',
                'index_eligible' => true,
            ],
            'structured_data' => ['occupation' => ['@type' => 'Occupation']],
        ];
    }

    /** @return array<string,mixed> */
    private function indexPayload(): array
    {
        return [
            'bundle_kind' => 'career_job_index',
            'items' => [[
                'identity' => ['canonical_slug' => self::SLUG],
                'trust_summary' => [
                    'reviewer_status' => 'human_reviewed',
                    'review_state' => 'approved',
                    'last_reviewed_at' => '2026-07-01T00:00:00Z',
                    'reviewer' => null,
                ],
            ]],
        ];
    }
}
