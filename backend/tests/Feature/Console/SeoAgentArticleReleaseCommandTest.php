<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SeoAgentArticleReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'tg_article_career_interest_vs_personality_test_2026v1';

    public function test_package_qa_reports_no_write_importer_evidence(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('seo-agent:article-release', $this->commandOptions($package, [
            '--stage' => 'package-qa',
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame('seo-agent-article-release-gate-report.v1', $payload['schema_version']);
        $this->assertSame('package-qa', $payload['stage']);
        $this->assertTrue($payload['ok']);
        $this->assertSame('passed', $payload['status']);
        $this->assertFalse($payload['write_allowed']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertSame('passed', $payload['stage_report']['importer_plan']['active_surface_guard_scan']['status'] ?? null);
        $this->assertSame('passed', $payload['stage_report']['importer_plan']['contract_integrity_scan']['status'] ?? null);
        $this->assertSame(2, $payload['stage_report']['importer_plan']['article_count']);
        $this->assertContains('no_cms_draft_creation', $payload['negative_guarantees']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_media_readiness_reports_cover_and_body_visual_fields(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('seo-agent:article-release', $this->commandOptions($package, [
            '--stage' => 'media-readiness',
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame('media-readiness', $payload['stage']);
        $this->assertSame('passed', $payload['status']);
        $this->assertCount(2, $payload['stage_report']['media_items']);
        foreach ($payload['stage_report']['media_items'] as $item) {
            $this->assertSame('article.riasec.explanation.cover.v1', $item['cover_media_asset_key']);
            $this->assertSame('article.riasec.explanation.body-visual.v1', $item['body_visual_asset_key']);
            $this->assertSame(
                'https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationbodyvisualv1/hero_1600x900.jpg',
                $item['body_visual_image_url']
            );
        }
    }

    public function test_cms_draft_dry_run_reports_body_visual_parity(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('seo-agent:article-release', $this->commandOptions($package, [
            '--stage' => 'cms-draft-dry-run',
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame('cms-draft-dry-run', $payload['stage']);
        $this->assertCount(2, $payload['stage_report']['body_visual_parity']);
        foreach ($payload['stage_report']['body_visual_parity'] as $item) {
            $this->assertSame('passed', $item['media_metadata_parity']['status'] ?? null);
            $this->assertSame('cover_image_variants.editorial_package_v1', $item['media_metadata_parity']['metadata_path'] ?? null);
        }
    }

    public function test_preview_qa_blocks_until_draft_preview_candidates_exist(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('seo-agent:article-release', $this->commandOptions($package, [
            '--stage' => 'preview-qa',
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked_external_draft_required', $payload['status']);
        $this->assertContains('ops_article_preview_html', $payload['external_evidence_required']);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_invalid_stage_is_rejected_before_any_write(): void
    {
        $package = $this->writeModeCPackage();

        $exitCode = Artisan::call('seo-agent:article-release', $this->commandOptions($package, [
            '--stage' => 'publish-now',
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('invalid_stage', $payload['errors'][0]['code'] ?? null);
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    /**
     * @return array<string,mixed>
     */
    private function commandOptions(string $package, array $overrides = []): array
    {
        return array_replace([
            '--package' => $package,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--locales' => 'zh-CN,en',
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
     * @param  callable(array<string,mixed>&):void|null  $mutate
     */
    private function writeModeCPackage(?callable $mutate = null): string
    {
        $root = sys_get_temp_dir().'/fm-seo-agent-release-package-'.Str::random(12);
        foreach (['brief', 'pages', 'cms', 'contracts', 'review', 'codex', 'media', 'observation'] as $directory) {
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
            'body_visual_asset_key' => 'article.riasec.explanation.body-visual.v1',
            'body_visual_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationbodyvisualv1/hero_1600x900.jpg',
            'body_visual_fallback_authorized' => false,
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
            'brief/SEO_BRIEF.md' => "# Brief\n",
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
            'media/IMAGE_ASSET_MANIFEST.json' => ['assets' => ['article.riasec.explanation.cover.v1', 'article.riasec.explanation.body-visual.v1']],
            'observation/D1_D7_D14.md' => "# Observation\n",
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
