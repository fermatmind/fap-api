<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentPage;
use App\Services\SeoAgent\CmsFaqGapReadonlyScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentCmsFaqGapReadonlyScannerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scanner_finds_only_explicit_faq_gap_signals_without_emitting_raw_content_or_schema(): void
    {
        $missingArticle = $this->createArticle('article-faq-gap');
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => $missingArticle->id,
            'locale' => 'zh-CN',
            'seo_title' => 'FAQ schema article',
            'seo_description' => 'FAQ schema article.',
            'canonical_url' => 'https://fermatmind.com/zh/articles/article-faq-gap',
            'robots' => 'index,follow',
            'is_indexable' => true,
            'schema_json' => [
                '@type' => 'FAQPage',
                'editorial_package_v1' => [
                    'answer_surface_v1' => [
                        'faq_items' => [],
                    ],
                ],
            ],
        ]);

        $completeArticle = $this->createArticle('article-faq-complete');
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => $completeArticle->id,
            'locale' => 'zh-CN',
            'seo_title' => 'Complete FAQ article',
            'seo_description' => 'Complete FAQ article.',
            'canonical_url' => 'https://fermatmind.com/zh/articles/article-faq-complete',
            'robots' => 'index,follow',
            'is_indexable' => true,
            'schema_json' => [
                '@graph' => [
                    ['@type' => 'FAQPage'],
                ],
                'editorial_package_v1' => [
                    'answer_surface_v1' => [
                        'faq_items' => [
                            ['question' => 'What is this page?', 'answer' => 'A complete FAQ page.'],
                        ],
                    ],
                ],
            ],
        ]);

        $noSignalArticle = $this->createArticle('article-without-faq-signal');
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => $noSignalArticle->id,
            'locale' => 'zh-CN',
            'seo_title' => 'No FAQ article',
            'seo_description' => 'No FAQ article.',
            'canonical_url' => 'https://fermatmind.com/zh/articles/article-without-faq-signal',
            'robots' => 'index,follow',
            'is_indexable' => true,
            'schema_json' => ['@type' => 'Article'],
        ]);

        $missingPage = $this->createContentPage('help-faq-gap', '/zh/help/faq-gap', [
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'schema_enabled' => true,
            'faq_schema_eligible' => true,
            'faq_items' => [],
        ]);
        $completePage = $this->createContentPage('help-faq-complete', '/zh/help/faq-complete', [
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'schema_enabled' => true,
            'faq_schema_eligible' => true,
            'faq_items' => [
                ['question' => 'How do I contact support?', 'answer' => 'Use the support page.'],
            ],
        ]);

        $artifact = (new CmsFaqGapReadonlyScanner)->scan('all', 20);

        $this->assertSame('seo-agent-cms-faq-gap-readonly-scanner.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('cms_faq_gap', $artifact['source_family'] ?? null);
        $this->assertSame(2, $artifact['candidate_count'] ?? null);

        $candidateRefs = array_column($artifact['candidates'] ?? [], 'subject_ref');
        $this->assertContains('article:'.$missingArticle->id.':zh-CN', $candidateRefs);
        $this->assertContains('content_page:'.$missingPage->id.':zh-CN', $candidateRefs);
        $this->assertNotContains('article:'.$completeArticle->id.':zh-CN', $candidateRefs);
        $this->assertNotContains('article:'.$noSignalArticle->id.':zh-CN', $candidateRefs);
        $this->assertNotContains('content_page:'.$completePage->id.':zh-CN', $candidateRefs);

        $articleCandidate = collect($artifact['candidates'])->firstWhere('subject_ref', 'article:'.$missingArticle->id.':zh-CN');
        $this->assertSame('/zh/articles/article-faq-gap', $articleCandidate['safe_path'] ?? null);
        $this->assertSame('p1', $articleCandidate['severity'] ?? null);
        $this->assertContains('missing_faq_items', $articleCandidate['gap_types'] ?? []);
        $this->assertContains('faq_schema_enabled_without_visible_faq', $articleCandidate['gap_types'] ?? []);

        $pageCandidate = collect($artifact['candidates'])->firstWhere('subject_ref', 'content_page:'.$missingPage->id.':zh-CN');
        $this->assertSame('/zh/help/faq-gap', $pageCandidate['safe_path'] ?? null);
        $this->assertContains('missing_faq_items', $pageCandidate['gap_types'] ?? []);
        $this->assertContains('faq_schema_enabled_without_visible_faq', $pageCandidate['gap_types'] ?? []);

        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ([
            'https://fermatmind.com',
            'raw_url',
            'full_url',
            'content_md',
            'content_html',
            'schema_json',
            'payload',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'token',
            'Article body must never be emitted',
            'Content page body must never be emitted',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }

        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.faq_schema_enable', true));
    }

    #[Test]
    public function command_writes_sanitized_faq_gap_artifact_without_db_writes(): void
    {
        $this->createContentPage('command-help-faq-gap', '/zh/help/command-faq-gap', [
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'schema_enabled' => true,
            'faq_schema_eligible' => true,
            'faq_items' => [],
        ]);
        $artifactDir = $this->artifactDir();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:cms-faq-gap-scan', [
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
        $this->assertSame(1, $summary['candidate_count'] ?? null);
        $this->assertCount(1, $files);

        $artifact = $this->readJson(data_get($summary, 'artifact.path'));
        $this->assertSame('seo-agent-cms-faq-gap-readonly-scanner.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('cms_faq_gap', $artifact['source_family'] ?? null);

        $combined = (string) file_get_contents($files[0]->getPathname());
        foreach ([
            'https://fermatmind.com',
            'raw_url',
            'schema_json',
            'content_md',
            'content_html',
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
            'faq_schema_enable',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'scheduler_activation',
            'queue_worker_started',
        ] as $field) {
            $this->assertFalse((bool) data_get($summary, 'negative_guarantees.'.$field, true), $field);
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function generated_contract_documents_faq_gap_readonly_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-cms-faq-gap-readonly-scanner.v1.json'));

        $this->assertSame('seo-agent-cms-faq-gap-readonly-scanner.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:cms-faq-gap-scan', $artifact['command'] ?? null);
        $this->assertSame('cms_faq_gap', $artifact['source_family'] ?? null);
        $this->assertContains('all', $artifact['supported_surfaces'] ?? []);

        foreach ([
            'raw_url',
            'full_url',
            'raw_body',
            'content_md',
            'content_html',
            'schema_json',
            'payload',
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
            'faq_schema_enable',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createContentPage(string $slug, string $path, array $overrides = []): ContentPage
    {
        return ContentPage::query()->create(array_merge([
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
            'seo_title' => 'FAQ page title',
            'seo_description' => 'FAQ page description.',
            'meta_description' => 'FAQ page description.',
            'canonical_path' => $path,
            'schema_enabled' => false,
            'faq_schema_eligible' => false,
            'faq_items' => [],
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
        ], $overrides));
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-cms-faq-gap-'.Str::uuid()->toString());
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
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
