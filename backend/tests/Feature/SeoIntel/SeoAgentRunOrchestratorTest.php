<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentRunOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_runs_full_readonly_chain_and_writes_final_evidence_without_db_writes(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);
        $this->createArticle('orchestrator-article-gap');
        $this->createFaqGapPage();

        Http::fake([
            'https://fermatmind.com/*' => Http::response('<html><head><link rel="canonical" href="https://fermatmind.com/wrong"></head><body></body></html>', 200),
        ]);

        $artifactDir = $this->artifactDir();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:run', [
            '--sources' => 'cms-tdk-gap,runtime-seo-qa,cms-faq-gap',
            '--limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $countsAfter = $this->rowCounts();
        $files = File::files($artifactDir);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($summary, Artisan::output());
        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame('success', $summary['status'] ?? null);
        $evidence = $this->readJson(data_get($summary, 'artifact.path'));
        $this->assertSame('seo-agent-run-evidence.v1', $summary['schema_version'] ?? null);
        $this->assertSame('seo-agent-run-evidence.v1', $evidence['schema_version'] ?? null);
        $this->assertGreaterThanOrEqual(1, $summary['candidate_count'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $summary['draft_brief_count'] ?? 0);
        $this->assertCount(9, $files);

        $this->assertSame([
            'cms-tdk-gap',
            'runtime-seo-qa',
            'cms-faq-gap',
        ], $evidence['sources'] ?? null);
        $this->assertSame('seo-agent-opportunity-aggregate.v1', data_get($evidence, 'artifacts.opportunity_aggregate.schema_version'));
        $this->assertSame('seo-agent-run-control-packet.v1', data_get($evidence, 'artifacts.run_control_packet.schema_version'));
        $this->assertSame('seo-agent-codex-review-handoff.v1', data_get($evidence, 'artifacts.codex_review_handoff.schema_version'));
        $this->assertSame('seo-agent-codex-review-verdict.v1', data_get($evidence, 'artifacts.codex_review_verdict.schema_version'));
        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', data_get($evidence, 'artifacts.cms_draft_package_dry_run.schema_version'));

        $combined = implode("\n", array_map(
            static fn ($file): string => (string) file_get_contents($file->getPathname()),
            $files
        ));

        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined, $forbidden);
        }

        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'scheduler_activation',
            'queue_worker_started',
            'google_search_console_api_call',
            'external_model_api_call',
        ] as $field) {
            $this->assertFalse((bool) data_get($summary, 'negative_guarantees.'.$field, true), $field);
            $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function command_fails_closed_for_invalid_sources(): void
    {
        $exitCode = Artisan::call('seo-agent:run', [
            '--sources' => 'cms-tdk-gap,gsc-live-read',
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('invalid_sources', $summary['issues'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.google_search_console_api_call', true));
    }

    #[Test]
    public function generated_contract_documents_run_orchestrator_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-run-evidence.v1.json'));

        $this->assertSame('seo-agent-run-evidence.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:run', $artifact['command'] ?? null);
        $this->assertFalse((bool) ($artifact['production_scheduler_enabled'] ?? true));
        $this->assertFalse((bool) ($artifact['gsc_live_read_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['external_model_api_call'] ?? true));
        $this->assertTrue((bool) ($artifact['runtime_public_http_read_allowed'] ?? false));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
    }

    private function createArticle(string $slug): Article
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => 'Orchestrator article',
            'excerpt' => 'Orchestrator article.',
            'content_md' => 'Article body must never be emitted.',
            'content_html' => '<p>Article body must never be emitted.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
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
            'slug' => 'orchestrator-help-faq-gap',
            'path' => '/zh/help/orchestrator-faq-gap',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'Orchestrator help',
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
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-run-orchestrator-'.Str::uuid()->toString());
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
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'article_seo_meta' => ArticleSeoMeta::query()->withoutGlobalScopes()->count(),
            'content_pages' => ContentPage::query()->withoutGlobalScopes()->count(),
        ];
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
