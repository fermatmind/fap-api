<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Cms\ArticlePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class Seo13ArticleAtomicPromotionCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BATCH = 'seo13-20260726';

    /**
     * @var list<array{
     *   article_id:int,
     *   working_revision_id:int,
     *   published_revision_id:int,
     *   slug:string,
     *   translation_group_id:string
     * }>
     */
    private const TARGETS = [
        ['article_id' => 1, 'working_revision_id' => 446, 'published_revision_id' => 341, 'slug' => 'big-five-growth-guide', 'translation_group_id' => 'big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61'],
        ['article_id' => 2, 'working_revision_id' => 445, 'published_revision_id' => 347, 'slug' => 'big-five-narrative-portrait', 'translation_group_id' => 'big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970'],
        ['article_id' => 5, 'working_revision_id' => 444, 'published_revision_id' => 5, 'slug' => 'iq-test-growth-guide', 'translation_group_id' => 'article-5'],
        ['article_id' => 6, 'working_revision_id' => 443, 'published_revision_id' => 6, 'slug' => 'iq-test-narrative-portrait', 'translation_group_id' => 'article-6'],
        ['article_id' => 7, 'working_revision_id' => 442, 'published_revision_id' => 7, 'slug' => 'iq-test-tool-guide', 'translation_group_id' => 'article-7'],
        ['article_id' => 9, 'working_revision_id' => 441, 'published_revision_id' => 9, 'slug' => 'mbti-growth-guide', 'translation_group_id' => 'article-9'],
        ['article_id' => 10, 'working_revision_id' => 440, 'published_revision_id' => 10, 'slug' => 'mbti-narrative-portrait', 'translation_group_id' => 'article-10'],
        ['article_id' => 11, 'working_revision_id' => 436, 'published_revision_id' => 30, 'slug' => 'are-infj-men-rare-or-socially-silenced', 'translation_group_id' => 'article-11'],
        ['article_id' => 12, 'working_revision_id' => 437, 'published_revision_id' => 31, 'slug' => 'best-valentines-date-by-personality-and-relationship-science', 'translation_group_id' => 'article-12'],
        ['article_id' => 13, 'working_revision_id' => 439, 'published_revision_id' => 32, 'slug' => 'childhood-dream-job-still-shapes-career-choice', 'translation_group_id' => 'article-13'],
        ['article_id' => 14, 'working_revision_id' => 438, 'published_revision_id' => 33, 'slug' => 'how-16-personality-types-talk-to-an-ai-coach', 'translation_group_id' => 'article-14'],
        ['article_id' => 15, 'working_revision_id' => 434, 'published_revision_id' => 34, 'slug' => 'how-personality-shapes-attitude-toward-ai', 'translation_group_id' => 'article-15'],
        ['article_id' => 16, 'working_revision_id' => 435, 'published_revision_id' => 35, 'slug' => 'which-love-script-fits-you-best', 'translation_group_id' => 'article-16'],
    ];

    public function test_batch_dry_run_locks_exact_thirteen_without_writes(): void
    {
        $this->createCohort();
        $before = $this->revisionState();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->dryRunOptions());
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['production_write_execution']);
        $this->assertSame(13, $payload['target_count']);
        $this->assertCount(13, $payload['rows']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['preflight_state_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['revision_set_sha256']);
        $this->assertSame($before, $this->revisionState());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'codex_controlled_seo13_atomic_working_revision_promotion')
            ->count());
    }

    public function test_batch_execute_promotes_all_thirteen_atomically_and_preserves_holds(): void
    {
        $this->createCohort();
        Cache::put('seo:sitemap-source:v1:fresh', 'held-source', 600);
        Cache::put('seo:sitemap:xml:v6', 'held-xml', 600);
        Cache::put('seo:sitemap:etag:v6', 'held-etag', 600);

        Artisan::call('articles:promote-existing-working-revision', $this->dryRunOptions());
        $preflight = $this->jsonOutput();

        $exitCode = Artisan::call(
            'articles:promote-existing-working-revision',
            $this->executeOptions($preflight),
        );
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['production_write_execution']);
        $this->assertSame(13, $payload['publish_count']);
        $this->assertSame(0, $payload['schema_write_count']);
        $this->assertSame(0, $payload['hreflang_write_count']);
        $this->assertSame(0, $payload['search_submission_count']);
        $this->assertSame(0, $payload['revalidation_count']);
        $this->assertSame(0, $payload['sitemap_eligibility_write_count']);
        $this->assertSame(0, $payload['llms_eligibility_write_count']);
        $this->assertSame(0, $payload['queue_dispatch_count']);

        foreach (self::TARGETS as $target) {
            $article = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail($target['article_id']);
            $this->assertSame($target['working_revision_id'], (int) $article->published_revision_id);
            $this->assertSame($target['working_revision_id'], (int) $article->working_revision_id);
            $this->assertTrue((bool) $article->is_public);
            $this->assertTrue((bool) $article->is_indexable);
            $this->assertTrue((bool) $article->sitemap_eligible);
            $this->assertTrue((bool) $article->llms_eligible);
            $this->assertSame('index,follow', (string) $article->seoMeta?->robots);
            $this->assertSame(['existing' => 'held'], $article->seoMeta?->schema_json);
            $this->assertSame(
                ArticleTranslationRevision::STATUS_PUBLISHED,
                ArticleTranslationRevision::query()->withoutGlobalScopes()
                    ->findOrFail($target['working_revision_id'])
                    ->revision_status,
            );
            $this->assertSame(
                ArticleTranslationRevision::STATUS_STALE,
                ArticleTranslationRevision::query()->withoutGlobalScopes()
                    ->findOrFail($target['published_revision_id'])
                    ->revision_status,
            );
        }

        $this->assertSame('held-source', Cache::get('seo:sitemap-source:v1:fresh'));
        $this->assertSame('held-xml', Cache::get('seo:sitemap:xml:v6'));
        $this->assertSame('held-etag', Cache::get('seo:sitemap:etag:v6'));
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'codex_controlled_seo13_atomic_working_revision_promotion')
            ->count());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'content_release_publish')
            ->count());
    }

    public function test_thirteenth_validation_failure_keeps_first_twelve_unpublished(): void
    {
        $this->createCohort(shortArticleId: 16);
        $before = $this->revisionState();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->dryRunOptions());
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, $payload['publish_count']);
        $this->assertContains(
            'body_han_characters_below_minimum',
            array_column($payload['errors'], 'code'),
        );
        $this->assertSame($before, $this->revisionState());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'codex_controlled_seo13_atomic_working_revision_promotion')
            ->count());
    }

    public function test_atomic_service_rolls_back_first_twelve_when_thirteenth_guard_fails(): void
    {
        $this->createCohort();
        $before = $this->revisionState();
        $targets = array_map(
            static fn (array $target): array => [
                'article_id' => $target['article_id'],
                'working_revision_id' => $target['working_revision_id'],
                'current_published_revision_id' => $target['published_revision_id'],
            ],
            self::TARGETS,
        );

        try {
            app(ArticlePublishService::class)->promoteExistingWorkingRevisionsAtomically(
                $targets,
                validateLockedBatch: static fn (): array => ['locked' => true],
                transactionGuard: static function (Article $article): void {
                    if ((int) $article->id === 16) {
                        throw new RuntimeException('simulated_thirteenth_guard_failure');
                    }
                },
                validateReadback: static fn (array $state): array => $state,
            );
            $this->fail('The thirteenth transaction guard must fail the atomic batch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated_thirteenth_guard_failure', $exception->getMessage());
        }

        $this->assertSame($before, $this->revisionState());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'content_release_publish')
            ->count());
    }

    public function test_execute_rejects_body_hash_drift_without_partial_writes(): void
    {
        $this->createCohort();
        Artisan::call('articles:promote-existing-working-revision', $this->dryRunOptions());
        $preflight = $this->jsonOutput();
        $before = $this->revisionState();

        $target = self::TARGETS[0];
        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($target['working_revision_id'])
            ->update(['content_md' => $this->longBody(99)]);

        $exitCode = Artisan::call(
            'articles:promote-existing-working-revision',
            $this->executeOptions($preflight),
        );
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, $payload['publish_count']);
        $this->assertContains('body_hash_mismatch', array_column($payload['errors'], 'code'));
        $after = $this->revisionState();
        foreach (self::TARGETS as $target) {
            $this->assertSame(
                $before[$target['article_id']]['published_revision_id'],
                $after[$target['article_id']]['published_revision_id'],
            );
            $this->assertSame(
                ArticleTranslationRevision::STATUS_APPROVED,
                $after[$target['article_id']]['working_revision_status'],
            );
        }
    }

    public function test_batch_rejects_private_routes_even_when_import_hash_matches(): void
    {
        $this->createCohort();
        $target = self::TARGETS[12];
        $body = $this->longBody($target['article_id'])."\n\n[查看订单](/orders/123)";
        $hash = $this->bodyHash($body);
        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($target['working_revision_id'])
            ->update(['content_md' => $body]);
        $import = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', $target['article_id'])
            ->latest('id')
            ->firstOrFail();
        $exactness = (array) $import->exactness_json;
        $exactness['body_hash'] = $hash;
        $import->forceFill([
            'body_hash' => $hash,
            'exactness_json' => $exactness,
        ])->save();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->dryRunOptions());
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains(
            'private_route_found_in_working_revision',
            array_column($payload['errors'], 'code'),
        );
        $this->assertSame(0, $payload['publish_count']);
    }

    public function test_batch_rejects_claim_warnings_instead_of_implicitly_acknowledging_them(): void
    {
        $this->createCohort();
        ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', 16)
            ->latest('id')
            ->firstOrFail()
            ->forceFill([
                'claim_result_json' => [
                    'status' => 'warning',
                    'matches' => [
                        ['boundary_context' => true],
                    ],
                ],
            ])
            ->save();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->dryRunOptions());
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('claim_warning_ack_required', array_column($payload['errors'], 'code'));
        $this->assertSame(0, $payload['publish_count']);
    }

    public function test_non_batch_callers_cannot_suppress_release_audit_or_cache_invalidation(): void
    {
        $this->createCohort();
        $target = self::TARGETS[0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('promotion side effects may be held only by the locked SEO 13 atomic lane');

        app(ArticlePublishService::class)->promoteExistingWorkingRevision(
            $target['article_id'],
            $target['working_revision_id'],
            $target['published_revision_id'],
            source: 'untrusted_side_effect_suppression',
            dispatchFollowUp: false,
            recordReleaseAudit: false,
            invalidateDiscoverabilityCaches: false,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function dryRunOptions(): array
    {
        return [
            '--batch' => self::BATCH,
            '--expected-target-count' => 13,
            '--dry-run' => true,
            '--json' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    private function executeOptions(array $preflight): array
    {
        return [
            '--batch' => self::BATCH,
            '--expected-target-count' => 13,
            '--expected-state-sha256' => $preflight['preflight_state_sha256'],
            '--expected-revision-set-sha256' => $preflight['revision_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--preview-approved' => true,
            '--schema-hold' => true,
            '--hreflang-hold' => true,
            '--search-hold' => true,
            '--no-revalidation' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--execute' => true,
            '--json' => true,
        ];
    }

    private function createCohort(?int $shortArticleId = null): void
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'seo-13',
            'name' => 'SEO 13',
            'is_active' => true,
        ]);
        $tag = ArticleTag::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'seo-13',
            'name' => 'SEO 13',
            'is_active' => true,
        ]);

        foreach (self::TARGETS as $index => $target) {
            $articleId = $target['article_id'];
            $group = $target['translation_group_id'];
            $body = $articleId === $shortArticleId ? '短正文。' : $this->longBody($articleId);
            $publishedBody = "## 旧版本\n\n旧正文 {$articleId}。";
            $canonical = 'https://fermatmind.com/zh/articles/'.$target['slug'];

            $article = Article::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
                'id' => $articleId,
                'org_id' => 0,
                'category_id' => (int) $category->id,
                'slug' => $target['slug'],
                'locale' => 'zh-CN',
                'translation_group_id' => $group,
                'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => "旧标题 {$articleId}",
                'excerpt' => "旧摘要 {$articleId}",
                'content_md' => $publishedBody,
                'content_html' => '<p>old</p>',
                'cover_image_url' => 'https://cdn.fermatmind.com/media/seo13-cover.jpg',
                'cover_image_alt' => '费马测试文章封面',
                'status' => 'published',
                'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'published_at' => now()->subDay(),
            ]));
            $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

            $published = ArticleTranslationRevision::unguarded(
                fn (): ArticleTranslationRevision => ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                    'id' => $target['published_revision_id'],
                    'org_id' => 0,
                    'article_id' => $articleId,
                    'source_article_id' => $articleId,
                    'translation_group_id' => $group,
                    'locale' => 'zh-CN',
                    'source_locale' => 'zh-CN',
                    'revision_number' => 1,
                    'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                    'title' => "旧标题 {$articleId}",
                    'excerpt' => "旧摘要 {$articleId}",
                    'content_md' => $publishedBody,
                    'seo_title' => "旧 SEO 标题 {$articleId}",
                    'seo_description' => "旧 SEO 描述 {$articleId}",
                    'published_at' => now()->subDay(),
                ])
            );
            $working = ArticleTranslationRevision::unguarded(
                fn (): ArticleTranslationRevision => ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                    'id' => $target['working_revision_id'],
                    'org_id' => 0,
                    'article_id' => $articleId,
                    'source_article_id' => $articleId,
                    'translation_group_id' => $group,
                    'locale' => 'zh-CN',
                    'source_locale' => 'zh-CN',
                    'revision_number' => 2,
                    'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                    'title' => "SEO 13 新标题 {$articleId}",
                    'excerpt' => "SEO 13 新摘要 {$articleId}",
                    'content_md' => $body,
                    'seo_title' => "SEO 13 新 SEO 标题 {$articleId} | FermatMind",
                    'seo_description' => "SEO 13 新 SEO 描述 {$articleId}。",
                    'reviewed_by' => 1,
                    'reviewed_at' => now()->subMinutes(10),
                    'approved_at' => now()->subMinutes(5),
                    'supersedes_revision_id' => (int) $published->id,
                ])
            );
            $article->forceFill([
                'working_revision_id' => (int) $working->id,
                'published_revision_id' => (int) $published->id,
            ])->save();

            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => $articleId,
                'locale' => 'zh-CN',
                'seo_title' => "旧 SEO 标题 {$articleId}",
                'seo_description' => "旧 SEO 描述 {$articleId}",
                'canonical_url' => $canonical,
                'og_title' => "旧 OG 标题 {$articleId}",
                'og_description' => "旧 OG 描述 {$articleId}",
                'og_image_url' => 'https://cdn.fermatmind.com/media/seo13-cover.jpg',
                'robots' => 'index,follow',
                'schema_json' => ['existing' => 'held'],
                'is_indexable' => true,
            ]);
            ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => $articleId,
                'slug' => $target['slug'],
                'locale' => 'zh-CN',
                'title' => "SEO 13 新标题 {$articleId}",
                'content_track' => 'seo_content_package_existing_article_update',
                'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
                'intended_status' => 'working_revision_human_review',
                'validation_summary_json' => [
                    'source' => 'articles:update-existing-seo-content-package',
                    'operation' => 'update_existing_article_working_revision',
                    'schema_hreflang_search_hold' => true,
                ],
                'claim_result_json' => ['status' => 'not_reviewed', 'matches' => []],
                'exactness_json' => [
                    'status' => 'passed',
                    'article_id' => $articleId,
                    'slug' => $target['slug'],
                    'canonical_url' => $canonical,
                    'body_hash' => $this->bodyHash($body),
                ],
                'references_json' => ['status' => 'complete'],
                'media_json' => ['status' => 'unchanged_hold'],
                'graph_json' => ['status' => 'unchanged_hold'],
                'answer_surface_json' => ['status' => 'visible_only'],
                'body_hash' => $this->bodyHash($body),
                'heading_sequence_json' => ['2:快速答案', '2:常见问题', '2:参考来源'],
                'references_count' => 3,
            ]);
        }
    }

    private function longBody(int $articleId): string
    {
        return "## 快速答案\n\n"
            .str_repeat("第{$articleId}篇文章用可验证的问题、证据和行动步骤帮助读者理解自己，并保留必要边界。", 90)
            ."\n\n## 常见问题\n\n常见问题必须与可见正文一致。\n\n## 参考来源\n\n- 示例公开来源";
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function revisionState(): array
    {
        $state = [];
        foreach (self::TARGETS as $target) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($target['article_id']);
            $state[$target['article_id']] = [
                'published_revision_id' => (int) $article->published_revision_id,
                'working_revision_id' => (int) $article->working_revision_id,
                'working_revision_status' => (string) ArticleTranslationRevision::query()
                    ->withoutGlobalScopes()
                    ->findOrFail($target['working_revision_id'])
                    ->revision_status,
                'previous_revision_status' => (string) ArticleTranslationRevision::query()
                    ->withoutGlobalScopes()
                    ->findOrFail($target['published_revision_id'])
                    ->revision_status,
            ];
        }

        return $state;
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
}
