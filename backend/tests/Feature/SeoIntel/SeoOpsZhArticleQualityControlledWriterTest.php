<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoOpsZhArticleQualityControlledWriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_plans_nine_deterministic_article_repairs_without_writes(): void
    {
        $this->seedArticleAuthorityRows();
        $dryRun = $this->writeJson($this->dryRunEvidence());
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:zh-article-quality-controlled-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => $dryRun['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(9, data_get($summary, 'planned_write_count.article_quality_repairs'));
        $this->assertStringContainsString('SEO-OPS-ZH-ARTICLE-QUALITY-CONTROLLED-WRITER-01', (string) ($summary['required_confirmation_phrase'] ?? ''));
        $this->assertFalse((bool) data_get($summary, 'side_effects.database_write', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.search_channel_enqueue', true));
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function execute_repairs_article_content_and_readback_passes_without_publish_or_search_side_effects(): void
    {
        $articles = $this->seedArticleAuthorityRows();
        $dryRun = $this->writeJson($this->dryRunEvidence());
        $phrase = $this->confirmationPhrase($dryRun['sha256']);
        $countsBefore = $this->rowCounts();
        $protectedBefore = $this->protectedSnapshots($articles);

        $exitCode = Artisan::call('seo-ops:zh-article-quality-controlled-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => $dryRun['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--execute' => true,
            '--confirm-write' => $phrase,
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'side_effects.database_write'));
        $this->assertTrue((bool) data_get($summary, 'side_effects.cms_article_update'));
        $this->assertFalse((bool) data_get($summary, 'side_effects.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'side_effects.indexnow_submit', true));
        $this->assertSame($countsBefore, $this->rowCounts());
        $this->assertSame($protectedBefore, $this->protectedSnapshots($articles));

        $first = Article::query()->withoutGlobalScopes()->with('publishedRevision')->findOrFail((int) $articles[0]->id);
        $this->assertStringNotContainsString('Dynamic next steps', (string) $first->content_md);
        $this->assertStringContainsString('下一步怎么做', (string) $first->content_md);
        $this->assertStringContainsString('常见问题', (string) $first->publishedRevision?->content_md);

        $writerArtifact = $this->readJson((string) data_get($summary, 'evidence.path'));
        $writerPath = (string) data_get($summary, 'evidence.path');
        $writerSha = (string) data_get($summary, 'evidence.sha256');
        $this->assertSame('seo-ops-zh-article-quality-controlled-writer.v1', $writerArtifact['schema_version'] ?? null);

        $exitCode = Artisan::call('seo-ops:zh-article-quality-readback', [
            '--writer-evidence' => $writerPath,
            '--confirm-writer-evidence-sha256' => $writerSha,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $readback = $this->jsonOutput();

        $this->assertSame(0, $exitCode, json_encode($readback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue((bool) ($readback['ok'] ?? false));
        $this->assertSame('success', $readback['status'] ?? null);
        $this->assertSame(9, data_get($readback, 'readback_count.passed'));
        $this->assertSame(0, data_get($readback, 'readback_count.blocked'));
        $this->assertFalse((bool) data_get($readback, 'side_effects.database_write', true));
    }

    #[Test]
    public function execute_requires_exact_confirmation_phrase(): void
    {
        $this->seedArticleAuthorityRows();
        $dryRun = $this->writeJson($this->dryRunEvidence());

        $exitCode = Artisan::call('seo-ops:zh-article-quality-controlled-writer', [
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
        $this->assertSame(9, Article::query()->withoutGlobalScopes()->where('content_md', 'like', '%Dynamic next steps%')->count());
    }

    #[Test]
    public function writer_blocks_wrong_dry_run_evidence_sha(): void
    {
        $this->seedArticleAuthorityRows();
        $dryRun = $this->writeJson($this->dryRunEvidence());

        $exitCode = Artisan::call('seo-ops:zh-article-quality-controlled-writer', [
            '--dry-run-evidence' => $dryRun['path'],
            '--confirm-dry-run-evidence-sha256' => str_repeat('0', 64),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertContains('dry_run_evidence_sha_mismatch', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_controlled_writer_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-ops-zh-article-quality-controlled-writer.v1.json'));

        $this->assertSame('seo-ops-zh-article-quality-controlled-writer.v1', $contract['version'] ?? null);
        $this->assertContains('php artisan seo-ops:zh-article-quality-controlled-writer', $contract['commands'] ?? []);
        $this->assertSame(9, data_get($contract, 'scope.expected_article_count'));
        $this->assertSame('下一步怎么做', data_get($contract, 'allowed_replacements.Dynamic next steps'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.indexnow_submit', true));
    }

    /**
     * @return list<Article>
     */
    private function seedArticleAuthorityRows(): array
    {
        $articles = [];
        foreach ($this->articleSlugs() as $slug) {
            $articles[] = $this->createArticle($slug, 'zh-CN', '现有标题 '.$slug, '/zh/articles/'.$slug);
        }

        return $articles;
    }

    private function createArticle(string $slug, string $locale, string $title, string $canonicalPath): Article
    {
        $content = "# {$title}\n\n## Dynamic next steps\n\n## Frequently asked questions\n\n## Related reading\n\n## Trust links\n\n[/tests/mbti-personality-test-16-personality-types](/tests/mbti-personality-test-16-personality-types)\n[/science](/science)\n";
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => (string) Str::uuid(),
            'source_locale' => $locale,
            'title' => $title,
            'excerpt' => 'Existing excerpt.',
            'content_md' => $content,
            'content_html' => '<h2>Dynamic next steps</h2><h2>Frequently asked questions</h2><a href="/tests/mbti-personality-test-16-personality-types">test</a>',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
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
            'content_md' => $content,
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
     * @return list<string>
     */
    private function articleSlugs(): array
    {
        return [
            'riasec-holland-career-interest-test-explained',
            'mbti-basics',
            'big-five-tool-guide',
            'iq-test-score-and-limits-explained',
            'eq-test-tool-guide',
            'enneagram-personality-test-explained',
            'college-major-choice-holland-mbti-career-test',
            'career-confusion-test-map',
            'career-interest-vs-personality-test-differences',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dryRunEvidence(): array
    {
        $plans = [];
        foreach (Article::query()->withoutGlobalScopes()->orderBy('id')->get() as $article) {
            $plans[] = [
                'target' => 'article:'.(int) $article->id.':zh-CN',
                'path' => '/zh/articles/'.$article->slug,
                'slug' => (string) $article->slug,
                'locale' => 'zh-CN',
                'resolved' => true,
                'current' => [
                    'article_id' => (int) $article->id,
                    'canonical_path' => '/zh/articles/'.$article->slug,
                ],
                'planned_repairs' => [
                    'heading_replacements' => [
                        ['find' => 'Dynamic next steps', 'replace_with' => '下一步怎么做', 'scope' => 'cms_article_body_or_module_heading'],
                        ['find' => 'Frequently asked questions', 'replace_with' => '常见问题', 'scope' => 'cms_article_body_or_module_heading'],
                        ['find' => 'Related reading', 'replace_with' => '相关阅读', 'scope' => 'cms_article_body_or_module_heading'],
                        ['find' => 'Trust links', 'replace_with' => '可信度与边界', 'scope' => 'cms_article_body_or_module_heading'],
                    ],
                    'link_replacements' => [
                        ['find' => '/tests/mbti-personality-test-16-personality-types', 'replace_with' => '/zh/tests/mbti-personality-test-16-personality-types', 'scope' => 'cms_article_body_or_module_link'],
                        ['find' => '/science', 'replace_with' => '/zh/science', 'scope' => 'cms_article_body_or_module_link'],
                    ],
                ],
                'issues' => [],
            ];
        }

        return [
            'schema_version' => 'seo-ops-zh-article-quality-repair-dry-run.v1',
            'ok' => true,
            'status' => 'planned',
            'dry_run' => true,
            'execute' => false,
            'candidate_counts' => [
                'package_operations' => 9,
                'resolved_article_operations' => 9,
                'unresolved_article_operations' => 0,
            ],
            'article_operation_plans' => $plans,
            'negative_guarantees' => [
                'database_write' => false,
                'cms_write' => false,
                'cms_publish' => false,
                'url_truth_write' => false,
                'schema_enable' => false,
                'hreflang_enable' => false,
                'sitemap_write' => false,
                'llms_write' => false,
                'search_channel_enqueue' => false,
                'indexnow_submit' => false,
                'baidu_submit' => false,
                'gsc_request_indexing' => false,
                'revalidation' => false,
                'deploy' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path:string,sha256:string}
     */
    private function writeJson(array $payload): array
    {
        $path = $this->artifactDir().'/input-'.Str::uuid()->toString().'.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => DB::table('articles')->count(),
            'article_translation_revisions' => DB::table('article_translation_revisions')->count(),
            'article_seo_meta' => DB::table('article_seo_meta')->count(),
        ];
    }

    /**
     * @param  list<Article>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function protectedSnapshots(array $articles): array
    {
        $snapshots = [];
        foreach ($articles as $article) {
            $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
            $snapshots[(int) $fresh->id] = [
                'slug' => (string) $fresh->slug,
                'locale' => (string) $fresh->locale,
                'status' => (string) $fresh->status,
                'published_revision_id' => $fresh->published_revision_id,
                'working_revision_id' => $fresh->working_revision_id,
                'canonical_url' => (string) $fresh->seoMeta?->canonical_url,
                'is_public' => (bool) $fresh->is_public,
                'is_indexable' => (bool) $fresh->is_indexable,
                'sitemap_eligible' => (bool) $fresh->sitemap_eligible,
                'llms_eligible' => (bool) $fresh->llms_eligible,
            ];
        }

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function confirmationPhrase(string $sha): string
    {
        return 'I explicitly approve SEO-OPS-ZH-ARTICLE-QUALITY-CONTROLLED-WRITER-01 to write 9 zh-CN article quality deterministic heading/link repairs from dry-run evidence sha256 '.$sha.'; no publish, no URL Truth, no sitemap/llms, no schema/hreflang, no Search Channel, no IndexNow/Baidu/GSC, no deploy/revalidation.';
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-ops-zh-article-quality-controlled-writer-'.Str::uuid()->toString());
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
