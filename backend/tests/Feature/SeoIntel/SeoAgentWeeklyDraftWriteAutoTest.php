<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\ArticleSeoMeta;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentWeeklyDraftWriteAutoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_runs_weekly_chain_filters_policy_and_writes_low_risk_drafts_only(): void
    {
        $this->createArticle('weekly-auto-draft-article-gap');
        $this->createFaqGapPage();

        $artifactDir = $this->artifactDir();

        $exitCode = Artisan::call('seo-agent:weekly-draft-write-auto', [
            '--sources' => 'cms-tdk-gap,cms-faq-gap',
            '--limit' => 10,
            '--draft-limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $files = File::allFiles($artifactDir);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($summary, Artisan::output());
        $this->assertSame('seo-agent-weekly-draft-write-auto.v1', $summary['schema_version'] ?? null);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertGreaterThanOrEqual(2, $summary['auto_approved_count'] ?? 0);
        $this->assertSame(2, ($summary['rows_created'] ?? 0) + ($summary['rows_skipped_existing'] ?? 0));
        $this->assertSame(1, ArticleRevision::query()->count());
        $this->assertSame(1, CmsTranslationRevision::query()->count());

        $evidence = $this->readJson(data_get($summary, 'artifact.path'));
        $this->assertSame('SEO-AGENT-WEEKLY-DRAFT-WRITE-AUTO-BATCH10-01', $evidence['task'] ?? null);
        $this->assertSame('php artisan seo-agent:weekly-draft-write-auto', $evidence['command'] ?? null);
        $this->assertSame('success', $evidence['status'] ?? null);
        $this->assertSame(10, $evidence['draft_limit'] ?? null);
        $this->assertGreaterThanOrEqual(2, data_get($evidence, 'policy_summary.auto_approved_count'));
        $this->assertSame('success', data_get($evidence, 'draft_write.status'));
        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', data_get($evidence, 'filtered_package.schema_version'));
        $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.google_indexing_api_call', true));
        $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.frontend_code_mutation', true));

        $articleRevision = ArticleRevision::query()->firstOrFail();
        $this->assertSame('SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01', data_get($articleRevision->payload_json, 'seo_agent.task'));
        $this->assertFalse((bool) data_get($articleRevision->payload_json, 'seo_agent.publish_allowed', true));

        $combined = implode("\n", array_map(
            static fn ($file): string => (string) file_get_contents($file->getPathname()),
            $files
        ));

        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined, $forbidden);
        }
    }

    #[Test]
    public function command_succeeds_as_noop_when_no_low_risk_candidates_exist(): void
    {
        $artifactDir = $this->artifactDir();

        $exitCode = Artisan::call('seo-agent:weekly-draft-write-auto', [
            '--sources' => 'cms-tdk-gap,cms-faq-gap',
            '--limit' => 10,
            '--draft-limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $evidence = $this->readJson(data_get($summary, 'artifact.path'));

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(0, $summary['auto_approved_count'] ?? null);
        $this->assertSame(0, $summary['rows_created'] ?? null);
        $this->assertSame(0, ArticleRevision::query()->count());
        $this->assertSame(0, CmsTranslationRevision::query()->count());
        $this->assertSame('skipped_no_auto_approved_proposals', data_get($evidence, 'draft_write.status'));
        $this->assertFalse((bool) data_get($evidence, 'draft_write.writes_attempted', true));
    }

    #[Test]
    public function command_fails_closed_for_bad_draft_limit_or_invalid_source(): void
    {
        $exitCode = Artisan::call('seo-agent:weekly-draft-write-auto', [
            '--sources' => 'cms-tdk-gap',
            '--draft-limit' => 11,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('draft_limit_out_of_bounds', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:weekly-draft-write-auto', [
            '--sources' => 'cms-tdk-gap,gsc-live-read',
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('invalid_sources', $summary['issues'] ?? []);
        $this->assertSame(0, ArticleRevision::query()->count());
        $this->assertSame(0, CmsTranslationRevision::query()->count());
    }

    #[Test]
    public function generated_contract_documents_weekly_draft_write_auto_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-weekly-draft-write-auto.v1.json'));

        $this->assertSame('seo-agent-weekly-draft-write-auto.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:weekly-draft-write-auto', $artifact['command'] ?? null);
        $this->assertSame(10, $artifact['max_draft_rows_per_execution'] ?? null);
        $this->assertContains('seo-agent-auto-approval-policy.v1', $artifact['input_contracts'] ?? []);
        $this->assertFalse((bool) ($artifact['publish_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['search_channel_enqueue_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['google_indexing_live_api_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['laravel_scheduler_enabled_by_pr'] ?? true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.external_model_api_call', true));
    }

    private function createArticle(string $slug): Article
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => 'Weekly auto draft article',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Article body must never be emitted.',
            'content_html' => '<p>Article body must never be emitted.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_revision_id' => 99,
            'published_at' => now()->subDay(),
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => $article->id,
            'locale' => 'zh-CN',
            'seo_title' => '',
            'seo_description' => '',
            'canonical_url' => '',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        return $article;
    }

    private function createFaqGapPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'weekly-auto-draft-faq-gap',
            'path' => '/zh/help/weekly-auto-draft-faq-gap',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'Weekly auto draft help',
            'summary' => 'Existing summary.',
            'seo_title' => 'Weekly auto draft help',
            'seo_description' => 'Existing SEO description.',
            'meta_description' => 'Existing SEO description.',
            'canonical_path' => '/zh/help/weekly-auto-draft-faq-gap',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => true,
            'faq_schema_eligible' => true,
            'faq_items' => [],
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
            'published_revision_id' => 88,
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-weekly-draft-write-auto-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStrings(): array
    {
        return [
            'https://fermatmind.com',
            '<html',
            '<p>',
            'raw_url',
            'raw_query',
            'full_url',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'token',
            'cookie',
            'content_md',
            'content_html',
            'raw_html',
            'cms_draft_body',
            'Article body must never be emitted',
        ];
    }
}
