<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoOpsZhArticleQualityRepairDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_resolves_zh_article_quality_package_without_business_writes(): void
    {
        $this->seedArticleAuthorityRows();
        $source = $this->writeSourcePackage($this->sourcePackage());
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:zh-article-quality-repair-dry-run', [
            '--package' => $source['path'],
            '--confirm-package-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(9, data_get($summary, 'candidate_counts.package_operations'));
        $this->assertSame(9, data_get($summary, 'candidate_counts.resolved_article_operations'));
        $this->assertSame(9, data_get($summary, 'planned_ops_count.article_quality_repair_operations'));
        $this->assertSame(33, data_get($summary, 'planned_ops_count.heading_replacements'));
        $this->assertSame(9, data_get($summary, 'planned_ops_count.link_replacements'));
        $this->assertSame('backend.articles + article_seo_meta + article_translation_revisions', data_get($summary, 'authority_sources.article_quality_repair'));
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
        $this->assertSame('seo-ops-zh-article-quality-repair-dry-run.v1', $artifact['schema_version'] ?? null);
        $this->assertSame($source['sha256'], data_get($artifact, 'source_package.sha256'));
        $this->assertCount(9, $artifact['article_operation_plans'] ?? []);
        $this->assertSame('article:1:zh-CN', data_get($artifact, 'article_operation_plans.0.target'));
        $this->assertSame('/zh/articles/riasec-holland-career-interest-test-explained', data_get($artifact, 'article_operation_plans.0.current.canonical_path'));
        $this->assertSame('下一步怎么做', data_get($artifact, 'article_operation_plans.0.planned_repairs.heading_replacements.0.replace_with'));
        $linkRepairs = data_get($artifact, 'article_operation_plans.7.planned_repairs.link_replacements');
        $this->assertIsArray($linkRepairs);
        $this->assertContains([
            'find' => '/tests/mbti-personality-test-16-personality-types',
            'replace_with' => '/zh/tests/mbti-personality-test-16-personality-types',
            'scope' => 'cms_article_body_or_internal_link',
        ], $linkRepairs);
        $this->assertNotContains([
            'find' => '',
            'replace_with' => '',
            'scope' => 'cms_article_body_or_internal_link',
        ], $linkRepairs);
    }

    #[Test]
    public function dry_run_blocks_wrong_package_sha(): void
    {
        $source = $this->writeSourcePackage($this->sourcePackage());

        $exitCode = Artisan::call('seo-ops:zh-article-quality-repair-dry-run', [
            '--package' => $source['path'],
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('package_sha_mismatch', $summary['issues'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.database_write', true));
    }

    #[Test]
    public function dry_run_blocks_missing_backend_authority_rows_without_business_writes(): void
    {
        $source = $this->writeSourcePackage($this->sourcePackage());
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:zh-article-quality-repair-dry-run', [
            '--package' => $source['path'],
            '--confirm-package-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertSame(0, data_get($summary, 'planned_ops_count.article_quality_repair_operations'));
        $this->assertContains('article_not_found:riasec-holland-career-interest-test-explained:zh-CN', $summary['issues'] ?? []);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function dry_run_rejects_forbidden_input_fields(): void
    {
        $package = $this->sourcePackage();
        $package['content_html'] = '<p>must not be accepted</p>';
        $source = $this->writeSourcePackage($package);

        $exitCode = Artisan::call('seo-ops:zh-article-quality-repair-dry-run', [
            '--package' => $source['path'],
            '--confirm-package-sha256' => $source['sha256'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_zh_article_quality_repair_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-ops-zh-article-quality-repair-dry-run.v1.json'));

        $this->assertSame('seo-ops-zh-article-quality-repair-dry-run.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-ops:zh-article-quality-repair-dry-run', $contract['command'] ?? null);
        $this->assertSame('fermatmind-zh-article-quality-cms-repair-package.v1', $contract['input_schema'] ?? null);
        $this->assertSame(9, data_get($contract, 'resolves.article_quality_repair.expected_count'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.indexnow_submit', true));
    }

    private function seedArticleAuthorityRows(): void
    {
        foreach ($this->articleSlugs() as $slug) {
            $this->createArticle($slug, 'zh-CN', 'Existing '.$slug, '/zh/articles/'.$slug);
        }
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
     * @return array<int, string>
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
    private function sourcePackage(): array
    {
        return [
            'schema' => 'fermatmind-zh-article-quality-cms-repair-package.v1',
            'generated_at' => now()->utc()->toIso8601String(),
            'source_scan' => 'generated/seo-ops-zh-article-quality-localization-repair-scan-20260624-04/runtime_scan_analysis.json',
            'source_scan_sha256' => str_repeat('a', 64),
            'scope' => 'raw route leak pages plus all zh-CN article pages with English module heading remnants',
            'operation_count' => 9,
            'operations' => [
                $this->operation('riasec-holland-career-interest-test-explained', ['Dynamic next steps', 'Frequently asked questions', 'Related reading']),
                $this->operation('mbti-basics', ['Dynamic next steps', 'Frequently asked questions', 'Related reading']),
                $this->operation('big-five-tool-guide', ['Dynamic next steps', 'Frequently asked questions', 'Related reading']),
                $this->operation('iq-test-score-and-limits-explained', ['Dynamic next steps', 'Frequently asked questions', 'Related reading', 'Trust links']),
                $this->operation('eq-test-tool-guide', ['Dynamic next steps', 'Frequently asked questions', 'Related reading', 'Related reading / internal links', 'Trust links']),
                $this->operation('enneagram-personality-test-explained', ['Dynamic next steps', 'Frequently asked questions', 'Related reading', 'Related reading / internal links', 'Trust links']),
                $this->operation('college-major-choice-holland-mbti-career-test', ['Dynamic next steps', 'Frequently asked questions', 'Related reading', 'Related reading / internal links', 'Trust links']),
                $this->operation('career-confusion-test-map', ['Dynamic next steps', 'Frequently asked questions', 'Related reading'], [
                    ['/tests/mbti-personality-test-16-personality-types', '/zh/tests/mbti-personality-test-16-personality-types'],
                    ['/tests/holland-career-interest-test-riasec', '/zh/tests/holland-career-interest-test-riasec'],
                    ['/science', '/zh/science'],
                    ['/method-boundaries', '/zh/method-boundaries'],
                    ['/reliability-validity', '/zh/reliability-validity'],
                    ['/tests/big-five-personality-test-ocean-model', '/zh/tests/big-five-personality-test-ocean-model'],
                ]),
                $this->operation('career-interest-vs-personality-test-differences', ['Frequently asked questions', 'Related reading'], [
                    ['/tests/mbti-personality-test-16-personality-types', '/zh/tests/mbti-personality-test-16-personality-types'],
                    ['/tests/holland-career-interest-test-riasec', '/zh/tests/holland-career-interest-test-riasec'],
                    ['/tests/big-five-personality-test-ocean-model', '/zh/tests/big-five-personality-test-ocean-model'],
                ]),
            ],
            'negative_guarantees' => [
                'cms_write' => false,
                'publish' => false,
                'search_submit' => false,
                'sitemap_llms' => false,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $headingFinds
     * @param  array<int, array{0:string,1:string}>  $linkPairs
     * @return array<string, mixed>
     */
    private function operation(string $slug, array $headingFinds, array $linkPairs = []): array
    {
        return [
            'path' => '/zh/articles/'.$slug,
            'snapshot_path' => 'generated/snapshots/zh__articles__'.$slug.'.html',
            'snapshot_sha256' => hash('sha256', $slug),
            'issue_codes' => 'english_heading_remnant;english_faq_heading',
            'heading_replacements' => array_map(static fn (string $find): array => [
                'find' => $find,
                'replace_with' => match ($find) {
                    'Dynamic next steps' => '下一步怎么做',
                    'Frequently asked questions' => '常见问题',
                    'Frequently Asked Questions' => '常见问题',
                    'Related reading', 'Related reading / internal links' => '相关阅读',
                    'Trust links' => '可信度与边界',
                    default => '已本地化',
                },
                'scope' => 'cms_article_body_or_module_heading',
            ], $headingFinds),
            'link_replacements' => array_map(static fn (array $pair): array => [
                'find_href' => $pair[0],
                'replace_with_href' => $pair[1],
                'scope' => 'cms_article_body_or_internal_link',
            ], $linkPairs),
            'protected_fields' => [
                'slug' => 'unchanged',
                'canonical' => 'unchanged',
                'locale' => 'zh-CN unchanged',
                'publication_state' => 'unchanged',
                'schema' => 'hold_no_enablement',
                'hreflang' => 'hold_no_enablement',
                'sitemap_llms' => 'unchanged',
                'search_submission' => 'hold',
            ],
            'cms_write_allowed_now' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{path:string,sha256:string}
     */
    private function writeSourcePackage(array $artifact): array
    {
        $dir = $this->artifactDir();
        $path = $dir.'/source-package.json';
        File::put($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

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
            'articles' => DB::table('articles')->count(),
            'article_seo_meta' => DB::table('article_seo_meta')->count(),
            'article_translation_revisions' => DB::table('article_translation_revisions')->count(),
        ];
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-ops-zh-article-quality-repair-dry-run-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, Artisan::output());

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);
        $this->assertIsArray($decoded, $path);

        return $decoded;
    }
}
