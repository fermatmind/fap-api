<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoOpsP0CtrRepairDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_resolves_p0_ctr_repair_authority_without_business_writes(): void
    {
        $this->seedAuthorityRows();
        $source = $this->writeSourceArtifact($this->sourceArtifact());
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:p0-ctr-repair-dry-run', [
            '--artifact' => $source['path'],
            '--confirm-artifact-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(2, data_get($summary, 'planned_write_count.landing_surfaces'));
        $this->assertSame(3, data_get($summary, 'planned_write_count.article_cms_updates'));
        $this->assertSame(5, data_get($summary, 'planned_write_count.total'));
        $this->assertSame('backend.landing_surfaces', data_get($summary, 'authority_sources.landing_surfaces'));
        $this->assertSame('backend.articles + article_seo_meta + article_translation_revisions', data_get($summary, 'authority_sources.article_cms_updates'));
        $this->assertSame('no_change', data_get($summary, 'protected_diff_summary.slug'));
        $this->assertSame('no_change', data_get($summary, 'protected_diff_summary.canonical'));
        $this->assertSame('resolved_no_change', data_get($summary, 'protected_diff_summary.article_id'));
        $this->assertSame('hold_no_change', data_get($summary, 'protected_diff_summary.schema'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'evidence.path'));
        $this->assertSame('seo-ops-p0-ctr-repair-dry-run.v1', $artifact['schema_version'] ?? null);
        $this->assertSame($source['sha256'], data_get($artifact, 'source_artifact.sha256'));
        $this->assertCount(2, $artifact['landing_surface_plans'] ?? []);
        $this->assertCount(3, $artifact['article_plans'] ?? []);
        $this->assertSame('zh-CN', data_get($artifact, 'landing_surface_plans.0.resolved_locale'));
        $this->assertSame('/zh/articles/riasec-holland-career-interest-test-explained', data_get($artifact, 'article_plans.1.current.canonical_path'));
    }

    #[Test]
    public function dry_run_blocks_wrong_artifact_sha(): void
    {
        $source = $this->writeSourceArtifact($this->sourceArtifact());

        $exitCode = Artisan::call('seo-ops:p0-ctr-repair-dry-run', [
            '--artifact' => $source['path'],
            '--confirm-artifact-sha256' => str_repeat('0', 64),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('artifact_sha_mismatch', $summary['issues'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.database_write', true));
    }

    #[Test]
    public function dry_run_blocks_missing_backend_authority_rows_without_business_writes(): void
    {
        $source = $this->writeSourceArtifact($this->sourceArtifact());
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:p0-ctr-repair-dry-run', [
            '--artifact' => $source['path'],
            '--confirm-artifact-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertSame(0, data_get($summary, 'planned_write_count.total'));
        $this->assertContains('landing_surface_not_found:test_detail_mbti_personality_test_16_personality_types:zh-CN', $summary['issues'] ?? []);
        $this->assertContains('article_not_found:what-is-riasec-holland-code-career-interest-test:en', $summary['issues'] ?? []);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function dry_run_rejects_forbidden_input_fields(): void
    {
        $artifact = $this->sourceArtifact();
        $artifact['content_md'] = 'must not be accepted';
        $source = $this->writeSourceArtifact($artifact);

        $exitCode = Artisan::call('seo-ops:p0-ctr-repair-dry-run', [
            '--artifact' => $source['path'],
            '--confirm-artifact-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_p0_ctr_repair_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-ops-p0-ctr-repair-dry-run.v1.json'));

        $this->assertSame('seo-ops-p0-ctr-repair-dry-run.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-ops:p0-ctr-repair-dry-run', $contract['command'] ?? null);
        $this->assertSame('fermatmind-seo-ops-ctr-repair-p0-dry-run-preview.v1', $contract['input_schema'] ?? null);
        $this->assertSame(2, data_get($contract, 'resolves.landing_surfaces.expected_count'));
        $this->assertSame(3, data_get($contract, 'resolves.article_cms_updates.expected_count'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.indexnow_submit', true));
    }

    private function seedAuthorityRows(): void
    {
        $this->createLandingSurface('test_detail_mbti_personality_test_16_personality_types', 'MBTI免费测试');
        $this->createLandingSurface('test_detail_holland_career_interest_test_riasec', '霍兰德职业兴趣测试');

        $this->createArticle('what-is-riasec-holland-code-career-interest-test', 'en', 'What Is RIASEC?', '/en/articles/what-is-riasec-holland-code-career-interest-test');
        $this->createArticle('riasec-holland-career-interest-test-explained', 'zh-CN', '霍兰德职业兴趣测试是什么？', '/zh/articles/riasec-holland-career-interest-test-explained');
        $this->createArticle('mbti-basics', 'zh-CN', 'MBTI 是什么？', '/zh/articles/mbti-basics');
    }

    private function createLandingSurface(string $surfaceKey, string $title): void
    {
        LandingSurface::query()->create([
            'org_id' => 0,
            'surface_key' => $surfaceKey,
            'locale' => 'zh-CN',
            'title' => $title,
            'description' => 'Existing description.',
            'schema_version' => 'v1',
            'payload_json' => [
                'seo_title' => $title.' | FermatMind',
                'seo_description' => 'Existing SEO description.',
                'h1_or_hero_title' => $title,
                'primary_cta_label' => '开始测试',
            ],
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    private function createArticle(string $slug, string $locale, string $title, string $canonicalPath): void
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => (string) Str::uuid(),
            'source_locale' => $locale,
            'title' => $title,
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing markdown.',
            'content_html' => '<p>Existing HTML.</p>',
            'status' => 'published',
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
            'content_md' => 'Existing markdown.',
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
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceArtifact(): array
    {
        return [
            'schema' => 'fermatmind-seo-ops-ctr-repair-p0-dry-run-preview.v1',
            'generated_at' => now()->utc()->toIso8601String(),
            'scope' => [
                'read_only_package_prep' => true,
                'cms_write_allowed' => false,
                'publish_allowed' => false,
                'search_submit_allowed' => false,
                'url_truth_write_allowed' => false,
                'sitemap_llms_mutation_allowed' => false,
                'schema_enable_allowed' => false,
                'hreflang_enable_allowed' => false,
                'deploy_allowed' => false,
            ],
            'authority_groups' => [
                'test_landing_surfaces' => [
                    $this->landingCandidate('test_detail_mbti_personality_test_16_personality_types', 'mbti-personality-test-16-personality-types'),
                    $this->landingCandidate('test_detail_holland_career_interest_test_riasec', 'holland-career-interest-test-riasec'),
                ],
                'article_cms_updates' => [
                    $this->articleCandidate('what-is-riasec-holland-code-career-interest-test', 'en'),
                    $this->articleCandidate('riasec-holland-career-interest-test-explained', 'zh'),
                    $this->articleCandidate('mbti-basics', 'zh'),
                ],
            ],
            'negative_guarantees' => [
                'db_write' => false,
                'cms_write' => false,
                'cms_publish' => false,
                'search_channel_enqueue' => false,
                'indexnow_submit' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function landingCandidate(string $surfaceKey, string $slug): array
    {
        return [
            'payload_key' => 'test_landing_surface::'.$surfaceKey.'::zh',
            'authority_source' => 'backend landing_surfaces payload',
            'surface_key' => $surfaceKey,
            'locale' => 'zh',
            'safe_path' => '/zh/tests/'.$slug,
            'slug' => $slug,
            'proposed_payload_json_updates' => [
                'seo_title' => '新的测试页标题 | FermatMind',
                'seo_description' => '新的测试页描述。',
                'h1_or_hero_title' => '新的 H1',
                'primary_cta_label' => '开始免费测试',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleCandidate(string $slug, string $locale): array
    {
        return [
            'payload_key' => 'article_cms_update::'.$slug.'::'.$locale,
            'authority_source' => 'backend CMS Article detail',
            'locale' => $locale,
            'safe_path' => ($locale === 'en' ? '/en' : '/zh').'/articles/'.$slug,
            'slug' => $slug,
            'proposed_cms_field_updates' => [
                'seo_title' => 'New SEO title',
                'seo_description' => 'New SEO description.',
                'primary_cta_path' => ($locale === 'en' ? '/en' : '/zh').'/tests/holland-career-interest-test-riasec',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{path:string, sha256:string}
     */
    private function writeSourceArtifact(array $artifact): array
    {
        $path = $this->artifactDir().'/source.json';
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'landing_surfaces' => DB::table('landing_surfaces')->count(),
            'articles' => DB::table('articles')->count(),
            'article_seo_meta' => DB::table('article_seo_meta')->count(),
            'article_translation_revisions' => DB::table('article_translation_revisions')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-ops-p0-ctr-'.Str::random(8));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
