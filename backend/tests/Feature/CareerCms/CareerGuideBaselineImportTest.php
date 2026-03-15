<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerGuideBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.frontend_url', 'https://www.example.test');
    }

    public function test_dry_run_does_not_write_database(): void
    {
        $this->seedRelationTargets();

        $this->artisan('career-guides:import-local-baseline', [
            '--dry-run' => true,
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('guides_found=4')
            ->expectsOutputToContain('will_create=4')
            ->expectsOutputToContain('revisions_to_create=4')
            ->assertExitCode(0);

        $this->assertSame(0, $this->guideQuery()->count());
        $this->assertSame(0, CareerGuideSeoMeta::query()->count());
        $this->assertSame(0, CareerGuideRevision::query()->count());
        $this->assertSame(0, DB::table('career_guide_job_map')->count());
        $this->assertSame(0, DB::table('career_guide_article_map')->count());
        $this->assertSame(0, DB::table('career_guide_personality_map')->count());
    }

    public function test_create_missing_imports_guides_relations_seo_meta_and_revision(): void
    {
        $this->seedRelationTargets();

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])
            ->expectsOutputToContain('guides_found=2')
            ->expectsOutputToContain('will_create=2')
            ->assertExitCode(0);

        $this->assertSame(2, $this->guideQuery()->count());
        $this->assertSame(2, CareerGuideSeoMeta::query()->count());
        $this->assertSame(2, CareerGuideRevision::query()->count());
        $this->assertSame(3, DB::table('career_guide_job_map')->count());
        $this->assertSame(2, DB::table('career_guide_article_map')->count());
        $this->assertSame(2, DB::table('career_guide_personality_map')->count());

        $guide = $this->guideQuery()
            ->where('guide_code', 'from-mbti-to-job-fit')
            ->where('locale', 'en')
            ->firstOrFail();
        $guide->load('seoMeta', 'relatedJobs', 'relatedArticles', 'relatedPersonalityProfiles');

        $this->assertSame('published', $guide->status);
        $this->assertSame('2026-03-05', $guide->published_at?->toDateString());
        $this->assertSame(['business', 'technology'], $guide->related_industry_slugs_json);
        $this->assertSame(
            ['product-manager', 'teacher'],
            $guide->relatedJobs->pluck('job_code')->all(),
        );
        $this->assertSame(
            ['mbti-basics', 'mbti-growth-guide'],
            $guide->relatedArticles->pluck('slug')->all(),
        );
        $this->assertSame(
            ['INTJ', 'ENFP'],
            $guide->relatedPersonalityProfiles->pluck('type_code')->all(),
        );
        $this->assertSame('From MBTI to Job Fit', $guide->seoMeta?->seo_title);
        $this->assertSame(
            'https://www.example.test/en/career/guides/from-mbti-to-job-fit',
            $guide->seoMeta?->canonical_url,
        );

        $quarterlyGuide = $this->guideQuery()
            ->where('guide_code', 'quarterly-career-review')
            ->where('locale', 'en')
            ->firstOrFail();

        $this->assertSame(
            'Quarterly Career Review Guide',
            $quarterlyGuide->seoMeta?->seo_title,
        );
        $this->assertSame(
            'baseline import',
            CareerGuideRevision::query()
                ->where('career_guide_id', (int) $guide->id)
                ->orderBy('revision_no')
                ->firstOrFail()
                ->note,
        );
    }

    public function test_upsert_updates_existing_guides_syncs_relations_and_creates_revision_when_changed(): void
    {
        $this->seedRelationTargets();
        $legacyJob = $this->seedJob([
            'job_code' => 'legacy-job',
            'slug' => 'legacy-job',
            'title' => 'Legacy Job',
        ]);
        $legacyArticle = $this->seedArticle([
            'slug' => 'legacy-article',
            'title' => 'Legacy Article',
        ]);
        $legacyProfile = $this->seedProfile([
            'type_code' => 'ISTJ',
            'slug' => 'istj',
            'title' => 'ISTJ Personality Type',
        ]);

        $guide = $this->guideQuery()->create([
            'org_id' => 0,
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'en',
            'title' => 'Legacy guide title',
            'excerpt' => 'Legacy excerpt',
            'category_slug' => 'legacy-category',
            'body_md' => 'Legacy body',
            'body_html' => '<p>Legacy body</p>',
            'related_industry_slugs_json' => ['legacy'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'sort_order' => 99,
            'published_at' => now()->subDay(),
            'schema_version' => 'v1',
        ]);
        $guide->relatedJobs()->attach([
            $legacyJob->id => ['sort_order' => 10],
            $this->findJob('teacher', 'en')->id => ['sort_order' => 20],
        ]);
        $guide->relatedArticles()->attach([
            $legacyArticle->id => ['sort_order' => 10],
        ]);
        $guide->relatedPersonalityProfiles()->attach([
            $legacyProfile->id => ['sort_order' => 10],
        ]);
        $guide->seoMeta()->create([
            'seo_title' => 'Legacy SEO title',
            'seo_description' => 'Legacy SEO description',
            'robots' => 'noindex,follow',
        ]);
        CareerGuideRevision::query()->create([
            'career_guide_id' => (int) $guide->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Legacy guide title'],
            'note' => 'seed',
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['from-mbti-to-job-fit'],
            '--upsert' => true,
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])
            ->expectsOutputToContain('guides_found=1')
            ->expectsOutputToContain('will_update=1')
            ->expectsOutputToContain('revisions_to_create=1')
            ->assertExitCode(0);

        $guide->refresh();
        $guide->load('seoMeta', 'relatedJobs', 'relatedArticles', 'relatedPersonalityProfiles');

        $this->assertSame('From MBTI to Job Fit', $guide->title);
        $this->assertTrue((bool) $guide->is_indexable);
        $this->assertSame(['business', 'technology'], $guide->related_industry_slugs_json);
        $this->assertSame(
            ['product-manager', 'teacher'],
            $guide->relatedJobs->pluck('job_code')->all(),
        );
        $this->assertSame(
            ['mbti-basics', 'mbti-growth-guide'],
            $guide->relatedArticles->pluck('slug')->all(),
        );
        $this->assertSame(
            ['INTJ', 'ENFP'],
            $guide->relatedPersonalityProfiles->pluck('type_code')->all(),
        );
        $this->assertSame('From MBTI to Job Fit', $guide->seoMeta?->seo_title);
        $this->assertSame(
            2,
            CareerGuideRevision::query()->where('career_guide_id', (int) $guide->id)->max('revision_no'),
        );
        $this->assertSame(
            'baseline upsert',
            CareerGuideRevision::query()
                ->where('career_guide_id', (int) $guide->id)
                ->orderByDesc('revision_no')
                ->firstOrFail()
                ->note,
        );
    }

    public function test_no_op_upsert_skips_revision_creation(): void
    {
        $this->seedRelationTargets();

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['quarterly-career-review'],
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])->assertExitCode(0);

        $guide = $this->guideQuery()
            ->where('guide_code', 'quarterly-career-review')
            ->where('locale', 'en')
            ->firstOrFail();

        $this->assertSame(
            1,
            CareerGuideRevision::query()->where('career_guide_id', (int) $guide->id)->count(),
        );

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['quarterly-career-review'],
            '--upsert' => true,
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])
            ->expectsOutputToContain('will_skip=1')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            CareerGuideRevision::query()->where('career_guide_id', (int) $guide->id)->count(),
        );
    }

    public function test_locale_filter_limits_import_scope(): void
    {
        $this->seedRelationTargets();

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])
            ->expectsOutputToContain('guides_found=2')
            ->expectsOutputToContain('will_create=2')
            ->assertExitCode(0);

        $this->assertSame(2, $this->guideQuery()->count());
        $this->assertSame(
            ['zh-CN'],
            $this->guideQuery()->distinct()->orderBy('locale')->pluck('locale')->all(),
        );
        $this->assertSame(
            ['draft'],
            $this->guideQuery()->distinct()->orderBy('status')->pluck('status')->all(),
        );
        $this->assertSame(0, $this->guideQuery()->whereNotNull('published_at')->count());
    }

    public function test_guide_filter_limits_import_scope(): void
    {
        $this->seedRelationTargets();

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['quarterly-career-review'],
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])
            ->expectsOutputToContain('guides_found=1')
            ->expectsOutputToContain('will_create=1')
            ->assertExitCode(0);

        $this->assertSame(1, $this->guideQuery()->count());
        $this->assertSame(
            'quarterly-career-review',
            $this->guideQuery()->firstOrFail()->guide_code,
        );
    }

    public function test_invalid_baseline_fails_for_shape_duplicates_and_unsupported_locale(): void
    {
        $invalidCases = [
            'top_level_shape' => [
                'meta' => [
                    'schema_version' => 'v1',
                    'locale' => 'en',
                    'source' => 'test fixture',
                    'generated_at' => '2026-03-15T00:00:00Z',
                ],
                'guides' => 'bad-shape',
            ],
            'relation_shape' => [
                'meta' => [
                    'schema_version' => 'v1',
                    'locale' => 'en',
                    'source' => 'test fixture',
                    'generated_at' => '2026-03-15T00:00:00Z',
                ],
                'guides' => [
                    $this->validGuideRow([
                        'related_jobs' => ['product-manager'],
                    ]),
                ],
            ],
            'duplicate_guide_code' => [
                'meta' => [
                    'schema_version' => 'v1',
                    'locale' => 'en',
                    'source' => 'test fixture',
                    'generated_at' => '2026-03-15T00:00:00Z',
                ],
                'guides' => [
                    $this->validGuideRow(),
                    $this->validGuideRow([
                        'slug' => 'sample-guide-2',
                    ]),
                ],
            ],
            'unsupported_locale' => [
                'meta' => [
                    'schema_version' => 'v1',
                    'locale' => 'fr',
                    'source' => 'test fixture',
                    'generated_at' => '2026-03-15T00:00:00Z',
                ],
                'guides' => [
                    $this->validGuideRow([
                        'locale' => 'fr',
                    ]),
                ],
            ],
        ];

        foreach ($invalidCases as $suffix => $payload) {
            $sourceDir = $this->writeFixtureDirectory('career_guide_baseline_invalid_'.$suffix, [
                'career_guides.en.json' => $payload,
            ]);

            try {
                $this->artisan('career-guides:import-local-baseline', [
                    '--source-dir' => $sourceDir,
                ])->assertExitCode(1);

                $this->assertSame(0, $this->guideQuery()->count());
            } finally {
                File::deleteDirectory(base_path($sourceDir));
            }
        }
    }

    public function test_unresolved_relations_fail_fast_for_job_article_and_personality(): void
    {
        $this->seedArticle(['slug' => 'mbti-basics', 'locale' => 'en']);
        $this->seedProfile(['type_code' => 'INTJ', 'slug' => 'intj', 'locale' => 'en']);

        $jobMissingDir = $this->writeFixtureDirectory('career_guide_baseline_missing_job', [
            'career_guides.en.json' => $this->validPayload('en', [
                $this->validGuideRow([
                    'guide_code' => 'missing-job-guide',
                    'slug' => 'missing-job-guide',
                    'related_jobs' => [['job_code' => 'missing-job']],
                    'related_articles' => [['slug' => 'mbti-basics']],
                    'related_personality_profiles' => [['type_code' => 'INTJ']],
                ]),
            ]),
        ]);

        try {
            $this->artisan('career-guides:import-local-baseline', [
                '--source-dir' => $jobMissingDir,
            ])
                ->expectsOutputToContain('Unable to resolve related_jobs')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory(base_path($jobMissingDir));
        }

        $this->assertSame(0, $this->guideQuery()->count());

        $this->seedJob(['job_code' => 'product-manager', 'slug' => 'product-manager', 'locale' => 'en']);
        $articleMissingDir = $this->writeFixtureDirectory('career_guide_baseline_missing_article', [
            'career_guides.en.json' => $this->validPayload('en', [
                $this->validGuideRow([
                    'guide_code' => 'missing-article-guide',
                    'slug' => 'missing-article-guide',
                    'related_jobs' => [['job_code' => 'product-manager']],
                    'related_articles' => [['slug' => 'missing-article']],
                    'related_personality_profiles' => [['type_code' => 'INTJ']],
                ]),
            ]),
        ]);

        try {
            $this->artisan('career-guides:import-local-baseline', [
                '--source-dir' => $articleMissingDir,
            ])
                ->expectsOutputToContain('Unable to resolve related_articles')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory(base_path($articleMissingDir));
        }

        $this->assertSame(0, $this->guideQuery()->count());

        $this->seedArticle(['slug' => 'mbti-growth-guide', 'locale' => 'en']);
        $profileMissingDir = $this->writeFixtureDirectory('career_guide_baseline_missing_profile', [
            'career_guides.en.json' => $this->validPayload('en', [
                $this->validGuideRow([
                    'guide_code' => 'missing-profile-guide',
                    'slug' => 'missing-profile-guide',
                    'related_jobs' => [['job_code' => 'product-manager']],
                    'related_articles' => [['slug' => 'mbti-growth-guide']],
                    'related_personality_profiles' => [['type_code' => 'ENFP']],
                ]),
            ]),
        ]);

        try {
            $this->artisan('career-guides:import-local-baseline', [
                '--source-dir' => $profileMissingDir,
            ])
                ->expectsOutputToContain('Unable to resolve related_personality_profiles')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory(base_path($profileMissingDir));
        }

        $this->assertSame(0, $this->guideQuery()->count());
    }

    public function test_seo_generation_fallback_runs_when_baseline_seo_meta_is_empty(): void
    {
        $this->seedRelationTargets(['en']);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['from-mbti-to-job-fit'],
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])->assertExitCode(0);

        $guide = $this->guideQuery()
            ->where('guide_code', 'from-mbti-to-job-fit')
            ->where('locale', 'en')
            ->firstOrFail();

        $seoMeta = CareerGuideSeoMeta::query()
            ->where('career_guide_id', (int) $guide->id)
            ->firstOrFail();

        $this->assertSame('From MBTI to Job Fit', $seoMeta->seo_title);
        $this->assertSame(
            'Translate MBTI signals into practical career choices.',
            $seoMeta->seo_description,
        );
        $this->assertSame(
            'https://www.example.test/en/career/guides/from-mbti-to-job-fit',
            $seoMeta->canonical_url,
        );
        $this->assertSame('index,follow', $seoMeta->robots);
    }

    public function test_revision_snapshot_shape_contains_guide_relations_and_seo_meta(): void
    {
        $this->seedRelationTargets(['en']);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['from-mbti-to-job-fit'],
            '--source-dir' => 'tests/Fixtures/career_guide_baseline',
        ])->assertExitCode(0);

        $guide = $this->guideQuery()
            ->where('guide_code', 'from-mbti-to-job-fit')
            ->where('locale', 'en')
            ->firstOrFail();
        $revision = CareerGuideRevision::query()
            ->where('career_guide_id', (int) $guide->id)
            ->orderBy('revision_no')
            ->firstOrFail();
        $snapshot = $revision->snapshot_json;

        $this->assertSame('from-mbti-to-job-fit', data_get($snapshot, 'guide.guide_code'));
        $this->assertSame('From MBTI to Job Fit', data_get($snapshot, 'guide.title'));
        $this->assertSame('product-manager', data_get($snapshot, 'related_jobs.0.job_code'));
        $this->assertSame('mbti-basics', data_get($snapshot, 'related_articles.0.slug'));
        $this->assertSame('INTJ', data_get($snapshot, 'related_personality_profiles.0.type_code'));
        $this->assertSame('From MBTI to Job Fit', data_get($snapshot, 'seo_meta.seo_title'));
    }

    private function guideQuery()
    {
        return CareerGuide::query()->withoutGlobalScopes();
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function seedRelationTargets(array $locales = ['en', 'zh-CN']): void
    {
        foreach ($locales as $locale) {
            $this->seedJob([
                'job_code' => 'product-manager',
                'slug' => 'product-manager',
                'locale' => $locale,
                'title' => $locale === 'zh-CN' ? '产品经理' : 'Product Manager',
            ]);
            $this->seedJob([
                'job_code' => 'teacher',
                'slug' => 'teacher',
                'locale' => $locale,
                'title' => $locale === 'zh-CN' ? '教师' : 'Teacher',
            ]);

            $this->seedArticle([
                'slug' => 'mbti-basics',
                'locale' => $locale,
                'title' => $locale === 'zh-CN' ? 'MBTI 基础' : 'MBTI Basics',
            ]);
            $this->seedArticle([
                'slug' => 'mbti-growth-guide',
                'locale' => $locale,
                'title' => $locale === 'zh-CN' ? 'MBTI 成长指南' : 'MBTI Growth Guide',
            ]);

            $this->seedProfile([
                'type_code' => 'INTJ',
                'slug' => 'intj',
                'locale' => $locale,
                'title' => $locale === 'zh-CN' ? 'INTJ 人格类型' : 'INTJ Personality Type',
            ]);
            $this->seedProfile([
                'type_code' => 'ENFP',
                'slug' => 'enfp',
                'locale' => $locale,
                'title' => $locale === 'zh-CN' ? 'ENFP 人格类型' : 'ENFP Personality Type',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedJob(array $overrides = []): CareerJob
    {
        /** @var CareerJob */
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'career-job',
            'slug' => 'career-job',
            'locale' => 'en',
            'title' => 'Career Job',
            'excerpt' => 'Career job excerpt.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedArticle(array $overrides = []): Article
    {
        /** @var Article */
        return Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'career-article',
            'locale' => 'en',
            'title' => 'Career Article',
            'excerpt' => 'Career article excerpt.',
            'content_md' => '# Career article',
            'content_html' => '<h1>Career article</h1>',
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'excerpt' => 'Strategic and independent.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
            'schema_version' => 'v1',
        ], $overrides));
    }

    private function findJob(string $jobCode, string $locale): CareerJob
    {
        /** @var CareerJob */
        return CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('job_code', $jobCode)
            ->where('locale', $locale)
            ->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $guides
     * @return array<string, mixed>
     */
    private function validPayload(string $locale, array $guides): array
    {
        return [
            'meta' => [
                'schema_version' => 'v1',
                'locale' => $locale,
                'source' => 'test fixture',
                'generated_at' => '2026-03-15T00:00:00Z',
            ],
            'guides' => $guides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validGuideRow(array $overrides = []): array
    {
        return array_merge([
            'guide_code' => 'sample-guide',
            'slug' => 'sample-guide',
            'locale' => 'en',
            'title' => 'Sample Guide',
            'excerpt' => 'Sample excerpt.',
            'category_slug' => 'career-planning',
            'body_md' => '# Sample Guide',
            'body_html' => null,
            'related_industry_slugs_json' => ['business'],
            'related_jobs' => [
                ['job_code' => 'product-manager'],
            ],
            'related_articles' => [
                ['slug' => 'mbti-basics'],
            ],
            'related_personality_profiles' => [
                ['type_code' => 'INTJ'],
            ],
            'seo_meta' => [
                'seo_title' => null,
                'seo_description' => null,
                'canonical_url' => null,
                'og_title' => null,
                'og_description' => null,
                'og_image_url' => null,
                'twitter_title' => null,
                'twitter_description' => null,
                'twitter_image_url' => null,
                'robots' => null,
                'jsonld_overrides_json' => null,
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => '2026-03-05',
            'scheduled_at' => null,
            'sort_order' => 0,
        ], $overrides);
    }

    /**
     * @param  array<string, array<string, mixed>>  $files
     */
    private function writeFixtureDirectory(string $directoryName, array $files): string
    {
        $relativeDir = 'tests/Fixtures/'.$directoryName;
        $absoluteDir = base_path($relativeDir);

        File::deleteDirectory($absoluteDir);
        File::ensureDirectoryExists($absoluteDir);

        foreach ($files as $fileName => $payload) {
            File::put(
                $absoluteDir.'/'.$fileName,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            );
        }

        return $relativeDir;
    }
}
