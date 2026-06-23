<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentArticlePostPublishPropagationDryRunTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_outputs_article_propagation_readiness_without_writes(): void
    {
        $fixture = $this->fixture();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:article-post-publish-propagation-dry-run', [
            '--publish-evidence' => $fixture['publish_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['article_revision_id'],
            '--limit' => 1,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('ready_with_adapter_gap', $summary['status'] ?? null);
        $this->assertSame('/en/articles/article-candidate', data_get($summary, 'canonical.safe_path'));
        $this->assertSame($fixture['target'], data_get($summary, 'runtime.target'));
        $this->assertSame($fixture['published_revision_id'], data_get($summary, 'runtime.published_revision_id'));
        $this->assertSame(35, data_get($summary, 'runtime.seo_title_length'));
        $this->assertTrue((bool) data_get($summary, 'sitemap_llms.already_sitemap_eligible'));
        $this->assertTrue((bool) data_get($summary, 'sitemap_llms.already_llms_eligible'));
        $this->assertTrue((bool) data_get($summary, 'url_truth_readiness.url_truth_page_type_supported'));
        $this->assertFalse((bool) data_get($summary, 'url_truth_readiness.url_truth_adapter_required', true));
        $this->assertTrue((bool) data_get($summary, 'search_channel_readiness.search_channel_adapter_required'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.url_truth_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.indexnow_submit', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame($summary['status'] ?? null, $artifact['status'] ?? null);
        $this->assertSame($fixture['publish_evidence_sha256'], $artifact['publish_evidence_sha256'] ?? null);
    }

    #[Test]
    public function dry_run_blocks_wrong_target_or_revision_lock(): void
    {
        $fixture = $this->fixture();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:article-post-publish-propagation-dry-run', [
            '--publish-evidence' => $fixture['publish_evidence_path'],
            '--target' => 'article:999:en',
            '--revision-id' => $fixture['article_revision_id'],
            '--limit' => 1,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('publish_evidence_target_revision_mismatch', $summary['issues'] ?? []);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function dry_run_blocks_invalid_publish_evidence_schema(): void
    {
        $fixture = $this->fixture(['publish_schema_version' => 'wrong.v1']);

        $exitCode = Artisan::call('seo-agent:article-post-publish-propagation-dry-run', [
            '--publish-evidence' => $fixture['publish_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['article_revision_id'],
            '--limit' => 1,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('publish_evidence_schema_invalid', $summary['issues'] ?? []);
    }

    #[Test]
    public function dry_run_blocks_article_that_is_not_public_indexable_and_published(): void
    {
        $fixture = $this->fixture([
            'article_status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:article-post-publish-propagation-dry-run', [
            '--publish-evidence' => $fixture['publish_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['article_revision_id'],
            '--limit' => 1,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('article_not_published', $summary['issues'] ?? []);
        $this->assertContains('article_not_public', $summary['issues'] ?? []);
        $this->assertContains('article_not_indexable', $summary['issues'] ?? []);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function generated_contract_documents_article_propagation_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-article-post-publish-propagation-dry-run.v1.json'));

        $this->assertSame('seo-agent-article-post-publish-propagation-dry-run.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:article-post-publish-propagation-dry-run', $contract['command'] ?? null);
        $this->assertContains('article', $contract['supported_targets_v1'] ?? []);
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.url_truth_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.indexnow_submit', true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fixture(array $overrides = []): array
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'article-candidate',
            'locale' => 'en',
            'translation_group_id' => (string) Str::uuid(),
            'source_locale' => 'en',
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'content_html' => '<p>Existing article HTML.</p>',
            'status' => (string) ($overrides['article_status'] ?? 'published'),
            'is_public' => (bool) ($overrides['is_public'] ?? true),
            'is_indexable' => (bool) ($overrides['is_indexable'] ?? true),
            'sitemap_eligible' => (bool) ($overrides['sitemap_eligible'] ?? true),
            'llms_eligible' => (bool) ($overrides['llms_eligible'] ?? true),
            'published_at' => now()->subDay(),
        ]);
        $publishedRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 3,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'seo_title' => 'Improved Article Title | FermatMind',
            'seo_description' => 'Improved description for search readers.',
            'published_at' => now()->subHour(),
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $publishedRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
        ])->save();
        ArticleSeoMeta::query()->create([
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Improved Article Title | FermatMind',
            'seo_description' => 'Improved description for search readers.',
            'canonical_url' => '/en/articles/article-candidate',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);
        $articleRevision = ArticleRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'revision_no' => 3,
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'content_html' => '<p>Existing article HTML.</p>',
            'payload_json' => [
                'seo_agent' => [
                    'task' => 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01',
                    'subject_ref' => 'article:'.$article->id.':en',
                ],
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $target = 'article:'.$article->id.':en';
        $publishEvidencePath = $this->writeJson('seo-agent-article-cms-publish-canary-', [
            'schema_version' => (string) ($overrides['publish_schema_version'] ?? 'seo-agent-article-cms-publish-canary.v1'),
            'ok' => true,
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'target' => $target,
            'revision_id' => (int) $articleRevision->id,
            'writes_committed' => true,
            'published_count' => 1,
            'affected_refs' => [
                [
                    'status' => 'published',
                    'target_model' => 'article',
                    'subject_ref' => $target,
                    'revision_id' => (int) $articleRevision->id,
                    'article_translation_revision_id' => (int) $publishedRevision->id,
                ],
            ],
            'rollback_evidence' => [
                'available' => true,
            ],
            'boundaries' => [
                'cms_publish' => true,
                'url_truth_write' => false,
                'sitemap_submission' => false,
                'indexnow_submit' => false,
                'search_channel_enqueue' => false,
                'search_channel_submit' => false,
                'indexing_request' => false,
                'scheduler_activation' => false,
                'queue_worker_start' => false,
            ],
        ]);

        return [
            'article_id' => (int) $article->id,
            'target' => $target,
            'article_revision_id' => (int) $articleRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
            'publish_evidence_path' => $publishEvidencePath,
            'publish_evidence_sha256' => hash_file('sha256', $publishEvidencePath) ?: '',
        ];
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-article-post-publish-propagation-dry-run-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $prefix, array $payload): string
    {
        $path = storage_path('framework/testing/'.$prefix.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'article_revisions' => ArticleRevision::query()->withoutGlobalScopes()->count(),
            'article_translation_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()->count(),
            'article_seo_meta' => ArticleSeoMeta::query()->withoutGlobalScopes()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
