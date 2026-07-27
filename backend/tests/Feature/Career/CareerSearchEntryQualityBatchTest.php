<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\Review\CareerPilotReviewEvidenceBridge;
use App\Services\Career\Review\CareerSearchEntryQualityBatchManifestReader;
use App\Services\Career\Review\CareerSearchEntryQualityBatchPlanner;
use App\Services\Career\Review\CareerSearchEntryQualityEvaluator;
use App\Services\Career\Review\CareerSearchEntryTierResolver;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerSearchEntryQualityBatchTest extends TestCase
{
    use RefreshDatabase;

    private CareerSearchEntryQualityBatchManifestReader $manifestReader;

    private PublicCareerAuthorityResponseCache $responseCache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: true),
        );
        $this->manifestReader = app(CareerSearchEntryQualityBatchManifestReader::class);
        $this->responseCache = app(PublicCareerAuthorityResponseCache::class);
        $this->publishCompleteBatch();
    }

    public function test_manifest_locks_exact_fifty_non_held_candidates(): void
    {
        $manifest = $this->manifestReader->read();

        $this->assertSame(50, $manifest['expected_candidate_count']);
        $this->assertSame(50, $manifest['max_candidate_count']);
        $this->assertCount(50, $manifest['candidates']);
        $this->assertSame(range(1, 50), array_column($manifest['candidates'], 'pool_rank'));
        $this->assertSame(
            CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE,
            $manifest['content_quality_tier'],
        );
        $this->assertSame(
            [],
            array_values(array_intersect(
                array_column($manifest['candidates'], 'canonical_slug'),
                CareerDirectoryAuthorityService::excludedSlugs(),
            )),
        );
    }

    public function test_manifest_rejects_more_than_fifty_candidates(): void
    {
        $manifest = $this->manifestReader->read();
        $manifest['expected_candidate_count'] = 51;
        $manifest['candidates'][] = [
            'pool_rank' => 51,
            'canonical_slug' => 'unbounded-candidate',
            'expected_publish_track' => 'candidate',
        ];
        $path = storage_path('framework/testing/career-search-entry-quality-batch-overflow.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('count boundary');
            $this->manifestReader->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_dry_run_is_deterministic_bilingual_bounded_and_zero_write(): void
    {
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $before = $this->cacheEvidenceSha();
        $planner = app(CareerSearchEntryQualityBatchPlanner::class);

        $first = $planner->build();
        $second = $planner->build();
        $verified = $planner->verify($first);

        $this->assertSame($first, $second);
        $this->assertSame($first, $verified);
        $this->assertSame(50, $first['candidate_count']);
        $this->assertSame(100, $first['bilingual_url_count']);
        $this->assertSame(300, $first['target_count']);
        $this->assertCount(50, $first['slugs']);
        $this->assertCount(100, $first['canonical_urls']);
        $this->assertSame(64, strlen($first['package_sha256']));
        $this->assertSame(64, strlen($first['target_set_sha256']));
        $this->assertSame(64, strlen($first['quality_package_sha256']));
        $this->assertSame(range(1, 50), array_column($first['candidates'], 'selection_rank'));
        $this->assertSame(
            ['stable', 'stable', 'stable', 'stable'],
            array_slice(array_column($first['candidates'], 'publish_track'), 0, 4),
        );
        $this->assertNotContains('stable', array_slice(array_column($first['candidates'], 'publish_track'), 4));

        foreach ($first['candidates'] as $candidate) {
            $this->assertSame([], $candidate['blockers']);
            $this->assertSame(
                CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE,
                $candidate['content_quality_tier'],
            );
            $this->assertSame('ineligible', $candidate['search_entry_tier']);
            $this->assertContains($candidate['target_search_entry_tier'], ['stable', 'approved_candidate']);
            $this->assertSame('awaiting_exact_approved_all_binding', $candidate['review_state']);
            $this->assertCount(6, $candidate['review_targets']);
            $this->assertCount(6, $candidate['review_target_sha256_by_identity']);
            foreach (['en', 'zh-CN'] as $locale) {
                $evidence = $candidate['locales'][$locale];
                $this->assertGreaterThanOrEqual(500, $evidence['visible_character_count']);
                $this->assertNotEmpty($evidence['source_references']);
                $this->assertNotEmpty($evidence['claim_boundary']);
                $this->assertGreaterThan(0, $evidence['faq_count']);
                $this->assertNotEmpty($evidence['internal_links']);
                $this->assertMatchesRegularExpression(
                    '/^[a-f0-9]{64}$/',
                    $candidate['current_content_sha256_by_locale'][$locale],
                );
                $this->assertMatchesRegularExpression(
                    '/^[a-f0-9]{64}$/',
                    $candidate['current_seo_sha256_by_locale'][$locale],
                );
                $this->assertSame(
                    $candidate['review_target_sha256_by_identity'][
                        "career-job:{$candidate['canonical_slug']}:{$locale}:content"
                    ],
                    $candidate['current_content_sha256_by_locale'][$locale],
                );
                $this->assertSame(
                    $candidate['review_target_sha256_by_identity'][
                        "career-job:{$candidate['canonical_slug']}:{$locale}:seo"
                    ],
                    $candidate['current_seo_sha256_by_locale'][$locale],
                );
            }
        }

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->cacheEvidenceSha());
        $this->assertSame(array_fill_keys(array_keys($first['negative_guarantees']), 0), $first['negative_guarantees']);
        Queue::assertNothingPushed();
    }

    public function test_command_build_and_exact_verification_are_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('career:build-search-entry-quality-batch', ['--json' => true]));
        $first = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_CAREER_SEARCH_ENTRY_QUALITY_BATCH', $first['status']);

        $path = storage_path('framework/testing/career-search-entry-quality-batch.json');
        file_put_contents($path, json_encode($first, JSON_THROW_ON_ERROR));
        try {
            $this->assertSame(0, Artisan::call('career:build-search-entry-quality-batch', [
                '--expected-package' => $path,
                '--json' => true,
            ]));
            $verified = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($verified['expected_package_verified']);
            $this->assertSame($first['package_sha256'], $verified['package_sha256']);
            $this->assertSame($first['target_set_sha256'], $verified['target_set_sha256']);
            $this->assertSame($first['quality_package_sha256'], $verified['quality_package_sha256']);
        } finally {
            @unlink($path);
        }
    }

    public function test_content_seo_or_review_target_drift_rejects_exact_package(): void
    {
        $planner = app(CareerSearchEntryQualityBatchPlanner::class);
        $package = $planner->build();
        $slug = $package['slugs'][0];
        $this->responseCache->publishJobDetailReadModel(
            $slug,
            'en',
            $this->detailPayload($slug, 'en', ['drift_marker' => 'changed']),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('drift detected');
        $planner->verify($package);
    }

    public function test_exact_review_binding_projects_stable_and_approved_candidate_without_conflating_quality(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $rows = $this->manifestReader->read()['candidates'];
        $slugs = [$rows[0]['canonical_slug'], $rows[4]['canonical_slug']];
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $package = $bridge->buildPackage($slugs);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );

        $stable = $bridge->projectDetailPayload(
            $slugs[0],
            $this->responseCache->jobDetailPayload($slugs[0], 'en'),
        );
        $candidate = $bridge->projectDetailPayload(
            $slugs[1],
            $this->responseCache->jobDetailPayload($slugs[1], 'en'),
        );

        $this->assertSame('stable', $stable['search_entry_tier']);
        $this->assertSame('approved_candidate', $candidate['search_entry_tier']);
        foreach ([$stable, $candidate] as $payload) {
            $this->assertTrue($payload['search_entry_authority']['search_entry_eligible']);
            $this->assertSame('approved', $payload['search_entry_authority']['review_state']);
            $this->assertSame(
                CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE,
                $payload['search_entry_authority']['content_quality_tier'],
            );
        }
    }

    public function test_quality_gaps_are_independently_rejected_without_backfill(): void
    {
        $rows = $this->manifestReader->read()['candidates'];
        $cases = [
            [$rows[0]['canonical_slug'], 'thin', 'visible_content_too_thin'],
            [$rows[1]['canonical_slug'], 'sources', 'source_references_missing'],
            [$rows[2]['canonical_slug'], 'claims', 'claim_boundary_missing'],
            [$rows[3]['canonical_slug'], 'faq', 'faq_visible_content_mismatch'],
            [$rows[4]['canonical_slug'], 'links', 'internal_links_missing_or_cross_locale'],
            [$rows[5]['canonical_slug'], 'robots', 'robots_not_indexable'],
        ];
        foreach ($cases as [$slug, $gap, $expectedBlocker]) {
            $this->responseCache->publishJobDetailReadModel(
                $slug,
                'en',
                $this->detailPayload($slug, 'en', $this->qualityGapOverrides($gap)),
            );
        }

        $evaluator = app(CareerSearchEntryQualityEvaluator::class);
        foreach ($cases as [$slug, , $expectedBlocker]) {
            $this->assertContains('en:'.$expectedBlocker, $evaluator->evaluate($slug)['blockers'], $slug);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insufficient qualified candidates');
        app(CareerSearchEntryQualityBatchPlanner::class)->build();
    }

    public function test_missing_bilingual_authority_fails_closed(): void
    {
        Cache::flush();
        $evaluation = app(CareerSearchEntryQualityEvaluator::class)
            ->evaluate($this->manifestReader->read()['candidates'][0]['canonical_slug']);

        $this->assertContains('en:bilingual_detail_not_ready', $evaluation['blockers']);
        $this->assertContains('zh-CN:bilingual_detail_not_ready', $evaluation['blockers']);
        $this->assertSame('ineligible', $evaluation['content_quality_tier']);
    }

    private function publishCompleteBatch(): void
    {
        $indexItems = ['en' => [], 'zh-CN' => []];
        foreach ($this->manifestReader->read()['candidates'] as $candidate) {
            $slug = $candidate['canonical_slug'];
            foreach (['en', 'zh-CN'] as $locale) {
                $this->responseCache->publishJobDetailReadModel($slug, $locale, $this->detailPayload($slug, $locale));
                $indexItems[$locale][] = [
                    'identity' => ['canonical_slug' => $slug],
                    'titles' => ['canonical_en' => str($slug)->replace('-', ' ')->title()->toString()],
                    'trust_summary' => ['review_state' => 'unknown', 'last_reviewed_at' => null],
                    'seo_contract' => ['robots_policy' => 'index,follow', 'index_eligible' => true],
                ];
            }
        }
        $this->responseCache->publishJobIndexReadModelsAtomically([
            'en' => ['bundle_kind' => 'career_job_index', 'items' => $indexItems['en']],
            'zh-CN' => ['bundle_kind' => 'career_job_index', 'items' => $indexItems['zh-CN']],
        ]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function detailPayload(string $slug, string $locale, array $overrides = []): array
    {
        $prefix = $locale === 'en' ? '/en' : '/zh';
        $title = str($slug)->replace('-', ' ')->title()->toString();
        $faqQuestion = $locale === 'en' ? 'Is this a guaranteed outcome?' : '这是否保证职业结果？';
        $faqAnswer = $locale === 'en'
            ? 'No. It is bounded evidence for exploration.'
            : '不是。这是用于职业探索的边界证据。';
        $body = str_repeat(
            $locale === 'en'
                ? 'Visible source-bounded career evidence supports careful exploration and comparison. '
                : '可见且有来源边界的职业证据用于审慎探索、比较与复盘。',
            20,
        );
        $payload = [
            'bundle_kind' => 'career_job_detail',
            'identity' => ['canonical_slug' => $slug],
            'locale_policy' => ['locale' => $locale],
            'titles' => ['canonical_en' => $title, 'canonical_zh' => $title.' 中文'],
            'truth_layer' => ['summary' => 'Source-bounded public fact.'],
            'content_sections' => [['key' => 'overview', 'body_md' => $body]],
            'content_body_md' => $body,
            'trust_manifest' => [
                'reviewer_status' => 'pending_exact_batch_review',
                'review_state' => 'unknown',
                'last_reviewed_at' => null,
            ],
            'warnings' => [],
            'claim_permissions' => [
                'allow_strong_claim' => false,
                'allow_salary_comparison' => false,
                'allow_ai_strategy' => false,
                'reason_codes' => ['bounded_quality_review_required'],
            ],
            'seo_contract' => [
                'canonical_url' => $prefix.'/career/jobs/'.$slug,
                'canonical_path' => $prefix.'/career/jobs/'.$slug,
                'canonical_target' => $prefix.'/career/jobs/'.$slug,
                'robots_policy' => 'index,follow',
                'index_eligible' => true,
            ],
            'structured_data' => ['occupation' => ['@type' => 'Occupation']],
            'display_surface_v1' => [
                'page' => [
                    'locale' => $locale,
                    'content' => [
                        'hero' => ['title' => $title, 'quick_answer' => $body],
                        'primary_cta' => ['href' => $prefix.'/tests/holland-career-interest-test-riasec'],
                        'faq_block' => ['items' => [[
                            'question' => $faqQuestion,
                            'answer' => $faqAnswer,
                        ]]],
                        'boundary_notice' => ['body' => 'No hiring, salary, or outcome guarantee.'],
                        'final_cta' => ['href' => $prefix.'/career'],
                    ],
                ],
                'sources' => [[
                    'key' => 'bounded_fixture_source',
                    'label' => 'Bounded public career source',
                    'usage' => 'Career evidence validation.',
                ]],
                'claim_permissions' => [
                    'allow_strong_claim' => false,
                    'allow_salary_comparison' => false,
                    'allow_ai_strategy' => false,
                    'blocked_claims' => ['guaranteed_outcomes'],
                ],
                'structured_data_from_visible_content' => [
                    'faq_page' => [
                        $locale === 'en' ? 'en' : 'zh' => [
                            '@context' => 'https://schema.org',
                            '@type' => 'FAQPage',
                            'mainEntity' => [[
                                '@type' => 'Question',
                                'name' => $faqQuestion,
                                'acceptedAnswer' => [
                                    '@type' => 'Answer',
                                    'text' => $faqAnswer,
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    /** @return array<string,mixed> */
    private function qualityGapOverrides(string $gap): array
    {
        return match ($gap) {
            'thin' => ['display_surface_v1' => ['page' => ['content' => [
                'hero' => ['title' => 'thin', 'quick_answer' => 'thin'],
            ]]]],
            'sources' => ['display_surface_v1' => ['sources' => [null]]],
            'claims' => [
                'display_surface_v1' => ['claim_permissions' => [
                    'allow_strong_claim' => 'unknown',
                    'allow_salary_comparison' => 'unknown',
                    'allow_ai_strategy' => 'unknown',
                ]],
                'claim_permissions' => [
                    'allow_strong_claim' => 'unknown',
                    'allow_salary_comparison' => 'unknown',
                    'allow_ai_strategy' => 'unknown',
                ],
            ],
            'faq' => ['display_surface_v1' => ['structured_data_from_visible_content' => [
                'faq_page' => ['en' => ['mainEntity' => [[
                    'acceptedAnswer' => ['text' => 'Drifted hidden FAQ answer.'],
                ]]]],
            ]]],
            'links' => ['display_surface_v1' => ['page' => ['content' => [
                'primary_cta' => ['href' => null],
                'final_cta' => ['href' => null],
            ]]]],
            'robots' => ['seo_contract' => [
                'robots_policy' => 'noindex,follow',
                'index_eligible' => false,
            ]],
            default => [],
        };
    }

    private function cacheEvidenceSha(): string
    {
        $rows = $this->manifestReader->read()['candidates'];
        $slugs = [$rows[0]['canonical_slug'], $rows[49]['canonical_slug']];
        $evidence = [
            'index_en' => $this->responseCache->jobIndexPayload('en'),
            'index_zh' => $this->responseCache->jobIndexPayload('zh-CN'),
        ];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $evidence[$slug.':'.$locale] = $this->responseCache->jobDetailCacheReadiness($slug, $locale);
            }
        }

        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }
}
