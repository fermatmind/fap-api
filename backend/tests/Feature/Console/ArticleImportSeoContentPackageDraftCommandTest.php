<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ArticleImportSeoContentPackageDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'tg_article_career_interest_vs_personality_test_2026v1';

    public function test_dry_run_accepts_valid_bilingual_package_without_database_writes(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_create_draft', $payload['action']);
        $this->assertSame('passed', $payload['active_surface_guard_scan']['status']);
        $this->assertSame('passed', $payload['contract_integrity_scan']['status']);
        $this->assertCount(2, $payload['articles']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_accepts_valid_zh_only_package_without_english_files(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['manifest.json']['locale_scope'] = ['zh-CN'];
            $files['manifest.json']['translation_group_id'] = null;
            $files['manifest.json']['pages'] = array_values(array_filter(
                $files['manifest.json']['pages'],
                static fn (array $page): bool => (string) ($page['locale'] ?? '') === 'zh-CN'
            ));
            unset(
                $files['pages/en-career-interest-test-vs-personality-test.md'],
                $files['cms/CMS_FIELDS_en_career-interest-test-vs-personality-test.json'],
                $files['cms/CMS_IMPORT_DRAFT_en_career-interest-test-vs-personality-test.json']
            );
            $files['cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json']['meta_description_draft'] =
                $files['cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json']['meta_description'];
            unset($files['cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json']['meta_description']);
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--locales' => 'zh-CN',
            '--expected-en-slug' => '',
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('would_create_draft', $payload['action']);
        $this->assertSame('passed', $payload['active_surface_guard_scan']['status']);
        $this->assertSame('passed', $payload['contract_integrity_scan']['status']);
        $this->assertCount(1, $payload['articles']);
        $this->assertSame('zh-CN', $payload['articles'][0]['locale']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_internal_visible_zh_category_name(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json']['category_name'] = 'SEO Articles';
            $files['cms/CMS_IMPORT_DRAFT_zh-CN_career-interest-vs-personality-test-differences.json']['category_name'] = 'SEO Articles';
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'category_reader_facing_label_required');
        $this->assertErrorCode($payload, 'internal_visible_category_forbidden');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_snake_case_zh_category_name(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json']['category_name'] = 'career_exploration';
            $files['cms/CMS_IMPORT_DRAFT_zh-CN_career-interest-vs-personality-test-differences.json']['category_name'] = 'career_exploration';
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'category_reader_facing_label_required');
        $this->assertErrorCode($payload, 'internal_visible_category_forbidden');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_accepts_old_big_five_alias_key_with_canonical_value(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/ROUTE_ALIAS_CONTRACT.json']['known_aliases'] = [
                '/tests/big-five-personality-test' => '/tests/big-five-personality-test-ocean-model',
            ];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('passed', $payload['contract_integrity_scan']['status']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_old_big_five_path_in_page_body(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['pages/en-career-interest-test-vs-personality-test.md'] .= "\n\n[Old Big Five](/tests/big-five-personality-test)\n";
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'old_big_five_route_found_in_active_surface');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_old_big_five_path_in_page_frontmatter_active_link(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['pages/en-career-interest-test-vs-personality-test.md'] = str_replace(
                "canonical_url_draft: /en/articles/career-interest-test-vs-personality-test\n",
                "canonical_url_draft: /en/articles/career-interest-test-vs-personality-test\nrelated_test_url: /tests/big-five-personality-test\n",
                $files['pages/en-career-interest-test-vs-personality-test.md']
            );
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'old_big_five_route_found_in_active_surface');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_old_big_five_path_in_cms_import_cta_target(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['cms/CMS_IMPORT_DRAFT_en_career-interest-test-vs-personality-test.json']['secondary_hub_urls'][1] = '/tests/big-five-personality-test';
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'old_big_five_route_found_in_active_surface');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_old_big_five_alias_value(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/ROUTE_ALIAS_CONTRACT.json']['known_aliases'] = [
                '/tests/big-five-personality-test-ocean-model' => '/tests/big-five-personality-test',
            ];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'route_alias_contract_invalid');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_accepts_private_routes_in_private_url_guard_forbidden_paths(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/PRIVATE_URL_GUARD.json'] = [
                'forbidden_paths' => ['/result', '/results', '/orders', '/order', '/share', '/pay', '/payment', '/history', '/take'],
                'forbidden_query_keys' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
            ];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('passed', $payload['contract_integrity_scan']['status']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_private_url_in_page_body(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['pages/en-career-interest-test-vs-personality-test.md'] .= "\n\n[private](/results/abc123)\n";
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'private_route_found_in_active_surface');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_accepts_sensitive_keys_in_dynamic_cta_forbidden_tracking_params(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/DYNAMIC_CTA_CONTRACT.json']['forbidden_tracking_params'] = ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('passed', $payload['contract_integrity_scan']['status']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_sensitive_keys_in_dynamic_cta_allowed_tracking_params(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/DYNAMIC_CTA_CONTRACT.json']['allowed_tracking_params'] = ['utm_source', 'token'];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'dynamic_cta_forbidden_params_contract_invalid');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_does_not_scan_claim_gate_review_text_as_article_body(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['review/claim_gate.md'] = "forbidden_claims_avoided:\n- Do not link /results or token examples in public body.\n- Legacy route mention: /tests/big-five-personality-test\n";
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('passed', $payload['active_surface_guard_scan']['status']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_private_url_guard_contract_private_routes_outside_forbidden_context(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/PRIVATE_URL_GUARD.json']['allowed_paths'] = ['/result'];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'private_url_guard_contract_invalid');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_missing_social_image_metadata(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            foreach (['zh-CN_career-interest-vs-personality-test-differences', 'en_career-interest-test-vs-personality-test'] as $suffix) {
                $fieldsPath = 'cms/CMS_FIELDS_'.$suffix.'.json';
                $importPath = 'cms/CMS_IMPORT_DRAFT_'.$suffix.'.json';
                unset(
                    $files[$fieldsPath]['cover_media_asset_key'],
                    $files[$fieldsPath]['cover_image_url'],
                    $files[$fieldsPath]['og_image_url'],
                    $files[$fieldsPath]['social_image_metadata'],
                    $files[$importPath]['cover_media_asset_key'],
                    $files[$importPath]['cover_image_url'],
                    $files[$importPath]['og_image_url'],
                    $files[$importPath]['social_image_metadata']
                );
            }
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'missing_social_image_metadata');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_rejects_cms_media_placeholder_marker(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['cms/CMS_IMPORT_DRAFT_en_career-interest-test-vs-personality-test.json']['cover_image_url'] = '__CMS_MEDIA_LIBRARY_PLACEHOLDER__';
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'media_placeholder_found');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_accepts_cms_media_placeholder_marker_in_guard_contract_forbidden_list(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json']['forbidden'] = [
                '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
                'fake image URL',
            ];
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('passed', $payload['active_surface_guard_scan']['status']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_import_creates_draft_only_human_review_articles_without_publish_or_discoverability_release(): void
    {
        Http::fake();
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('created_draft', $payload['action']);
        $this->assertCount(2, $payload['articles']);

        foreach ($payload['articles'] as $articleResult) {
            $this->assertIsInt($articleResult['article_id']);
            $this->assertIsInt($articleResult['working_revision_id']);
            $this->assertStringStartsWith('/ops/article-preview/', $articleResult['preview_url_candidate']);

            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'seoMeta'])
                ->findOrFail((int) $articleResult['article_id']);

            $this->assertSame('draft', (string) $article->status);
            $this->assertFalse((bool) $article->is_public);
            $this->assertFalse((bool) $article->is_indexable);
            $this->assertFalse((bool) $article->sitemap_eligible);
            $this->assertFalse((bool) $article->llms_eligible);
            $this->assertNull($article->published_at);
            $this->assertNull($article->published_revision_id);
            $this->assertSame(self::TRANSLATION_GROUP_ID, (string) $article->translation_group_id);

            $this->assertInstanceOf(ArticleTranslationRevision::class, $article->workingRevision);
            $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $article->workingRevision->revision_status);
            $this->assertSame((int) $articleResult['working_revision_id'], (int) $article->workingRevision->id);

            $this->assertInstanceOf(ArticleSeoMeta::class, $article->seoMeta);
            $this->assertSame('noindex,nofollow', (string) $article->seoMeta->robots);
            $this->assertFalse((bool) $article->seoMeta->is_indexable);
            $this->assertTrue((bool) data_get($article->seoMeta->schema_json, 'editorial_package_v1.schema_hold'));
            $this->assertTrue((bool) data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_hold'));
            $this->assertFalse((bool) data_get($article->seoMeta->schema_json, 'editorial_package_v1.publish_allowed', true));
        }

        $this->assertSame(2, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'content_release_publish')->count());
        foreach (['seo_search_channel_queue_items', 'seo_search_channel_queue_batches', 'seo_search_channel_queue_events'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), $table.' should remain empty.');
            }
        }
        Http::assertNothingSent();
    }

    public function test_import_preserves_zh_utf8_frontmatter_meta_draft_fallback_without_corruption(): void
    {
        $metaTitle = '高考志愿要不要服从调剂？先看不能接受专业 | FermatMind';
        $metaDescription = '填院校专业组前，别先争服从调剂。先把全部专业分成不能接受、待验证、可接受。';
        $package = $this->writeModeCPackage(static function (array &$files) use ($metaTitle, $metaDescription): void {
            foreach ([
                'manifest.json',
                'cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json',
                'cms/CMS_IMPORT_DRAFT_zh-CN_career-interest-vs-personality-test-differences.json',
            ] as $path) {
                unset(
                    $files[$path]['meta_title'],
                    $files[$path]['meta_title_draft'],
                    $files[$path]['meta_description'],
                    $files[$path]['meta_description_draft']
                );
            }

            $files['pages/zh-CN-career-interest-vs-personality-test-differences.md'] = str_replace(
                "canonical_url_draft: /zh/articles/career-interest-vs-personality-test-differences\n",
                "canonical_url_draft: /zh/articles/career-interest-vs-personality-test-differences\nmeta_title_draft: {$metaTitle}\nmeta_description_draft: {$metaDescription}\n",
                $files['pages/zh-CN-career-interest-vs-personality-test-differences.md']
            );
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--locales' => 'zh-CN',
            '--expected-en-slug' => '',
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);

        $revision = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame($metaTitle, (string) $revision->seo_title);
        $this->assertSame($metaDescription, (string) $revision->seo_description);
        $this->assertTrue(mb_check_encoding((string) $revision->seo_title, 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $revision->seo_description, 'UTF-8'));
    }

    public function test_import_serializes_multilingual_heading_sequence_with_smart_and_fullwidth_punctuation(): void
    {
        $package = $this->writeModeCPackage(static function (array &$files): void {
            $files['pages/zh-CN-career-interest-vs-personality-test-differences.md'] = str_replace(
                "## Quick answer\n",
                "## 职业兴趣—性格“边界”：全角，标点\n\n## Quick answer\n",
                $files['pages/zh-CN-career-interest-vs-personality-test-differences.md']
            );
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);

        $importLog = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertStringContainsString(
            '职业兴趣—性格“边界”：全角，标点',
            implode("\n", $importLog->heading_sequence_json)
        );
    }

    public function test_dry_run_rejects_malformed_utf8_body_scalar_before_database_write(): void
    {
        $malformed = (string) hex2bin('c328');
        $package = $this->writeModeCPackage(static function (array &$files) use ($malformed): void {
            $files['pages/en-career-interest-test-vs-personality-test.md'] = str_replace(
                "## Quick answer\n",
                "## Broken {$malformed} heading\n\n## Quick answer\n",
                $files['pages/en-career-interest-test-vs-personality-test.md']
            );
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'invalid_utf8_scalar');
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_malformed_utf8_in_review_context_does_not_crash_import_audit_json(): void
    {
        $malformed = (string) hex2bin('c328');
        $package = $this->writeModeCPackage(static function (array &$files) use ($malformed): void {
            $files['review/claim_gate.md'] = "claim_gate_status: not_reviewed\nreview_note: {$malformed}\n";
        });

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(2, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_import_failure_rolls_back_all_draft_related_writes(): void
    {
        $package = $this->writeModeCPackage();

        Event::listen('eloquent.creating: '.ArticleEditorialPackageImport::class, static function (): void {
            throw new RuntimeException('forced import audit failure');
        });

        try {
            $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
                '--json' => true,
            ]));
        } finally {
            Event::forget('eloquent.creating: '.ArticleEditorialPackageImport::class);
        }

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_json_output_includes_article_ids_working_revision_ids_and_preview_url_candidates(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('articles:import-seo-content-package-draft', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);

        foreach ($payload['articles'] as $article) {
            $this->assertIsInt($article['article_id']);
            $this->assertGreaterThan(0, $article['article_id']);
            $this->assertIsInt($article['working_revision_id']);
            $this->assertGreaterThan(0, $article['working_revision_id']);
            $this->assertSame('/ops/article-preview/'.$article['article_id'], $article['preview_url_candidate']);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function commandOptions(string $package, array $overrides = []): array
    {
        return array_replace([
            '--package' => $package,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--locales' => 'zh-CN,en',
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--schema-hold' => true,
            '--hreflang-hold' => true,
            '--expected-zh-slug' => 'career-interest-vs-personality-test-differences',
            '--expected-en-slug' => 'career-interest-test-vs-personality-test',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertWarningCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $warning): string => (string) ($warning['code'] ?? ''),
            $payload['warnings'] ?? []
        ));
    }

    /**
     * @param  callable(array<string,mixed>&):void|null  $mutate
     */
    private function writeModeCPackage(?callable $mutate = null): string
    {
        $root = sys_get_temp_dir().'/fm-mode-c-package-'.Str::random(12);
        foreach (['pages', 'cms', 'contracts', 'review', 'codex'] as $directory) {
            mkdir($root.'/'.$directory, 0777, true);
        }

        $files = $this->modeCFiles();
        if ($mutate !== null) {
            $mutate($files);
        }

        foreach ($files as $relativePath => $contents) {
            $path = $root.'/'.$relativePath;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents(
                $path,
                is_array($contents)
                    ? json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $contents
            );
        }

        return $root;
    }

    /**
     * @return array<string,mixed>
     */
    private function modeCFiles(): array
    {
        $social = [
            'media_library_asset_key' => 'article.riasec.explanation.cover.v1',
            'media_library_status' => 'published',
            'is_public' => true,
            'cdn_status' => 'verified',
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationcoverv1/hero_1600x900.jpg',
            'hero_variant' => [
                'url' => 'https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationcoverv1/hero_1600x900.jpg',
                'width' => 1600,
                'height' => 900,
            ],
            'og_1200x630_variant' => [
                'url' => 'https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationcoverv1/og_1200x630.jpg',
                'width' => 1200,
                'height' => 630,
            ],
            'twitter_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationcoverv1/og_1200x630.jpg',
            'alt_text' => 'Career exploration notes with a RIASEC structure and decision path diagram',
            'width' => 1672,
            'height' => 941,
        ];
        $variants = [
            'hero' => ['url' => $social['hero_variant']['url'], 'width' => 1600, 'height' => 900],
            'og' => ['url' => $social['og_1200x630_variant']['url'], 'width' => 1200, 'height' => 630],
        ];
        $baseFields = [
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'status' => 'draft',
            'publish_allowed' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'claim_gate_status' => 'not_reviewed',
            'category_name' => '职业决策',
            'category_slug' => 'career-decision-making',
            'primary_keyword' => 'career interest test vs personality test',
            'secondary_keywords' => ['Holland Code vs MBTI', 'career assessment vs personality assessment'],
            'primary_hub_url' => '/tests/holland-career-interest-test-riasec',
            'secondary_hub_urls' => ['/tests/mbti-personality-test-16-personality-types', '/tests/big-five-personality-test-ocean-model'],
            'schema_eligibility' => ['article_schema' => 'review_required', 'faq_schema' => false, 'breadcrumb_schema' => 'review_required'],
            'cover_media_asset_key' => 'article.riasec.explanation.cover.v1',
            'cover_image_url' => $social['cover_image_url'],
            'cover_image_alt' => $social['alt_text'],
            'cover_image_width' => 1672,
            'cover_image_height' => 941,
            'cover_image_variants' => $variants,
            'og_image_url' => $social['og_1200x630_variant']['url'],
            'twitter_image_url' => $social['twitter_image_url'],
            'social_image_metadata' => $social,
        ];

        return [
            'manifest.json' => [
                'package_name' => 'career-interest-test-vs-personality-test',
                'status' => 'draft_only_not_for_publication',
                'translation_group_id' => self::TRANSLATION_GROUP_ID,
                'publish_allowed' => false,
                'schema_generation_allowed' => false,
                'hreflang_enablement_allowed' => false,
                'pages' => [
                    [
                        'locale' => 'zh-CN',
                        'title' => '职业兴趣测试与性格测试的区别：选专业、找工作该先做哪个？',
                        'slug' => 'career-interest-vs-personality-test-differences',
                        'canonical_url_draft' => '/zh/articles/career-interest-vs-personality-test-differences',
                        'meta_title_draft' => '职业兴趣测试与性格测试有什么区别？职业规划指南 | FermatMind',
                        'meta_description_draft' => '找工作应该测 MBTI 还是霍兰德？本文解释职业兴趣测试与性格测试的区别。',
                        'file' => 'pages/zh-CN-career-interest-vs-personality-test-differences.md',
                    ],
                    [
                        'locale' => 'en',
                        'title' => 'Career Interest Test vs Personality Test: Which Should You Take First?',
                        'slug' => 'career-interest-test-vs-personality-test',
                        'canonical_url_draft' => '/en/articles/career-interest-test-vs-personality-test',
                        'meta_title_draft' => 'Career Interest Test vs Personality Test: Which Should You Take?',
                        'meta_description_draft' => 'Learn how Holland Code, MBTI, and Big Five answer different career exploration questions.',
                        'file' => 'pages/en-career-interest-test-vs-personality-test.md',
                    ],
                ],
            ],
            'pages/zh-CN-career-interest-vs-personality-test-differences.md' => $this->markdownPage('zh-CN', '职业兴趣测试与性格测试的区别：选专业、找工作该先做哪个？', 'career-interest-vs-personality-test-differences', '/zh/articles/career-interest-vs-personality-test-differences'),
            'pages/en-career-interest-test-vs-personality-test.md' => $this->markdownPage('en', 'Career Interest Test vs Personality Test: Which Should You Take First?', 'career-interest-test-vs-personality-test', '/en/articles/career-interest-test-vs-personality-test'),
            'cms/CMS_FIELDS_zh-CN_career-interest-vs-personality-test-differences.json' => array_replace($baseFields, [
                'locale' => 'zh-CN',
                'title' => '职业兴趣测试与性格测试的区别：选专业、找工作该先做哪个？',
                'slug' => 'career-interest-vs-personality-test-differences',
                'canonical_url' => '/zh/articles/career-interest-vs-personality-test-differences',
                'meta_title' => '职业兴趣测试与性格测试有什么区别？职业规划指南 | FermatMind',
                'meta_description' => '找工作应该测 MBTI 还是霍兰德？本文解释职业兴趣测试与性格测试的区别。',
            ]),
            'cms/CMS_FIELDS_en_career-interest-test-vs-personality-test.json' => array_replace($baseFields, [
                'locale' => 'en',
                'category_name' => 'Career Decision-Making',
                'category_slug' => 'career-decision-making',
                'title' => 'Career Interest Test vs Personality Test: Which Should You Take First?',
                'slug' => 'career-interest-test-vs-personality-test',
                'canonical_url' => '/en/articles/career-interest-test-vs-personality-test',
                'meta_title' => 'Career Interest Test vs Personality Test: Which Should You Take?',
                'meta_description' => 'Learn how Holland Code, MBTI, and Big Five answer different career exploration questions.',
            ]),
            'cms/CMS_IMPORT_DRAFT_zh-CN_career-interest-vs-personality-test-differences.json' => array_replace($baseFields, [
                'locale' => 'zh-CN',
                'title' => '职业兴趣测试与性格测试的区别：选专业、找工作该先做哪个？',
                'slug' => 'career-interest-vs-personality-test-differences',
                'canonical_url' => '/zh/articles/career-interest-vs-personality-test-differences',
                'meta_title' => '职业兴趣测试与性格测试有什么区别？职业规划指南 | FermatMind',
                'meta_description' => '找工作应该测 MBTI 还是霍兰德？本文解释职业兴趣测试与性格测试的区别。',
                'body_markdown_file' => 'pages/zh-CN-career-interest-vs-personality-test-differences.md',
            ]),
            'cms/CMS_IMPORT_DRAFT_en_career-interest-test-vs-personality-test.json' => array_replace($baseFields, [
                'locale' => 'en',
                'category_name' => 'Career Decision-Making',
                'category_slug' => 'career-decision-making',
                'title' => 'Career Interest Test vs Personality Test: Which Should You Take First?',
                'slug' => 'career-interest-test-vs-personality-test',
                'canonical_url' => '/en/articles/career-interest-test-vs-personality-test',
                'meta_title' => 'Career Interest Test vs Personality Test: Which Should You Take?',
                'meta_description' => 'Learn how Holland Code, MBTI, and Big Five answer different career exploration questions.',
                'body_markdown_file' => 'pages/en-career-interest-test-vs-personality-test.md',
            ]),
            'contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json' => ['routes' => ['/zh/articles/career-interest-vs-personality-test-differences', '/en/articles/career-interest-test-vs-personality-test']],
            'contracts/ROUTE_ALIAS_CONTRACT.json' => [
                'known_alias_autofix_allowed' => true,
                'unknown_alias_requires_operator_input' => true,
                'known_aliases' => [
                    '/tests/big-five-personality-test' => '/tests/big-five-personality-test-ocean-model',
                ],
            ],
            'contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json' => ['asset_key' => 'article.riasec.explanation.cover.v1', 'required' => true],
            'contracts/DYNAMIC_CTA_CONTRACT.json' => [
                'primary' => '/tests/holland-career-interest-test-riasec',
                'secondary' => ['/tests/mbti-personality-test-16-personality-types', '/tests/big-five-personality-test-ocean-model'],
                'allowed_tracking_params' => ['utm_source', 'utm_medium', 'utm_campaign'],
                'forbidden_tracking_params' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
            ],
            'contracts/INTERNAL_LINK_PLAN.json' => ['links' => ['/tests/holland-career-interest-test-riasec', '/tests/big-five-personality-test-ocean-model']],
            'contracts/PRIVATE_URL_GUARD.json' => [
                'forbidden_paths' => ['/result', '/results', '/orders', '/order', '/share', '/pay', '/payment', '/history', '/take'],
                'forbidden_query_keys' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
            ],
            'review/claim_gate.md' => "claim_gate_status: not_reviewed\n",
            'review/operator_review.md' => "operator_review_required: true\n",
            'codex/qa_checklist.md' => "- no publish\n- no index\n",
        ];
    }

    private function markdownPage(string $locale, string $title, string $slug, string $canonical): string
    {
        return <<<MD
---
translation_group_id: {self::TRANSLATION_GROUP_ID}
locale: {$locale}
title: {$title}
slug: {$slug}
canonical_url_draft: {$canonical}
primary_keyword: career interest test vs personality test
secondary_keywords:
  - Holland Code vs MBTI
  - career assessment vs personality assessment
claim_gate_status: not_reviewed
publish_allowed: false
sitemap_eligible: false
llms_eligible: false
---

## Quick answer

Start with a career-interest test when the question is direction, then use MBTI and Big Five as supporting lenses.

## CTA

[Start RIASEC](/tests/holland-career-interest-test-riasec)
[Use Big Five](/tests/big-five-personality-test-ocean-model)

## FAQ

### Can a test decide my career?

No. It is only one input.
MD;
    }
}
