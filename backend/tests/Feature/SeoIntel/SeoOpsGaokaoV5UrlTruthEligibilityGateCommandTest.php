<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\SeoOpsGaokaoV5UrlTruthEligibilityGateCommand;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SeoOpsGaokaoV5UrlTruthEligibilityGateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL_PATH = '/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist';

    private const CONFIRMATION = 'I explicitly approve SEO-OPS-GAOKAO-V5-URL-TRUTH-ELIGIBILITY-GATE-01 to enable sitemap/llms eligibility for article 55 canonical /zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist; no content change, no publish, no URL Truth write, no schema/hreflang, no Search Channel, no IndexNow/Baidu/GSC, no deploy/revalidation.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app
            ->make(ConsoleKernel::class)
            ->registerCommand($this->app->make(SeoOpsGaokaoV5UrlTruthEligibilityGateCommand::class));
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('seo-ops:gaokao-v5-url-truth-eligibility-gate', Artisan::all());
    }

    public function test_dry_run_plans_article55_eligibility_without_writes(): void
    {
        $this->createPublishedArticle55();
        Storage::fake('local');
        $artifactDir = storage_path('framework/testing/gaokao-url-truth-eligibility-dryrun');

        $exitCode = Artisan::call('seo-ops:gaokao-v5-url-truth-eligibility-gate', [
            '--article' => '55',
            '--expected-canonical-path' => self::CANONICAL_PATH,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame('planned', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['execute']);
        $this->assertSame(['articles.sitemap_eligible', 'articles.llms_eligible'], $payload['planned_write_scope']);
        $this->assertFalse(data_get($payload, 'plan.before.sitemap_eligible'));
        $this->assertFalse(data_get($payload, 'plan.before.llms_eligible'));
        $this->assertTrue(data_get($payload, 'plan.after.sitemap_eligible'));
        $this->assertTrue(data_get($payload, 'plan.after.llms_eligible'));
        $this->assertFalse(data_get($payload, 'negative_guarantees.url_truth_write'));
        $this->assertFalse(data_get($payload, 'negative_guarantees.search_channel_enqueue'));
        $this->assertIsString(data_get($payload, 'artifact.path'));
        $this->assertFileExists((string) data_get($payload, 'artifact.path'));

        $fresh = Article::query()->withoutGlobalScopes()->findOrFail(55);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'seo_ops_gaokao_v5_url_truth_eligibility_gate')->count());
    }

    public function test_execute_only_enables_sitemap_and_llms_and_makes_url_truth_candidate(): void
    {
        $article = $this->createPublishedArticle55();
        $contentHash = hash('sha256', (string) $article->content_md);
        $schemaHash = hash('sha256', (string) json_encode($article->seoMeta?->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $publishedRevisionId = (int) $article->published_revision_id;

        $exitCode = Artisan::call('seo-ops:gaokao-v5-url-truth-eligibility-gate', [
            '--article' => '55',
            '--expected-canonical-path' => self::CANONICAL_PATH,
            '--execute' => true,
            '--confirm-eligibility' => self::CONFIRMATION,
            '--artifact-dir' => storage_path('framework/testing/gaokao-url-truth-eligibility-execute'),
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame('success', $payload['status']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['execute']);

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail(55);
        $this->assertSame('published', (string) $fresh->status);
        $this->assertTrue((bool) $fresh->is_public);
        $this->assertTrue((bool) $fresh->is_indexable);
        $this->assertTrue((bool) $fresh->sitemap_eligible);
        $this->assertTrue((bool) $fresh->llms_eligible);
        $this->assertSame($publishedRevisionId, (int) $fresh->published_revision_id);
        $this->assertSame($contentHash, hash('sha256', (string) $fresh->content_md));
        $this->assertSame($schemaHash, hash('sha256', (string) json_encode($fresh->seoMeta?->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)));

        $records = (new BackendAuthorityUrlTruthSource)->candidates();
        $articleRecords = array_values(array_filter(
            $records,
            static fn ($record): bool => $record->pageEntityType === 'article'
                && $record->entityIdOrSlug === '55'
                && str_ends_with($record->canonicalUrl, self::CANONICAL_PATH),
        ));
        $this->assertCount(1, $articleRecords);

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'seo_ops_gaokao_v5_url_truth_eligibility_gate')->first();
        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame('article', (string) $audit->target_type);
        $this->assertSame('55', (string) $audit->target_id);
        $this->assertSame(['articles.sitemap_eligible', 'articles.llms_eligible'], data_get($audit->meta_json, 'updates_scope'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'no_search'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'no_url_truth_write'));
    }

    public function test_execute_blocks_wrong_article_or_canonical_without_write(): void
    {
        $this->createPublishedArticle55();

        $exitCode = Artisan::call('seo-ops:gaokao-v5-url-truth-eligibility-gate', [
            '--article' => '56',
            '--expected-canonical-path' => '/zh/articles/wrong',
            '--execute' => true,
            '--confirm-eligibility' => self::CONFIRMATION,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertIssueCode($payload, 'unsupported_article_id');
        $this->assertIssueCode($payload, 'unsupported_canonical_path');

        $fresh = Article::query()->withoutGlobalScopes()->findOrFail(55);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
    }

    private function createPublishedArticle55(): Article
    {
        /** @var Article $article */
        $article = Model::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'id' => 55,
            'org_id' => 0,
            'category_id' => null,
            'author_name' => 'Fermat Institute',
            'reading_minutes' => 15,
            'slug' => 'gaokao-major-choice-parent-conflict-riasec-course-checklist',
            'locale' => 'zh-CN',
            'translation_group_id' => 'tg_article_gaokao_parent_conflict_riasec_course_checklist_2026v1',
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '选专业父母不同意怎么办？高考志愿里用位次、兴趣和课程清单沟通',
            'excerpt' => '高考志愿选专业时父母不同意，先别争谁对。',
            'content_md' => '# 选专业父母不同意怎么办？'."\n\n正文。",
            'content_html' => '<h1>选专业父母不同意怎么办？</h1>',
            'cover_image_url' => 'https://ops.fermatmind.com/storage/media-library/variants/article/hero.jpg',
            'cover_image_alt' => '高考志愿沟通清单',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => Carbon::create(2026, 6, 26, 2, 43, 10, 'UTC'),
        ]));

        /** @var ArticleTranslationRevision $revision */
        $revision = Model::unguarded(fn (): ArticleTranslationRevision => ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'id' => 75,
            'org_id' => 0,
            'article_id' => 55,
            'source_article_id' => 55,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => '选专业父母不同意怎么办？高考志愿三张清单 | FermatMind',
            'seo_description' => (string) $article->excerpt,
            'published_at' => $article->published_at,
        ]));

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => 55,
            'locale' => 'zh-CN',
            'seo_title' => '选专业父母不同意怎么办？高考志愿三张清单 | FermatMind',
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => self::CANONICAL_PATH,
            'og_title' => '选专业父母不同意怎么办？高考志愿三张清单 | FermatMind',
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://ops.fermatmind.com/storage/media-library/variants/article/og.jpg',
            'robots' => 'index,follow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'schema_hold' => true,
                    'hreflang_hold' => true,
                    'search_submission_allowed' => false,
                ],
            ],
            'is_indexable' => true,
        ]);

        return $article->fresh(['publishedRevision', 'seoMeta']) ?? $article;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertIssueCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            (array) ($payload['issues'] ?? []),
        ));
    }
}
