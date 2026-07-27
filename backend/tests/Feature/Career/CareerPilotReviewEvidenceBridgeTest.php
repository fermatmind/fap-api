<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\Review\CareerPilotReviewEvidenceBridge;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
            ->assertJsonPath('trust_manifest.last_reviewed_at', null)
            ->assertJsonPath('search_entry_tier', 'ineligible')
            ->assertJsonPath('search_entry_authority.review_state', 'unknown')
            ->assertJsonPath('search_entry_authority.search_entry_eligible', false);
        $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null)
            ->assertJsonPath('items.0.search_entry_tier', 'ineligible')
            ->assertJsonPath('items.0.search_entry_authority.review_state', 'unknown');
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
            ->assertJsonPath('trust_manifest.reviewer', null)
            ->assertJsonPath('search_entry_tier', 'ineligible')
            ->assertJsonPath('search_entry_authority.content_quality_tier', 'unknown')
            ->assertJsonPath(
                'search_entry_authority.reason_codes',
                ['content_quality_tier_unknown', 'publish_track_unsupported'],
            )
            ->assertJsonPath('search_entry_authority.publish_track', 'runtime_publish_projection');
        $index = $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonPath('items.0.trust_summary.review_state', 'approved')
            ->assertJsonPath('items.0.trust_summary.reviewer', null)
            ->assertJsonPath('items.0.search_entry_tier', 'ineligible')
            ->assertJsonPath('items.0.search_entry_authority.content_quality_tier', 'unknown');
        $this->getJson('/api/v0.5/career/jobs?locale=en-US')
            ->assertOk()
            ->assertJsonPath('items.0.trust_summary.review_state', 'approved');

        foreach ([$detail->getContent(), $index->getContent()] as $publicJson) {
            $this->assertStringNotContainsString('attested_by_admin_user_id', $publicJson);
            $this->assertStringNotContainsString('target_set_sha256', $publicJson);
            $this->assertStringNotContainsString('package_sha256', $publicJson);
            $this->assertStringNotContainsString('evidence_sha256', $publicJson);
            $this->assertStringNotContainsString('index_item_sha256_by_locale', $publicJson);
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
            ->assertJsonPath('trust_manifest.last_reviewed_at', null)
            ->assertJsonPath('search_entry_tier', 'ineligible')
            ->assertJsonPath(
                'search_entry_authority.reason_codes.0',
                'reviewer_evidence_not_current',
            );
    }

    public function test_newer_overlapping_rejection_cannot_be_overridden_by_older_approval(): void
    {
        $package = app(CareerPilotReviewEvidenceBridge::class)->buildPackage([self::SLUG]);
        $reviews = app(CareerSeoReviewAttestationService::class);
        $reviews->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );
        $reviews->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: 'career-search-entry-pilot:newer-overlapping-rejection',
            decision: 'rejected',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );

        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown')
            ->assertJsonPath('trust_manifest.last_reviewed_at', null);
    }

    public function test_public_trust_evidence_and_exact_index_entry_drift_fail_closed(): void
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

        $this->publishBilingualDetails(trustSource: 'changed public source evidence');
        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown');

        $current = app(CareerPilotReviewEvidenceBridge::class)->buildPackage([self::SLUG]);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $current['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $current['targets'],
            actorAdminUserId: 1,
            packageSha256: $current['package_sha256'],
        );

        app(PublicCareerAuthorityResponseCache::class)->publishJobIndexReadModelsAtomically([
            'en' => $this->indexPayload('Changed English index title'),
            'zh-CN' => $this->indexPayload(),
        ]);
        $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.search_entry_tier', 'ineligible')
            ->assertJsonPath(
                'items.0.search_entry_authority.reason_codes.0',
                'reviewer_evidence_not_current',
            );
    }

    public function test_legacy_or_cold_detail_cache_fails_without_promotion_or_dispatch(): void
    {
        Queue::fake();
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            Cache::forget($cache->jobDetailActiveVersionKey(self::SLUG, $locale));
            Cache::put($cache->jobDetailCacheKey(self::SLUG, $locale), $this->detailPayload($locale, 'legacy payload'));
        }

        try {
            app(CareerPilotReviewEvidenceBridge::class)->buildPackage([self::SLUG]);
            $this->fail('Legacy-only detail authority must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('active/LKG', $exception->getMessage());
        }

        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertNull(Cache::get($cache->jobDetailActiveVersionKey(self::SLUG, $locale)));
            $this->assertIsArray(Cache::get($cache->jobDetailCacheKey(self::SLUG, $locale)));
        }
        Queue::assertNotPushed(WarmCareerJobDetailProjection::class);
    }

    public function test_public_scoring_claim_drift_fails_closed(): void
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

        $this->publishBilingualDetails(score: 99);
        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown');
    }

    public function test_current_seo_sha_drift_makes_search_entry_ineligible(): void
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

        $this->publishBilingualDetails(robotsPolicy: 'noindex,follow');

        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=en')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown')
            ->assertJsonPath('search_entry_tier', 'ineligible')
            ->assertJsonPath('search_entry_authority.robots_indexable', false)
            ->assertJsonPath(
                'search_entry_authority.reason_codes',
                [
                    'robots_not_indexable',
                    'reviewer_evidence_not_current',
                    'content_quality_tier_unknown',
                    'publish_track_unsupported',
                ],
            );
    }

    public function test_unlisted_public_detail_field_drift_fails_closed(): void
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

        $this->publishBilingualDetails(alias: 'changed public alias');
        $this->getJson('/api/v0.5/career/jobs/'.self::SLUG.'?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('trust_manifest.review_state', 'unknown');
    }

    public function test_cached_approval_cannot_keep_index_eligible_after_opposite_locale_target_drift(): void
    {
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $package = $bridge->buildPackage([self::SLUG]);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $before = $bridge->projectJobIndexPayload($cache->jobIndexPayload('en'), 'en');
        $this->assertSame('approved', data_get($before, 'items.0.trust_summary.review_state'));

        $cache->publishJobDetailReadModel(
            self::SLUG,
            'zh-CN',
            $this->detailPayload('zh-CN', '已漂移但仍足够厚的当前可见中文内容'),
        );
        $after = $bridge->projectJobIndexPayload($cache->jobIndexPayload('en'), 'en');

        $this->assertSame('unknown', data_get($after, 'items.0.trust_summary.review_state'));
        $this->assertSame('ineligible', data_get($after, 'items.0.search_entry_tier'));
        $this->assertContains(
            'reviewer_evidence_not_current',
            data_get($after, 'items.0.search_entry_authority.reason_codes'),
        );
    }

    public function test_transient_publication_revalidation_failure_downgrades_without_breaking_detail(): void
    {
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $package = $bridge->buildPackage([self::SLUG]);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );
        $payload = app(PublicCareerAuthorityResponseCache::class)->jobDetailPayload(self::SLUG, 'en');
        $this->assertIsArray($payload);
        $approved = $bridge->projectDetailPayload(self::SLUG, $payload);
        $this->assertSame('approved', data_get($approved, 'trust_manifest.review_state'));

        Cache::partialMock()
            ->shouldReceive('get')
            ->andThrow(new \RuntimeException('transient publication cache failure'));

        $downgraded = $bridge->projectDetailPayload(self::SLUG, $payload);

        $this->assertSame('unknown', data_get($downgraded, 'trust_manifest.review_state'));
        $this->assertSame('ineligible', data_get($downgraded, 'search_entry_tier'));
        $this->assertContains(
            'reviewer_evidence_not_current',
            data_get($downgraded, 'search_entry_authority.reason_codes'),
        );
    }

    private function publishBilingualDetails(
        string $englishContent = 'current visible English content',
        string $trustSource = 'current public source evidence',
        int $score = 42,
        string $alias = 'current public alias',
        string $robotsPolicy = 'index,follow',
    ): void {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobDetailReadModel(self::SLUG, 'en', $this->detailPayload('en', $englishContent, $trustSource, $score, $alias, $robotsPolicy));
        $cache->publishJobDetailReadModel(self::SLUG, 'zh-CN', $this->detailPayload('zh-CN', '当前可见中文内容', $trustSource, $score, $alias, $robotsPolicy));
    }

    /** @return array<string,mixed> */
    private function detailPayload(
        string $locale,
        string $content,
        string $trustSource = 'current public source evidence',
        int $score = 42,
        string $alias = 'current public alias',
        string $robotsPolicy = 'index,follow',
    ): array {
        $prefix = $locale === 'en' ? '/en' : '/zh';

        return [
            'bundle_kind' => 'career_job_detail',
            'identity' => ['canonical_slug' => self::SLUG],
            'locale_policy' => ['locale' => $locale],
            'titles' => ['canonical_en' => 'Reviewed Pilot Career', 'canonical_zh' => '审核试点职业'],
            'truth_layer' => ['summary' => 'Source-bounded public fact.'],
            'alias_index' => [['alias' => $alias, 'locale' => $locale]],
            'ontology' => ['family' => 'bounded fixture'],
            'score_bundle' => ['confidence_score' => ['value' => $score]],
            'white_box_scores' => ['confidence_score' => ['value' => $score, 'formula' => 'bounded fixture']],
            'integrity_summary' => ['integrity_state' => 'source_bounded', 'confidence_cap' => $score],
            'content_sections' => [['key' => 'overview', 'body_md' => $content]],
            'content_body_md' => $content,
            'trust_manifest' => [
                'reviewer_status' => 'human_reviewed',
                'review_state' => 'approved',
                'last_reviewed_at' => '2026-07-01T00:00:00Z',
                'reviewer' => null,
                'source_trace' => [$trustSource],
            ],
            'warnings' => [],
            'claim_permissions' => ['allow_strong_claim' => false],
            'seo_contract' => [
                'canonical_path' => $prefix.'/career/jobs/'.self::SLUG,
                'canonical_target' => $prefix.'/career/jobs/'.self::SLUG,
                'robots_policy' => $robotsPolicy,
                'index_eligible' => $robotsPolicy === 'index,follow',
            ],
            'structured_data' => ['occupation' => ['@type' => 'Occupation']],
        ];
    }

    /** @return array<string,mixed> */
    private function indexPayload(string $title = 'Reviewed Pilot Career'): array
    {
        return [
            'bundle_kind' => 'career_job_index',
            'items' => [[
                'identity' => ['canonical_slug' => self::SLUG],
                'titles' => ['canonical_en' => $title],
                'trust_summary' => [
                    'reviewer_status' => 'human_reviewed',
                    'review_state' => 'approved',
                    'last_reviewed_at' => '2026-07-01T00:00:00Z',
                    'reviewer' => null,
                ],
                'seo_contract' => [
                    'robots_policy' => 'index,follow',
                    'index_eligible' => true,
                ],
            ]],
        ];
    }
}
