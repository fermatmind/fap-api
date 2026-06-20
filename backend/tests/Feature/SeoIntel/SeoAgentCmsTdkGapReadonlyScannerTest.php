<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentPage;
use App\Services\SeoAgent\CmsTdkGapReadonlyScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentCmsTdkGapReadonlyScannerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scanner_finds_article_and_content_page_tdk_gaps_without_returning_raw_content(): void
    {
        $missingArticle = $this->createArticle('zh-missing-seo-meta');
        $completeArticle = $this->createArticle('zh-complete-seo-meta');
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => $completeArticle->id,
            'locale' => 'zh-CN',
            'seo_title' => 'Complete SEO title',
            'seo_description' => 'Complete SEO description.',
            'canonical_url' => 'https://fermatmind.com/zh/articles/zh-complete-seo-meta',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);
        $page = $this->createContentPage('tdk-gap-page');

        $artifact = (new CmsTdkGapReadonlyScanner)->scan('all', 10);

        $this->assertSame('seo-agent-cms-tdk-gap-readonly-scanner.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('cms_tdk_gap', $artifact['source_family'] ?? null);
        $this->assertSame(2, $artifact['candidate_count'] ?? null);

        $candidateRefs = array_column($artifact['candidates'] ?? [], 'subject_ref');
        $this->assertContains('article:'.$missingArticle->id.':zh-CN', $candidateRefs);
        $this->assertContains('content_page:'.$page->id.':zh-CN', $candidateRefs);
        $this->assertNotContains('article:'.$completeArticle->id.':zh-CN', $candidateRefs);

        $articleCandidate = collect($artifact['candidates'])->firstWhere('subject_ref', 'article:'.$missingArticle->id.':zh-CN');
        $this->assertSame('/zh/articles/zh-missing-seo-meta', $articleCandidate['safe_path'] ?? null);
        $this->assertSame('p1', $articleCandidate['severity'] ?? null);
        $this->assertContains('missing_title', $articleCandidate['gap_types'] ?? []);
        $this->assertContains('missing_meta_description', $articleCandidate['gap_types'] ?? []);
        $this->assertContains('missing_canonical', $articleCandidate['gap_types'] ?? []);
        $this->assertContains('missing_indexability_metadata', $articleCandidate['gap_types'] ?? []);

        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('https://fermatmind.com', $encoded);
        $this->assertStringNotContainsString('raw_url', $encoded);
        $this->assertStringNotContainsString('raw_query', $encoded);
        $this->assertStringNotContainsString('content_md', $encoded);
        $this->assertStringNotContainsString('content_html', $encoded);
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.database_write', true));
    }

    #[Test]
    public function command_writes_scanner_packet_and_codex_review_handoff_artifacts_without_db_writes(): void
    {
        $this->createArticle('zh-command-gap');
        $this->createContentPage('command-gap-page');
        $artifactDir = $this->artifactDir();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:cms-tdk-gap-scan', [
            '--surface' => 'all',
            '--limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $countsAfter = $this->rowCounts();
        $files = File::files($artifactDir);

        $this->assertSame(0, $exitCode);
        $this->assertSame($countsBefore, $countsAfter);
        $this->assertIsArray($summary);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(2, $summary['candidate_count'] ?? null);
        $this->assertCount(3, $files);

        $scanner = $this->readJson(data_get($summary, 'artifacts.scanner.path'));
        $packet = $this->readJson(data_get($summary, 'artifacts.run_control_packet.path'));
        $handoff = $this->readJson(data_get($summary, 'artifacts.codex_review_handoff.path'));

        $this->assertSame('seo-agent-cms-tdk-gap-readonly-scanner.v1', $scanner['schema_version'] ?? null);
        $this->assertSame('seo-agent-run-control-packet.v1', $packet['schema_version'] ?? null);
        $this->assertSame('readonly_discovery', $packet['run_mode'] ?? null);
        $this->assertSame('not_requested', data_get($packet, 'approval.status'));
        $this->assertSame('codex', data_get($packet, 'model_review.reviewer'));
        $this->assertFalse((bool) data_get($packet, 'model_review.execution_permission', true));

        $this->assertSame('seo-agent-codex-review-handoff.v1', $handoff['schema_version'] ?? null);
        $this->assertSame('codex', $handoff['reviewer'] ?? null);
        $this->assertSame('review_only', $handoff['role'] ?? null);
        $this->assertFalse((bool) ($handoff['execution_permission'] ?? true));

        $combined = implode("\n", array_map(
            static fn ($file): string => (string) file_get_contents($file->getPathname()),
            $files
        ));

        foreach ([
            'https://fermatmind.com',
            'raw_url',
            'raw_query',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'token',
            'content_md',
            'content_html',
        ] as $forbidden) {
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
        ] as $field) {
            $this->assertFalse((bool) data_get($summary, 'negative_guarantees.'.$field, true), $field);
            $this->assertFalse((bool) data_get($packet, 'negative_guarantees.'.$field, true), $field);
            $this->assertFalse((bool) data_get($handoff, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function generated_contract_documents_readonly_boundaries_and_codex_handoff(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-cms-tdk-gap-readonly-scanner.v1.json'));

        $this->assertSame('seo-agent-cms-tdk-gap-readonly-scanner.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:cms-tdk-gap-scan', $artifact['command'] ?? null);
        $this->assertSame('cms_tdk_gap', $artifact['source_family'] ?? null);
        $this->assertSame('codex', data_get($artifact, 'codex_review_handoff.reviewer'));
        $this->assertFalse((bool) data_get($artifact, 'codex_review_handoff.execution_permission', true));

        foreach ([
            'raw_url',
            'raw_query',
            'credential_path',
            'service_account_json',
            'client_email',
            'private_key',
            'token',
            'cms_draft_body',
            'content_md',
            'content_html',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_output_fields'] ?? [], $field);
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
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }

    private function createArticle(string $slug): Article
    {
        return Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => 'Readable title',
            'excerpt' => 'Reader-facing excerpt.',
            'content_md' => 'Article body must never be emitted by the scanner.',
            'content_html' => '<p>Article body must never be emitted by the scanner.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    private function createContentPage(string $slug): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => '/zh/'.$slug,
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'Content page',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'is_public' => true,
            'is_indexable' => true,
            'content_md' => 'Content page body must never be emitted.',
            'content_html' => '<p>Content page body must never be emitted.</p>',
            'seo_title' => null,
            'seo_description' => null,
            'meta_description' => null,
            'canonical_path' => null,
            'schema_enabled' => false,
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
        $dir = storage_path('framework/testing/seo-agent-cms-tdk-gap-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
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
     * @return array<string, mixed>
     */
    private function readJson(?string $path): array
    {
        $this->assertIsString($path);
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
