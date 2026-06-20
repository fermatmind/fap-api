<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ContentPage;
use App\Services\SeoAgent\RuntimeSeoQaReadonlyScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentRuntimeSeoQaReadonlyScannerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scanner_flags_runtime_seo_qa_issues_without_emitting_raw_runtime_payloads(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);
        $healthyArticle = $this->createArticle('healthy-runtime-page');
        $noindexPage = $this->createContentPage('runtime-noindex-page', '/zh/runtime-noindex-page');
        $redirectPage = $this->createContentPage('runtime-redirect-page', '/zh/runtime-redirect-page');

        Http::fake([
            'https://fermatmind.com/zh/articles/healthy-runtime-page' => Http::response($this->html('/zh/articles/healthy-runtime-page', true), 200),
            'https://fermatmind.com/zh/runtime-noindex-page' => Http::response($this->html('/zh/wrong-canonical', false, 'noindex, nofollow'), 200, [
                'X-Robots-Tag' => 'noindex',
            ]),
            'https://fermatmind.com/zh/runtime-redirect-page' => Http::response('', 301, [
                'Location' => 'https://fermatmind.com/zh/runtime-redirect-target',
            ]),
        ]);

        $artifact = (new RuntimeSeoQaReadonlyScanner)->scan('cms-indexable', 10);

        $this->assertSame('seo-agent-runtime-seo-qa-readonly-scanner.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('runtime_seo_qa', $artifact['source_family'] ?? null);
        $this->assertSame(2, $artifact['candidate_count'] ?? null);

        $candidateRefs = array_column($artifact['candidates'] ?? [], 'subject_ref');
        $this->assertContains('content_page:'.$noindexPage->id.':zh-CN', $candidateRefs);
        $this->assertContains('content_page:'.$redirectPage->id.':zh-CN', $candidateRefs);
        $this->assertNotContains('article:'.$healthyArticle->id.':zh-CN', $candidateRefs);

        $noindexCandidate = collect($artifact['candidates'])->firstWhere('subject_ref', 'content_page:'.$noindexPage->id.':zh-CN');
        $this->assertContains('canonical_mismatch', $noindexCandidate['gap_types'] ?? []);
        $this->assertContains('noindex_present', $noindexCandidate['gap_types'] ?? []);
        $this->assertContains('x_robots_noindex', $noindexCandidate['gap_types'] ?? []);
        $this->assertContains('missing_json_ld', $noindexCandidate['gap_types'] ?? []);

        $redirectCandidate = collect($artifact['candidates'])->firstWhere('subject_ref', 'content_page:'.$redirectPage->id.':zh-CN');
        $this->assertContains('redirect_present', $redirectCandidate['gap_types'] ?? []);

        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ([
            'https://fermatmind.com',
            '<html',
            '<p>',
            'raw_html',
            'raw_url',
            'full_url',
            'cookie',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'token',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }

        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_search_console_api_call', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_indexing_api_call', true));
    }

    #[Test]
    public function command_writes_sanitized_runtime_qa_artifact_without_db_writes(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);
        $this->createContentPage('command-runtime-page', '/zh/command-runtime-page');
        Http::fake([
            'https://fermatmind.com/zh/command-runtime-page' => Http::response($this->html('/zh/command-runtime-page', false), 200),
        ]);

        $artifactDir = $this->artifactDir();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:runtime-seo-qa-scan', [
            '--source' => 'cms-indexable',
            '--limit' => 5,
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
        $this->assertSame(1, $summary['candidate_count'] ?? null);
        $this->assertCount(1, $files);

        $artifact = $this->readJson(data_get($summary, 'artifact.path'));
        $this->assertSame('seo-agent-runtime-seo-qa-readonly-scanner.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('runtime_seo_qa', $artifact['source_family'] ?? null);

        $combined = (string) file_get_contents($files[0]->getPathname());
        foreach ([
            'https://fermatmind.com',
            '<html',
            'raw_html',
            'raw_url',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'token',
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
            'google_search_console_api_call',
            'google_indexing_api_call',
        ] as $field) {
            $this->assertFalse((bool) data_get($summary, 'negative_guarantees.'.$field, true), $field);
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function generated_contract_documents_runtime_qa_readonly_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-runtime-seo-qa-readonly-scanner.v1.json'));

        $this->assertSame('seo-agent-runtime-seo-qa-readonly-scanner.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:runtime-seo-qa-scan', $artifact['command'] ?? null);
        $this->assertSame('runtime_seo_qa', $artifact['source_family'] ?? null);
        $this->assertContains('cms-indexable', $artifact['supported_sources'] ?? []);

        foreach ([
            'raw_url',
            'full_url',
            'raw_html',
            'credential_path',
            'service_account_json',
            'client_email',
            'private_key',
            'token',
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
            'google_search_console_api_call',
            'google_indexing_api_call',
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

    private function createContentPage(string $slug, string $path): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => $path,
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
            'seo_title' => 'Runtime page title',
            'seo_description' => 'Runtime page description.',
            'meta_description' => 'Runtime page description.',
            'canonical_path' => $path,
            'schema_enabled' => true,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
        ]);
    }

    private function html(string $canonicalPath, bool $withJsonLd, string $robots = 'index, follow'): string
    {
        $jsonLd = $withJsonLd ? '<script type="application/ld+json">{"@context":"https://schema.org"}</script>' : '';

        return '<html><head><link rel="canonical" href="https://fermatmind.com'.$canonicalPath.'"><meta name="robots" content="'.$robots.'">'.$jsonLd.'</head><body><p>Runtime body must not be emitted.</p></body></html>';
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-runtime-seo-qa-'.Str::uuid()->toString());
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
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
