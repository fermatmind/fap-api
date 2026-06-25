<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoOpsGaokaoV5CmsDraftGateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'tg_article_gaokao_major_choice_parent_conflict_riasec_2026v5';

    private const SLUG = 'gaokao-major-choice-parent-conflict-riasec-course-checklist';

    #[Test]
    public function dry_run_plans_one_gaokao_article_draft_without_database_writes(): void
    {
        $package = $this->writePackage();
        $sha = $this->packageSha256($package);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:gaokao-v5-cms-draft-gate', [
            '--package' => $package,
            '--confirm-package-sha256' => $sha,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--expected-zh-slug' => self::SLUG,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(1, data_get($summary, 'planned_write_count.article_drafts'));
        $this->assertSame('new_article_draft_only', data_get($summary, 'protected_diff_summary.operation_type'));
        $this->assertSame('no_change', data_get($summary, 'protected_diff_summary.slug'));
        $this->assertSame('hold_no_change', data_get($summary, 'protected_diff_summary.schema'));
        $this->assertSame('hold_no_change', data_get($summary, 'protected_diff_summary.search_submission'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'evidence.path'));
        $this->assertSame('seo-ops-gaokao-v5-cms-draft-gate.v1', $artifact['schema_version'] ?? null);
        $this->assertSame($sha, data_get($artifact, 'source_package.sha256'));
        $this->assertSame(self::SLUG, data_get($artifact, 'article_plans.0.slug'));
        $this->assertStringContainsString('no publish, no URL Truth', (string) ($artifact['required_confirmation_phrase'] ?? ''));
    }

    #[Test]
    public function dry_run_blocks_wrong_package_sha(): void
    {
        $package = $this->writePackage();

        $exitCode = Artisan::call('seo-ops:gaokao-v5-cms-draft-gate', [
            '--package' => $package,
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--expected-zh-slug' => self::SLUG,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('package_sha_mismatch', $summary['issues'] ?? []);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function dry_run_blocks_existing_article_authority_for_new_article_gate(): void
    {
        $this->createPublishedArticle();
        $package = $this->writePackage();
        $sha = $this->packageSha256($package);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:gaokao-v5-cms-draft-gate', [
            '--package' => $package,
            '--confirm-package-sha256' => $sha,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--expected-zh-slug' => self::SLUG,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('existing_article_authority_found_new_article_gate_blocked', $summary['issues'] ?? []);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function generated_contract_documents_gaokao_v5_draft_gate_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-ops-gaokao-v5-cms-draft-gate.v1.json'));

        $this->assertSame('seo-ops-gaokao-v5-cms-draft-gate.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-ops:gaokao-v5-cms-draft-gate', $contract['command'] ?? null);
        $this->assertSame('new_article_draft_only', data_get($contract, 'protected_diff.operation_type'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.indexnow_submit', true));
    }

    private function writePackage(): string
    {
        $root = sys_get_temp_dir().'/fm-gaokao-v5-package-'.Str::random(12);
        foreach (['pages', 'cms', 'contracts', 'review', 'codex'] as $directory) {
            File::ensureDirectoryExists($root.'/'.$directory);
        }

        foreach ($this->packageFiles() as $relativePath => $contents) {
            $path = $root.'/'.$relativePath;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, is_array($contents)
                ? json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $contents);
        }

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function packageFiles(): array
    {
        $social = [
            'media_library_asset_key' => 'article.gaokao.riasec.parent-conflict.cover.v1',
            'media_library_status' => 'published',
            'is_public' => true,
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/gaokao/hero_1600x900.jpg',
            'og_1200x630_variant' => [
                'url' => 'https://api.fermatmind.com/storage/media-library/variants/gaokao/og_1200x630.jpg',
                'width' => 1200,
                'height' => 630,
            ],
            'twitter_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/gaokao/og_1200x630.jpg',
            'alt_text' => '高考专业选择与霍兰德职业兴趣结构示意图',
            'width' => 1600,
            'height' => 900,
        ];
        $base = [
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'status' => 'draft',
            'publish_allowed' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'claim_gate_status' => 'not_reviewed',
            'category_name' => '高考志愿',
            'category_slug' => 'gaokao-major-choice',
            'title' => '孩子不听家长建议怎么选专业？用霍兰德兴趣做一次课程核对',
            'slug' => self::SLUG,
            'canonical_url' => '/zh/articles/'.self::SLUG,
            'meta_title' => '高考选专业亲子冲突：用霍兰德兴趣做课程核对',
            'meta_description' => '当孩子和家长在高考专业选择上意见不同，可以用霍兰德兴趣、课程偏好和信息核对表把讨论变得更具体。',
            'primary_keyword' => '高考选专业 家长 孩子 冲突',
            'secondary_keywords' => ['霍兰德职业兴趣测试', '高考志愿专业选择'],
            'primary_hub_url' => '/zh/tests/holland-career-interest-test-riasec',
            'secondary_hub_urls' => ['/zh/tests/mbti-personality-test-16-personality-types'],
            'schema_eligibility' => ['article_schema' => 'review_required', 'faq_schema' => false],
            'cover_media_asset_key' => 'article.gaokao.riasec.parent-conflict.cover.v1',
            'cover_image_url' => $social['cover_image_url'],
            'cover_image_alt' => $social['alt_text'],
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'cover_image_variants' => ['hero' => ['url' => $social['cover_image_url'], 'width' => 1600, 'height' => 900]],
            'og_image_url' => $social['og_1200x630_variant']['url'],
            'twitter_image_url' => $social['twitter_image_url'],
            'social_image_metadata' => $social,
        ];

        return [
            'manifest.json' => [
                'package_name' => 'gaokao-major-choice-parent-conflict-riasec-course-checklist',
                'translation_group_id' => self::TRANSLATION_GROUP_ID,
                'locale_scope' => ['zh-CN'],
                'publish_allowed' => false,
                'schema_hold' => true,
                'hreflang_hold' => true,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_hold' => true,
                'pages' => [[
                    'locale' => 'zh-CN',
                    'slug' => self::SLUG,
                    'canonical_url_draft' => '/zh/articles/'.self::SLUG,
                    'title' => $base['title'],
                    'file' => 'pages/zh-CN-'.self::SLUG.'.md',
                    'category_name' => '高考志愿',
                ]],
            ],
            'pages/zh-CN-'.self::SLUG.'.md' => $this->markdownPage(),
            'cms/CMS_FIELDS_zh-CN_'.self::SLUG.'.json' => $base,
            'cms/CMS_IMPORT_DRAFT_zh-CN_'.self::SLUG.'.json' => array_replace($base, [
                'body_markdown_file' => 'pages/zh-CN-'.self::SLUG.'.md',
            ]),
            'contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json' => ['canonical_path' => '/zh/articles/'.self::SLUG],
            'contracts/ROUTE_ALIAS_CONTRACT.json' => ['known_aliases' => []],
            'contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json' => ['asset_key' => 'article.gaokao.riasec.parent-conflict.cover.v1', 'required' => true],
            'contracts/DYNAMIC_CTA_CONTRACT.json' => [
                'primary' => '/zh/tests/holland-career-interest-test-riasec',
                'forbidden_tracking_params' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
            ],
            'contracts/INTERNAL_LINK_PLAN.json' => ['links' => ['/zh/tests/holland-career-interest-test-riasec']],
            'contracts/PRIVATE_URL_GUARD.json' => [
                'forbidden_paths' => ['/result', '/results', '/orders', '/order', '/share', '/pay', '/payment', '/history', '/take'],
                'forbidden_query_keys' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
            ],
            'review/claim_gate.md' => "claim_gate_status: not_reviewed\n",
            'review/operator_review.md' => "operator_review_required: true\n",
            'codex/qa_checklist.md' => "- no publish\n- no search\n",
        ];
    }

    private function markdownPage(): string
    {
        $translationGroupId = self::TRANSLATION_GROUP_ID;
        $slug = self::SLUG;

        return <<<MD
---
translation_group_id: {$translationGroupId}
locale: zh-CN
title: 孩子不听家长建议怎么选专业？用霍兰德兴趣做一次课程核对
slug: {$slug}
canonical_url_draft: /zh/articles/{$slug}
primary_keyword: 高考选专业 家长 孩子 冲突
claim_gate_status: not_reviewed
publish_allowed: false
sitemap_eligible: false
llms_eligible: false
---

## 先把争论改成信息核对

高考专业选择不应该靠一次测试决定，但可以用霍兰德兴趣把讨论拆成可核对的问题。

## 下一步

[做霍兰德职业兴趣测试](/zh/tests/holland-career-interest-test-riasec)
MD;
    }

    private function createPublishedArticle(): void
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => self::SLUG,
            'locale' => 'zh-CN',
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'source_locale' => 'zh-CN',
            'title' => 'Existing',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing markdown.',
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
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Existing',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing markdown.',
            'seo_title' => 'Existing | FermatMind',
            'seo_description' => 'Existing SEO description.',
            'published_at' => now()->subHour(),
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'article_seo_meta' => ArticleSeoMeta::query()->withoutGlobalScopes()->count(),
            'article_translation_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()->count(),
            'article_editorial_package_imports' => ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count(),
        ];
    }

    private function packageSha256(string $root): string
    {
        $files = collect(File::allFiles($root))
            ->filter(static fn (\SplFileInfo $file): bool => $file->isFile())
            ->map(static fn (\SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values();

        $hash = hash_init('sha256');
        foreach ($files as $path) {
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            hash_update($hash, $relative."\0".hash_file('sha256', $path)."\n");
        }

        return hash_final($hash);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-ops-gaokao-v5-cms-draft-gate-'.Str::random(12));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload, $path);

        return $payload;
    }
}
