<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerCmsPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_career_guide_and_job_packages_are_independent_idempotent_and_rollback_only_their_target_set(): void
    {
        $guide = $this->guide('first-guide');
        $job = $this->job('first-job');
        $guidePackage = $this->package('guide', $guide);
        $jobPackage = $this->package('job', $job);
        $guideContext = $this->context($guidePackage, 'W3', 'career-guides');
        $jobContext = $this->context($jobPackage, 'W8', 'career-jobs');
        $guides = app(PromotionAdapterRegistry::class)->resolve('W3', 'career-guides');
        $jobs = app(PromotionAdapterRegistry::class)->resolve('W8', 'career-jobs');

        self::assertSame('audit_compatible', $guides->capability());
        self::assertSame('audit_compatible', $jobs->capability());
        self::assertSame(1, $guides->preflight($guideContext)['readback_count']);
        self::assertSame(1, $jobs->preflight($jobContext)['readback_count']);
        self::assertSame(1, $guides->draftImport($guideContext)['created_count']);
        self::assertSame(0, $guides->draftImport($guideContext)['created_count']);
        self::assertSame(1, $jobs->draftImport($jobContext)['created_count']);
        self::assertSame(0, $jobs->draftImport($jobContext)['created_count']);
        self::assertSame(1, CareerGuideRevision::query()->count());
        self::assertSame(1, CareerJobRevision::query()->count());
        self::assertSame('Original guide', $guide->refresh()->title);
        self::assertSame('Original job', $job->refresh()->title);

        $guidePublished = $guides->publish($guideContext);
        $jobPublished = $jobs->publish($jobContext);
        self::assertSame(1, $guidePublished['published_count']);
        self::assertSame(1, $jobPublished['published_count']);
        self::assertSame(0, $guides->publish($guideContext)['written_count']);
        self::assertSame(0, $jobs->publish($jobContext)['written_count']);
        self::assertSame(1, $guides->liveQa($guideContext)['published_count']);
        self::assertSame(1, $jobs->liveQa($jobContext)['published_count']);
        self::assertSame('Promoted guide', $guide->refresh()->title);
        self::assertSame('Promoted job', $job->refresh()->title);
        self::assertNull($guide->body_html);
        self::assertNull($job->body_html);
        self::assertFalse((bool) $guide->is_indexable);
        self::assertFalse((bool) $job->is_indexable);

        $guides->rollback($guideContext, (string) $guidePublished['rollback_reference']);
        self::assertSame('Original guide', $guide->refresh()->title);
        self::assertSame('<p>Original guide body.</p>', $guide->body_html);
        self::assertSame('Promoted job', $job->refresh()->title);
        $jobs->rollback($jobContext, (string) $jobPublished['rollback_reference']);
        self::assertSame('Original job', $job->refresh()->title);
        self::assertSame('<p>Original job body.</p>', $job->body_html);
    }

    public function test_career_packages_fail_closed_for_cross_lane_private_claim_and_cjk_input(): void
    {
        $guide = $this->guide('bounded-guide');
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'career-guides');
        foreach ([
            [['snapshot' => ['title' => '中文']], 'career_promotion_cjk_leakage'],
            [['snapshot' => ['body_md' => 'This guaranteed an outcome.']], 'career_promotion_claim_boundary_invalid'],
            [['snapshot' => ['body_md' => 'Read /checkout?token=private.']], 'career_promotion_private_payload_invalid'],
        ] as [$override, $error]) {
            try {
                $adapter->preflight($this->context($this->package('guide', $guide, $override), 'W3', 'career-guides'));
                self::fail('Invalid package must fail closed.');
            } catch (DomainException $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }
        try {
            $adapter->preflight($this->context($this->package('guide', $guide), 'W8', 'career-jobs'));
            self::fail('Cross-lane context must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('career_promotion_manifest_contract_invalid', $exception->getMessage());
        }
    }

    private function guide(string $slug): CareerGuide
    {
        return CareerGuide::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'guide_code' => $slug, 'slug' => $slug, 'locale' => 'en', 'title' => 'Original guide',
            'excerpt' => 'Original guide excerpt', 'category_slug' => 'career', 'body_md' => 'Original guide body.', 'body_html' => '<p>Original guide body.</p>',
            'related_industry_slugs_json' => ['technology'], 'status' => 'published', 'is_public' => true,
            'is_indexable' => false, 'published_at' => now(), 'schema_version' => 'v1', 'sort_order' => 10,
        ]);
    }

    private function job(string $slug): CareerJob
    {
        return CareerJob::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'job_code' => $slug, 'slug' => $slug, 'locale' => 'en', 'title' => 'Original job',
            'subtitle' => 'Original subtitle', 'excerpt' => 'Original job excerpt', 'hero_kicker' => 'Explore',
            'hero_quote' => 'Original quote.', 'industry_slug' => 'technology', 'industry_label' => 'Technology',
            'body_md' => 'Original job body.', 'body_html' => '<p>Original job body.</p>', 'salary_json' => ['notes' => 'Varies by context.'], 'outlook_json' => ['summary' => 'Contextual.'],
            'skills_json' => ['core' => ['Communication'], 'supporting' => []], 'work_contents_json' => ['items' => []],
            'growth_path_json' => ['entry' => 'Entry'], 'fit_personality_codes_json' => [], 'mbti_primary_codes_json' => [],
            'mbti_secondary_codes_json' => [], 'riasec_profile_json' => [], 'big5_targets_json' => [], 'iq_eq_notes_json' => [],
            'market_demand_json' => [], 'status' => 'published', 'is_public' => true, 'is_indexable' => false,
            'published_at' => now(), 'schema_version' => 'v1', 'sort_order' => 10,
        ]);
    }

    /** @param array<string,mixed> $override */
    private function package(string $kind, CareerGuide|CareerJob $model, array $override = []): string
    {
        $directory = base_path('content_assets/en-content-parity/t5-career-test-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        $this->directories[] = $directory;
        $snapshot = $kind === 'guide'
            ? ['title' => 'Promoted guide', 'excerpt' => 'Promoted guide excerpt', 'category_slug' => 'career', 'body_md' => 'Promoted guide body.', 'body_html' => null, 'related_industry_slugs_json' => ['technology'], 'schema_version' => 'v1', 'sort_order' => 20]
            : ['title' => 'Promoted job', 'subtitle' => 'Promoted subtitle', 'excerpt' => 'Promoted job excerpt', 'hero_kicker' => 'Explore', 'hero_quote' => 'Promoted quote.', 'industry_slug' => 'technology', 'industry_label' => 'Technology', 'body_md' => 'Promoted job body.', 'body_html' => null, 'salary_json' => ['notes' => 'Varies by context.'], 'outlook_json' => ['summary' => 'Contextual.'], 'skills_json' => ['core' => ['Communication'], 'supporting' => []], 'work_contents_json' => ['items' => []], 'growth_path_json' => ['entry' => 'Entry'], 'fit_personality_codes_json' => [], 'mbti_primary_codes_json' => [], 'mbti_secondary_codes_json' => [], 'riasec_profile_json' => [], 'big5_targets_json' => [], 'iq_eq_notes_json' => [], 'market_demand_json' => [], 'schema_version' => 'v1', 'sort_order' => 20];
        $row = array_replace_recursive(['identity' => ['org_id' => 0, 'slug' => $model->slug, 'locale' => 'en'], 'snapshot' => $snapshot], $override);
        $bytes = json_encode(['assets' => [$row]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($directory.'/assets.json', $bytes);
        $lane = $kind === 'guide' ? 'W3' : 'W8';
        $subscope = $kind === 'guide' ? 'career-guides' : 'career-jobs';
        $manifest = ['schema_version' => 'fermatmind.career_cms_promotion.v2', 'lane' => $lane, 'subscope' => $subscope, 'locale' => 'en', 'permissions' => ['cms_draft_import' => false, 'public_publish' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search' => false, 'deploy' => false], 'expected_row_count' => 1, 'payloads' => [['path' => 'assets.json', 'sha256' => hash('sha256', $bytes)]]];
        $packageSha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($manifest))."\nassets.json\n".hash('sha256', $bytes)."\n");
        $manifest['package_sha256'] = $packageSha;
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        return $directory;
    }

    private function context(string $directory, string $lane, string $subscope): PromotionContext
    {
        $manifest = json_decode((string) File::get($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return new PromotionContext($directory, $manifest['package_sha256'], $lane, $subscope, str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '1', 1, str_repeat('d', 64), 1, hash('sha256', $directory));
    }
}
