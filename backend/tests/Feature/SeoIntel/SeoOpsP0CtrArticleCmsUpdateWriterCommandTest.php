<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\SeoOpsP0CtrArticleCmsUpdateWriterCommand;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoOpsP0CtrArticleCmsUpdateWriterCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(SeoOpsP0CtrArticleCmsUpdateWriterCommand::class)
        );
    }

    #[Test]
    public function dry_run_plans_three_article_updates_without_writes(): void
    {
        $this->seedAuthorityRows();
        $dryRun = $this->writeDryRunEvidence();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:p0-ctr-article-cms-update-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => $dryRun['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['dry_run'] ?? false));
        $this->assertFalse((bool) ($summary['execute'] ?? true));
        $this->assertSame(3, data_get($summary, 'planned_write_count.article_cms_updates'));
        $this->assertSame(0, data_get($summary, 'planned_write_count.landing_surfaces'));
        $this->assertStringContainsString('I explicitly approve Gate B P0 CTR repair article CMS update', (string) ($summary['required_confirmation_phrase'] ?? ''));
        $this->assertFalse((bool) data_get($summary, 'side_effects.database_write', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.search_channel_enqueue', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'evidence.path'));
        $this->assertSame('seo-ops-p0-ctr-article-cms-update-writer.v1', $artifact['schema_version'] ?? null);
        $this->assertCount(3, $artifact['article_update_plans'] ?? []);
    }

    #[Test]
    public function execute_updates_live_article_seo_and_cta_metadata_without_publish_or_search_side_effects(): void
    {
        $articles = $this->seedAuthorityRows();
        $dryRun = $this->writeDryRunEvidence();
        $plan = $this->writerPlan($dryRun);
        $phrase = (string) $plan['required_confirmation_phrase'];
        $countsBefore = $this->rowCounts();
        $protectedBefore = $this->protectedSnapshots($articles);

        $exitCode = Artisan::call('seo-ops:p0-ctr-article-cms-update-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => $dryRun['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--execute' => true,
            '--confirm-write' => $phrase,
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertFalse((bool) ($summary['dry_run'] ?? true));
        $this->assertTrue((bool) data_get($summary, 'side_effects.database_write'));
        $this->assertTrue((bool) data_get($summary, 'side_effects.cms_article_update'));
        $this->assertFalse((bool) data_get($summary, 'side_effects.landing_surface_write', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.indexnow_submit', true));
        $this->assertSame($countsBefore, $this->rowCounts());
        $this->assertSame($protectedBefore, $this->protectedSnapshots($articles));

        $first = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->findOrFail((int) $articles[0]->id);

        $this->assertSame('What Is RIASEC? Holland Code Career Test Guide', (string) $first->publishedRevision?->seo_title);
        $this->assertSame('Learn what RIASEC means, how the Holland Code career interest model works, and when to use a free career interest test for exploration.', (string) $first->publishedRevision?->seo_description);
        $this->assertSame('What Is RIASEC? Holland Code Career Test Guide', (string) $first->seoMeta?->seo_title);
        $this->assertSame('Take the free RIASEC test', (string) data_get($first->seoMeta?->schema_json, 'editorial_package_v1.cta_slots.0.label'));
        $this->assertSame('/en/tests/holland-career-interest-test-riasec', (string) data_get($first->seoMeta?->schema_json, 'editorial_package_v1.cta_slots.0.href'));
        $this->assertSame('seo_ops_p0_ctr_article_cms_update_writer', (string) data_get($first->seoMeta?->schema_json, 'editorial_package_v1.source'));
        $this->assertFalse((bool) data_get($first->seoMeta?->schema_json, 'editorial_package_v1.search_submission_allowed', true));
        $this->assertTrue((bool) data_get($first->seoMeta?->schema_json, 'editorial_package_v1.schema_hold', false));
    }

    #[Test]
    public function execute_requires_exact_confirmation_phrase(): void
    {
        $this->seedAuthorityRows();
        $dryRun = $this->writeDryRunEvidence();

        $exitCode = Artisan::call('seo-ops:p0-ctr-article-cms-update-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => $dryRun['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--execute' => true,
            '--confirm-write' => 'wrong',
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('confirm_write_phrase_mismatch', $summary['issues'] ?? []);
        $this->assertSame('Existing SEO description.', (string) ArticleSeoMeta::query()->withoutGlobalScopes()->first()?->seo_description);
    }

    #[Test]
    public function writer_blocks_wrong_dry_run_evidence_sha(): void
    {
        $this->seedAuthorityRows();
        $dryRun = $this->writeDryRunEvidence();

        $exitCode = Artisan::call('seo-ops:p0-ctr-article-cms-update-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => str_repeat('0', 64),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('dry_run_evidence_sha_mismatch', $summary['issues'] ?? []);
    }

    /**
     * @return list<Article>
     */
    private function seedAuthorityRows(): array
    {
        $this->createLandingSurface('test_detail_mbti_personality_test_16_personality_types', 'MBTI免费测试');
        $this->createLandingSurface('test_detail_holland_career_interest_test_riasec', '霍兰德职业兴趣测试');

        return [
            $this->createArticle('what-is-riasec-holland-code-career-interest-test', 'en', 'What Is RIASEC?', '/en/articles/what-is-riasec-holland-code-career-interest-test'),
            $this->createArticle('riasec-holland-career-interest-test-explained', 'zh-CN', '霍兰德职业兴趣测试是什么？', '/zh/articles/riasec-holland-career-interest-test-explained'),
            $this->createArticle('mbti-basics', 'zh-CN', 'MBTI 是什么？', '/zh/articles/mbti-basics'),
        ];
    }

    private function createLandingSurface(string $surfaceKey, string $title): void
    {
        LandingSurface::query()->create([
            'org_id' => 0,
            'surface_key' => $surfaceKey,
            'locale' => 'zh-CN',
            'title' => $title,
            'description' => 'Existing description.',
            'schema_version' => 'v1',
            'payload_json' => [
                'seo_title' => $title.' | FermatMind',
                'seo_description' => 'Existing SEO description.',
                'h1_or_hero_title' => $title,
                'primary_cta_label' => '开始测试',
            ],
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    private function createArticle(string $slug, string $locale, string $title, string $canonicalPath): Article
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => (string) Str::uuid(),
            'source_locale' => $locale,
            'title' => $title,
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing markdown with a public link to [/articles](/'.($locale === 'en' ? 'en' : 'zh').'/articles).',
            'content_html' => '<p>Existing HTML.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subDay(),
        ]);
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => $title,
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing markdown.',
            'seo_title' => $title.' | FermatMind',
            'seo_description' => 'Existing SEO description.',
            'published_at' => now()->subHour(),
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => $title.' | FermatMind',
            'seo_description' => 'Existing SEO description.',
            'canonical_url' => 'https://fermatmind.com'.$canonicalPath,
            'robots' => 'index,follow',
            'schema_json' => [['@type' => 'Article']],
            'is_indexable' => true,
        ]);

        return $article->fresh(['publishedRevision', 'seoMeta']) ?? $article;
    }

    /**
     * @return array{path:string,sha256:string}
     */
    private function writeDryRunEvidence(): array
    {
        $source = $this->writeJson($this->sourceArtifact());
        $exitCode = Artisan::call('seo-ops:p0-ctr-repair-dry-run', [
            '--artifact' => $source['path'],
            '--confirm-artifact-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $path = (string) data_get($summary, 'evidence.path');

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * @param  array{path:string,sha256:string}  $dryRun
     * @return array<string,mixed>
     */
    private function writerPlan(array $dryRun): array
    {
        $exitCode = Artisan::call('seo-ops:p0-ctr-article-cms-update-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => $dryRun['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceArtifact(): array
    {
        return [
            'schema' => 'fermatmind-seo-ops-ctr-repair-p0-dry-run-preview.v1',
            'generated_at' => now()->utc()->toIso8601String(),
            'scope' => [
                'read_only_package_prep' => true,
                'cms_write_allowed' => false,
                'publish_allowed' => false,
                'search_submit_allowed' => false,
                'url_truth_write_allowed' => false,
                'sitemap_llms_mutation_allowed' => false,
                'schema_enable_allowed' => false,
                'hreflang_enable_allowed' => false,
                'deploy_allowed' => false,
            ],
            'authority_groups' => [
                'test_landing_surfaces' => [
                    $this->landingCandidate('test_detail_mbti_personality_test_16_personality_types', 'mbti-personality-test-16-personality-types'),
                    $this->landingCandidate('test_detail_holland_career_interest_test_riasec', 'holland-career-interest-test-riasec'),
                ],
                'article_cms_updates' => [
                    $this->articleCandidate('what-is-riasec-holland-code-career-interest-test', 'en', 'What Is RIASEC? Holland Code Career Test Guide', 'Take the free RIASEC test'),
                    $this->articleCandidate('riasec-holland-career-interest-test-explained', 'zh', 'RIASEC 是什么？霍兰德职业兴趣测试解释', '免费做霍兰德职业兴趣测试'),
                    $this->articleCandidate('mbti-basics', 'zh', 'MBTI 是什么？16 型人格测试入门指南', '开始免费 MBTI 测试'),
                ],
            ],
            'negative_guarantees' => [
                'db_write' => false,
                'cms_write' => false,
                'cms_publish' => false,
                'search_channel_enqueue' => false,
                'indexnow_submit' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function landingCandidate(string $surfaceKey, string $slug): array
    {
        return [
            'payload_key' => 'test_landing_surface::'.$surfaceKey.'::zh',
            'authority_source' => 'backend landing_surfaces payload',
            'surface_key' => $surfaceKey,
            'locale' => 'zh',
            'safe_path' => '/zh/tests/'.$slug,
            'slug' => $slug,
            'proposed_payload_json_updates' => [
                'seo_title' => '新的测试页标题 | FermatMind',
                'seo_description' => '新的测试页描述。',
                'h1_or_hero_title' => '新的 H1',
                'primary_cta_label' => '开始免费测试',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function articleCandidate(string $slug, string $locale, string $seoTitle, string $ctaLabel): array
    {
        $segment = $locale === 'en' ? 'en' : 'zh';

        return [
            'payload_key' => 'article_cms_update::'.$slug.'::'.$locale,
            'authority_source' => 'backend CMS Article detail',
            'locale' => $locale,
            'safe_path' => '/'.$segment.'/articles/'.$slug,
            'slug' => $slug,
            'proposed_cms_field_updates' => [
                'seo_title' => $seoTitle,
                'seo_description' => $locale === 'en'
                    ? 'Learn what RIASEC means, how the Holland Code career interest model works, and when to use a free career interest test for exploration.'
                    : '解释 RIASEC 六种职业兴趣类型、霍兰德测试能说明什么，以及如何把结果用于专业和职业探索。',
                'first_screen_summary_direction' => $locale === 'en'
                    ? 'Answer what RIASEC means first, then route testing intent to the free RIASEC test page.'
                    : '首屏聚焦公开解释，并把测试入口作为清晰下一步。',
                'primary_cta_label' => $ctaLabel,
                'primary_cta_path' => '/'.$segment.'/tests/'.($slug === 'mbti-basics' ? 'mbti-personality-test-16-personality-types' : 'holland-career-interest-test-riasec'),
                'internal_link_targets' => [
                    '/'.$segment.'/tests/'.($slug === 'mbti-basics' ? 'mbti-personality-test-16-personality-types' : 'holland-career-interest-test-riasec'),
                ],
                'claim_boundary_note' => $locale === 'en'
                    ? 'Use as career exploration input, not as a promise of job fit or career outcome.'
                    : '用于探索参考，不承诺录取、岗位适配或职业结果。',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{path:string,sha256:string}
     */
    private function writeJson(array $payload): array
    {
        $path = $this->artifactDir().'/source.json';
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function rowCounts(): array
    {
        return [
            'landing_surfaces' => DB::table('landing_surfaces')->count(),
            'articles' => DB::table('articles')->count(),
            'article_seo_meta' => DB::table('article_seo_meta')->count(),
            'article_translation_revisions' => DB::table('article_translation_revisions')->count(),
        ];
    }

    /**
     * @param  list<Article>  $articles
     * @return array<int,array<string,mixed>>
     */
    private function protectedSnapshots(array $articles): array
    {
        return array_map(static function (Article $article): array {
            $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);

            return [
                'id' => (int) $fresh->id,
                'slug' => (string) $fresh->slug,
                'locale' => (string) $fresh->locale,
                'translation_group_id' => (string) $fresh->translation_group_id,
                'status' => (string) $fresh->status,
                'is_public' => (bool) $fresh->is_public,
                'is_indexable' => (bool) $fresh->is_indexable,
                'sitemap_eligible' => (bool) $fresh->sitemap_eligible,
                'llms_eligible' => (bool) $fresh->llms_eligible,
                'working_revision_id' => (int) $fresh->working_revision_id,
                'published_revision_id' => (int) $fresh->published_revision_id,
            ];
        }, $articles);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-ops-p0-ctr-article-writer-'.Str::random(8));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
