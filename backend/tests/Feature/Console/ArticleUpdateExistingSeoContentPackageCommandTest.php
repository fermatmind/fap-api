<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ArticleUpdateExistingSeoContentPackageCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ARTICLE_ID = 40;

    private const TRANSLATION_GROUP_ID = 'tg_article_riasec_holland_career_interest_test_2026v1';

    private const SLUG = 'riasec-holland-career-interest-test-explained';

    private const CANONICAL = '/zh/articles/riasec-holland-career-interest-test-explained';

    public function test_guarded_plan_is_read_only_and_guarded_execute_forks_only_working_revision(): void
    {
        $article = $this->createExistingPublishedArticle40();
        $published = $article->publishedRevision->getAttributes();
        $baseline = $this->baselineOptions($article);
        $package = $this->writeExistingUpdatePackage();
        $this->assertSame(0, Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            ...$baseline, '--dry-run' => true, '--json' => true,
        ])));
        $this->assertTrue($this->jsonOutput()['ok']);
        $this->assertSame(1, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());

        $this->assertSame(0, Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            ...$baseline, '--execute' => true, '--json' => true,
        ])));
        $article->refresh()->load(['publishedRevision', 'workingRevision']);
        $this->assertSame($published, $article->publishedRevision->getAttributes());
        $this->assertNotSame((int) $article->published_revision_id, (int) $article->working_revision_id);
        $this->assertSame(1, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    #[DataProvider('rejectedBaselines')]
    public function test_guarded_execute_rejects_wrong_or_incomplete_baseline_without_writes(string $option, mixed $value, string $code): void
    {
        $article = $this->createExistingPublishedArticle40();
        $before = $article->publishedRevision->getAttributes();
        $baseline = $this->baselineOptions($article);
        $baseline[$option] = $value;
        $package = $this->writeExistingUpdatePackage();

        $this->assertSame(1, Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            ...$baseline, '--execute' => true, '--json' => true,
        ])));
        $this->assertErrorCode($this->jsonOutput(), $code);
        $article->refresh()->load('workingRevision');
        $this->assertSame($before, $article->workingRevision->getAttributes());
        $this->assertSame(1, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public static function rejectedBaselines(): array
    {
        return [
            'wrong organization' => ['--expected-org-id', '1', 'baseline_expected_org_id_mismatch'],
            'stale working revision' => ['--expected-working-revision-id', '9999', 'baseline_expected_working_revision_id_mismatch'],
            'stale published revision' => ['--expected-published-revision-id', '9999', 'baseline_expected_published_revision_id_mismatch'],
            'wrong working bytes' => ['--expected-working-body-sha256', str_repeat('0', 64), 'baseline_expected_working_body_sha256_mismatch'],
            'wrong published bytes' => ['--expected-published-body-sha256', str_repeat('0', 64), 'baseline_expected_published_body_sha256_mismatch'],
            'partial guard' => ['--expected-published-body-sha256', null, 'baseline_guard_incomplete'],
            'invalid organization' => ['--expected-org-id', '-1', 'baseline_guard_invalid'],
            'zero revision' => ['--expected-working-revision-id', '0', 'baseline_guard_invalid'],
            'invalid raw hash' => ['--expected-working-body-sha256', 'not-a-hash', 'baseline_guard_invalid'],
        ];
    }

    public function test_guard_uses_exact_stored_body_bytes_including_crlf_and_surrounding_whitespace(): void
    {
        $article = $this->createExistingPublishedArticle40();
        $raw = "\r\n## Existing RIASEC article\r\n\r\n旧正文。  \r\n";
        $article->workingRevision->forceFill(['content_md' => $raw])->save();
        $article->refresh()->load(['workingRevision', 'publishedRevision']);
        $baseline = $this->baselineOptions($article);
        $package = $this->writeExistingUpdatePackage();
        $normalizedHash = hash('sha256', trim(str_replace("\r\n", "\n", $raw)));

        $this->assertSame(1, Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            ...$baseline, '--expected-working-body-sha256' => $normalizedHash, '--execute' => true, '--json' => true,
        ])));
        $this->assertErrorCode($this->jsonOutput(), 'baseline_expected_working_body_sha256_mismatch');
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());

        $this->assertSame(0, Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            ...$baseline, '--execute' => true, '--json' => true,
        ])));
        $article->refresh()->load('publishedRevision');
        $this->assertSame($raw, $article->publishedRevision->getRawOriginal('content_md'));
    }

    public function test_guarded_execute_rechecks_body_inside_transaction_before_any_revision_write(): void
    {
        $article = $this->createExistingPublishedArticle40();
        $before = $article->publishedRevision->getAttributes();
        $baseline = $this->baselineOptions($article);
        $package = $this->writeExistingUpdatePackage();
        $reads = 0;
        $event = 'eloquent.retrieved: '.Article::class;
        Event::listen($event, function (Article $read) use (&$reads, $article): void {
            if ((int) $read->id === (int) $article->id && ++$reads === 2) {
                DB::table('article_translation_revisions')->where('id', $article->working_revision_id)
                    ->update(['content_md' => "Concurrent editor's current body\n"]);
            }
        });
        try {
            $this->assertSame(1, Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
                ...$baseline, '--execute' => true, '--json' => true,
            ])));
            $payload = $this->jsonOutput();
            $this->assertErrorCode($payload, 'runtime_error');
            $this->assertSame('baseline_expected_working_body_sha256_mismatch', $payload['errors'][0]['message']);
            $this->assertGreaterThanOrEqual(2, $reads);
        } finally {
            Event::forget($event);
        }
        $article->refresh()->load('workingRevision');
        $this->assertSame($before, $article->workingRevision->getAttributes());
        $this->assertSame(1, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    private function baselineOptions(Article $article): array
    {
        return [
            '--expected-org-id' => (string) $article->org_id,
            '--expected-working-revision-id' => (string) $article->working_revision_id,
            '--expected-published-revision-id' => (string) $article->published_revision_id,
            '--expected-working-body-sha256' => hash('sha256', (string) $article->workingRevision->getRawOriginal('content_md')),
            '--expected-published-body-sha256' => hash('sha256', (string) $article->publishedRevision->getRawOriginal('content_md')),
        ];
    }

    public function test_dry_run_accepts_exact_article_40_without_database_writes(): void
    {
        $article = $this->createExistingPublishedArticle40();
        $package = $this->writeExistingUpdatePackage();

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_update_existing_working_revision', $payload['action']);
        $this->assertSame(self::ARTICLE_ID, $payload['article_id']);
        $this->assertSame(self::SLUG, $payload['slug_lock']);
        $this->assertSame(self::CANONICAL, $payload['canonical_lock']);
        $this->assertSame('passed', $payload['active_surface_guard_scan']['status']);
        $this->assertCount(1, $payload['articles']);
        $this->assertSame(self::ARTICLE_ID, $payload['articles'][0]['article_id']);
        $this->assertSame((int) $article->working_revision_id, (int) $payload['articles'][0]['working_revision_id']);
        $this->assertSame((int) $article->published_revision_id, (int) $payload['articles'][0]['published_revision_id']);
        $this->assertTrue((bool) $payload['articles'][0]['working_revision_is_published_revision']);
        $this->assertTrue((bool) $payload['articles'][0]['will_create_isolated_working_revision']);

        $article->refresh();
        $this->assertSame('Published RIASEC article', (string) $article->workingRevision?->title);
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_updates_working_revision_and_preserves_public_route_state(): void
    {
        $article = $this->createExistingPublishedArticle40();
        $publishedRevisionId = (int) $article->published_revision_id;
        $package = $this->writeExistingUpdatePackage();

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--execute' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('updated_existing_working_revision', $payload['action']);
        $this->assertTrue((bool) data_get($payload, 'articles.0.created_isolated_working_revision'));

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
            ->findOrFail(self::ARTICLE_ID);

        $this->assertSame(self::SLUG, (string) $article->slug);
        $this->assertSame(self::TRANSLATION_GROUP_ID, (string) $article->translation_group_id);
        $this->assertSame('published', (string) $article->status);
        $this->assertTrue((bool) $article->is_public);
        $this->assertTrue((bool) $article->is_indexable);
        $this->assertTrue((bool) $article->sitemap_eligible);
        $this->assertTrue((bool) $article->llms_eligible);
        $this->assertSame($publishedRevisionId, (int) $article->published_revision_id);
        $this->assertNotSame($publishedRevisionId, (int) $article->working_revision_id);
        $this->assertSame('https://fermatmind.com'.self::CANONICAL, (string) $article->seoMeta?->canonical_url);

        $this->assertSame('Published RIASEC article', (string) $article->publishedRevision?->title);
        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $article->publishedRevision?->revision_status);
        $this->assertSame('霍兰德职业兴趣测试是什么？RIASEC 六型如何帮助职业探索', (string) $article->workingRevision?->title);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $article->workingRevision?->revision_status);
        $this->assertStringContainsString('## RIASEC 六型如何帮助职业探索', (string) $article->workingRevision?->content_md);
        $this->assertSame('霍兰德职业兴趣测试是什么？RIASEC 六型如何帮助职业探索 | FermatMind', (string) $article->workingRevision?->seo_title);

        $import = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', self::ARTICLE_ID)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(ArticleEditorialPackageImport::STATUS_IMPORTED, (string) $import->status);
        $this->assertSame('seo_content_package_existing_article_update', (string) $import->content_track);
        $this->assertSame('working_revision_human_review', (string) $import->intended_status);
        $this->assertSame('passed', (string) data_get($import->exactness_json, 'status'));
        $this->assertSame(self::CANONICAL, (string) data_get($import->exactness_json, 'canonical_url'));
        $this->assertTrue((bool) data_get($import->validation_summary_json, 'schema_hreflang_search_hold'));
        $this->assertSame(0, (int) $import->references_count);
    }

    #[DataProvider('markdownBodyFormats')]
    public function test_execute_preserves_complete_body_bytes_without_changing_public_revision(bool $frontmatter, string $lineEnding): void
    {
        $article = $this->createExistingPublishedArticle40();
        $publishedRevision = $article->publishedRevision->getAttributes();
        $seo = $article->seoMeta->getAttributes();
        $body = "\n## 读取六维分数\n\n正文末尾换行与空白属于导入内容。  \n\n";
        $page = ($frontmatter ? "---\nlocale: zh-CN\nschema_hold: true\n---\n" : '').$body;
        $package = $this->writeExistingUpdatePackage(static function (array &$files) use ($page, $lineEnding): void {
            $files['pages/zh-CN-riasec-holland-career-interest-test-explained.md'] = str_replace("\n", $lineEnding, $page);
        });

        try {
            $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
                '--execute' => true,
                '--json' => true,
            ]));
            $payload = $this->jsonOutput();
            $this->assertSame(0, $exitCode);
            $this->assertTrue($payload['ok']);
            $article->refresh()->load(['publishedRevision', 'workingRevision', 'seoMeta']);
            $this->assertNotSame((int) $article->published_revision_id, (int) $article->working_revision_id);
            $this->assertSame($body, $article->workingRevision->content_md);
            $this->assertSame(hash('sha256', $body), hash('sha256', $article->workingRevision->content_md));
            $this->assertSame($publishedRevision, $article->publishedRevision->getAttributes());
            $this->assertSame($seo, $article->seoMeta->getAttributes());
            $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $article->workingRevision->revision_status);
            $this->assertNull($article->workingRevision->approved_at);
            // Existing package metadata uses a normalized hash; raw revision bytes are checked separately.
            $this->assertSame(hash('sha256', trim($body)), data_get($payload, 'articles.0.body_hash'));
        } finally {
            foreach (['pages', 'cms', 'contracts', 'review'] as $directory) {
                foreach (glob($package.'/'.$directory.'/*') ?: [] as $path) {
                    unlink($path);
                }
                rmdir($package.'/'.$directory);
            }
            unlink($package.'/manifest.json');
            rmdir($package);
        }
    }

    public static function markdownBodyFormats(): array
    {
        return [
            'plain LF' => [false, "\n"],
            'frontmatter LF' => [true, "\n"],
            'plain CRLF' => [false, "\r\n"],
            'frontmatter CRLF' => [true, "\r\n"],
        ];
    }

    public function test_execute_preserves_zh_utf8_frontmatter_meta_draft_fallback_without_corruption(): void
    {
        $this->createExistingPublishedArticle40();
        $metaTitle = '高考志愿要不要服从调剂？先看不能接受专业 | FermatMind';
        $metaDescription = '填院校专业组前，别先争服从调剂。先把全部专业分成不能接受、待验证、可接受。';
        $package = $this->writeExistingUpdatePackage(static function (array &$files) use ($metaTitle, $metaDescription): void {
            foreach ([
                'cms/CMS_IMPORT_UPDATE_DRAFT_zh-CN_article-40_riasec-holland-career-interest-test-explained.json',
                'cms/CMS_FIELDS_UPDATE_zh-CN_article-40_riasec-holland-career-interest-test-explained.json',
            ] as $path) {
                unset(
                    $files[$path]['meta_title'],
                    $files[$path]['meta_description']
                );
            }

            $files['pages/zh-CN-riasec-holland-career-interest-test-explained.md'] = str_replace(
                "canonical_url_draft: {self::CANONICAL}\n",
                "canonical_url_draft: {self::CANONICAL}\nmeta_title_draft: {$metaTitle}\nmeta_description_draft: {$metaDescription}\n",
                $files['pages/zh-CN-riasec-holland-career-interest-test-explained.md']
            );
        });

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--execute' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with('workingRevision')
            ->findOrFail(self::ARTICLE_ID);

        $this->assertSame($metaTitle, (string) $article->workingRevision?->seo_title);
        $this->assertSame($metaDescription, (string) $article->workingRevision?->seo_description);
        $this->assertTrue(mb_check_encoding((string) $article->workingRevision?->seo_title, 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $article->workingRevision?->seo_description, 'UTF-8'));
    }

    public function test_dry_run_rejects_malformed_utf8_update_body_scalar_before_database_write(): void
    {
        $this->createExistingPublishedArticle40();
        $malformed = (string) hex2bin('c328');
        $package = $this->writeExistingUpdatePackage(static function (array &$files) use ($malformed): void {
            $files['pages/zh-CN-riasec-holland-career-interest-test-explained.md'] = str_replace(
                "## 霍兰德职业兴趣测试是什么\n",
                "## Broken {$malformed} heading\n\n## 霍兰德职业兴趣测试是什么\n",
                $files['pages/zh-CN-riasec-holland-career-interest-test-explained.md']
            );
        });

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'invalid_utf8_scalar');
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_identity_lock_article_id_mismatch(): void
    {
        $this->createExistingPublishedArticle40();
        $package = $this->writeExistingUpdatePackage(static function (array &$files): void {
            $files['contracts/ARTICLE_IDENTITY_LOCK.json']['target_article_id'] = 41;
        });

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'article_id_mismatch');
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_requires_explicit_schema_hreflang_search_and_route_holds(): void
    {
        $this->createExistingPublishedArticle40();
        $package = $this->writeExistingUpdatePackage();

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', [
            '--package' => $package,
            '--article-id' => self::ARTICLE_ID,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--locale' => 'zh-CN',
            '--expected-slug' => self::SLUG,
            '--expected-canonical' => self::CANONICAL,
            '--execute' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_hold_flag_missing');
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_and_execute_modes_are_mutually_exclusive(): void
    {
        $this->createExistingPublishedArticle40();
        $package = $this->writeExistingUpdatePackage();

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--dry-run' => true,
            '--execute' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'dry_run_execute_conflict');
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_private_route_and_forbidden_new_slug_in_active_surface(): void
    {
        $this->createExistingPublishedArticle40();
        $package = $this->writeExistingUpdatePackage(static function (array &$files): void {
            $files['manifest.json']['forbidden_new_route'] = '/zh/articles/holland-riasec-career-interest-test-explained';
            $files['cms/CMS_IMPORT_UPDATE_DRAFT_zh-CN_article-40_riasec-holland-career-interest-test-explained.json']['related_url'] = '/zh/articles/holland-riasec-career-interest-test-explained';
            $files['pages/zh-CN-riasec-holland-career-interest-test-explained.md'] .= "\n\n[private](/result/abc123)\n";
        });

        $exitCode = Artisan::call('articles:update-existing-seo-content-package', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'private_route_found_in_active_surface');
        $this->assertErrorCode($payload, 'forbidden_new_route_found_in_active_surface');
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function commandOptions(string $package, array $overrides = []): array
    {
        return array_replace([
            '--package' => $package,
            '--article-id' => self::ARTICLE_ID,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--locale' => 'zh-CN',
            '--expected-slug' => self::SLUG,
            '--expected-canonical' => self::CANONICAL,
            '--slug-lock' => true,
            '--canonical-lock' => true,
            '--schema-hold' => true,
            '--hreflang-hold' => true,
            '--search-hold' => true,
            '--no-revalidation' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
        ], $overrides);
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
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }

    private function createExistingPublishedArticle40(): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'career-development'],
            ['name' => '职业发展', 'is_active' => true]
        );
        $body = "## Existing RIASEC article\n\n旧正文。";

        $article = Article::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'id' => self::ARTICLE_ID,
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => self::SLUG,
            'locale' => 'zh-CN',
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Published RIASEC article',
            'excerpt' => 'Published excerpt',
            'content_md' => $body,
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/article-riasec/hero_1600x900.jpg',
            'cover_image_alt' => 'RIASEC cover',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subDay(),
        ]));

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Published RIASEC article',
            'excerpt' => 'Published excerpt',
            'content_md' => $body,
            'seo_title' => 'Published RIASEC SEO',
            'seo_description' => 'Published SEO description',
            'published_at' => now()->subDay(),
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => 'Published RIASEC SEO',
            'seo_description' => 'Published SEO description',
            'canonical_url' => 'https://fermatmind.com'.self::CANONICAL,
            'og_title' => 'Published RIASEC OG',
            'og_description' => 'Published OG description',
            'og_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/article-riasec/og_1200x630.jpg',
            'robots' => 'index,follow',
            'schema_json' => ['status' => 'existing_public_schema'],
            'is_indexable' => true,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']) ?? $article;
    }

    /**
     * @param  callable(array<string,mixed>&):void|null  $mutate
     */
    private function writeExistingUpdatePackage(?callable $mutate = null): string
    {
        $root = sys_get_temp_dir().'/fm-existing-article-update-package-'.Str::random(12);
        foreach (['pages', 'cms', 'contracts', 'review'] as $directory) {
            mkdir($root.'/'.$directory, 0777, true);
        }

        $files = $this->existingUpdateFiles();
        if ($mutate !== null) {
            $mutate($files);
        }

        foreach ($files as $relativePath => $contents) {
            $path = $root.'/'.$relativePath;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents(
                $path,
                is_array($contents)
                    ? json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $contents
            );
        }

        return $root;
    }

    /**
     * @return array<string,mixed>
     */
    private function existingUpdateFiles(): array
    {
        $import = [
            'operation_type' => 'update_existing_article',
            'target_article_id' => self::ARTICLE_ID,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'title' => '霍兰德职业兴趣测试是什么？RIASEC 六型如何帮助职业探索',
            'slug' => self::SLUG,
            'canonical_url' => self::CANONICAL,
            'meta_title' => '霍兰德职业兴趣测试是什么？RIASEC 六型如何帮助职业探索 | FermatMind',
            'meta_description' => '系统解释霍兰德职业兴趣测试、RIASEC 六型和职业探索边界：它能帮助你整理兴趣线索，但不是职业判决。',
            'excerpt' => '系统解释霍兰德职业兴趣测试、RIASEC 六型和职业探索边界。',
            'claim_gate_status' => 'not_reviewed',
            'schema_hold' => true,
            'hreflang_hold' => true,
            'search_submission_allowed' => false,
            'revalidation_allowed' => false,
            'sitemap_change_allowed' => false,
            'llms_change_allowed' => false,
            'body_markdown_file' => 'pages/zh-CN-riasec-holland-career-interest-test-explained.md',
        ];

        return [
            'manifest.json' => [
                'package_name' => 'riasec-pillar-update-existing-zh',
                'operation_type' => 'update_existing_article',
                'target_article_id' => self::ARTICLE_ID,
                'translation_group_id' => self::TRANSLATION_GROUP_ID,
                'create_new_article' => false,
                'create_new_slug' => false,
                'schema_enabled' => false,
                'schema_generation_allowed' => false,
                'hreflang_enabled' => false,
                'hreflang_enablement_allowed' => false,
                'search_submission_allowed' => false,
                'revalidation_allowed' => false,
                'sitemap_change_allowed' => false,
                'llms_change_allowed' => false,
                'forbidden_new_route' => '/zh/articles/holland-riasec-career-interest-test-explained',
                'pages' => [[
                    'locale' => 'zh-CN',
                    'title' => $import['title'],
                    'slug' => self::SLUG,
                    'canonical_url_draft' => self::CANONICAL,
                    'meta_title_draft' => $import['meta_title'],
                    'meta_description_draft' => $import['meta_description'],
                    'file' => 'pages/zh-CN-riasec-holland-career-interest-test-explained.md',
                ]],
            ],
            'contracts/ARTICLE_IDENTITY_LOCK.json' => [
                'target_article_id' => self::ARTICLE_ID,
                'translation_group_id' => self::TRANSLATION_GROUP_ID,
                'locale' => 'zh-CN',
                'slug' => self::SLUG,
                'canonical_url' => self::CANONICAL,
                'preserve_slug' => true,
                'create_new_article' => false,
                'create_new_slug' => false,
            ],
            'contracts/PRIVATE_URL_GUARD.json' => [
                'forbidden_paths' => ['/result', '/results', '/orders', '/order', '/share', '/pay', '/payment', '/history', '/take'],
                'forbidden_query_keys' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
            ],
            'cms/CMS_IMPORT_UPDATE_DRAFT_zh-CN_article-40_riasec-holland-career-interest-test-explained.json' => $import,
            'cms/CMS_FIELDS_UPDATE_zh-CN_article-40_riasec-holland-career-interest-test-explained.json' => $import,
            'pages/zh-CN-riasec-holland-career-interest-test-explained.md' => <<<'MD'
---
translation_group_id: {self::TRANSLATION_GROUP_ID}
locale: zh-CN
title: 霍兰德职业兴趣测试是什么？RIASEC 六型如何帮助职业探索
slug: {self::SLUG}
canonical_url_draft: {self::CANONICAL}
publish_allowed: false
schema_hold: true
hreflang_hold: true
---

## 霍兰德职业兴趣测试是什么

霍兰德职业兴趣测试是一种职业兴趣模型，用来整理一个人对活动、环境和任务类型的偏好。

## RIASEC 六型如何帮助职业探索

RIASEC 六型可以帮助你把兴趣线索转成可讨论的问题，但它不是职业判决，也不预测职业成功。

## 下一步

[开始霍兰德职业兴趣测试](/zh/tests/holland-career-interest-test-riasec)
MD,
            'review/claim_gate.md' => "claim_gate_status: not_reviewed\n",
            'review/operator_review.md' => "operator_review_required: true\n",
        ];
    }
}
