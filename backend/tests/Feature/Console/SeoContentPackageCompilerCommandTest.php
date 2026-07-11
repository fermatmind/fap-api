<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SeoContentPackageCompilerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_compiles_one_deterministic_importer_ready_package_without_touching_source(): void
    {
        $source = $this->package();
        $sourceHash = hash_file('sha256', $source.'/pages/zh-CN/article.md');
        $output = $source.'-derived';

        $exitCode = Artisan::call('seo-agent:compile-mode-c-package', [
            '--package' => $source,
            '--output-dir' => $output,
            '--locales' => 'zh-CN,en',
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $this->assertFileExists($output.'/cms/CMS_IMPORT_DRAFT_zh-CN_demo-topic.json');
        $this->assertFileExists($output.'/cms/CMS_FIELDS_en_demo-topic.json');
        $this->assertFileExists($output.'/contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json');
        $this->assertFileExists($output.'/PACKAGE_COMPILATION_REPORT.json');
        $this->assertSame($sourceHash, hash_file('sha256', $source.'/pages/zh-CN/article.md'));

        $first = json_decode((string) file_get_contents($output.'/PACKAGE_COMPILATION_REPORT.json'), true);
        $this->assertSame('FINAL_DERIVED_IMPORT_READY_PACKAGE', $first['status']);

        $exitCode = Artisan::call('seo-agent:compile-mode-c-package', [
            '--package' => $source,
            '--output-dir' => $output,
            '--locales' => 'zh-CN,en',
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());
        $second = json_decode((string) file_get_contents($output.'/PACKAGE_COMPILATION_REPORT.json'), true);
        $this->assertSame($first['derived_sha256'], $second['derived_sha256']);

        $draft = json_decode((string) file_get_contents($output.'/cms/CMS_IMPORT_DRAFT_en_demo-topic.json'), true);
        $this->assertSame('Demo title', $draft['meta_title']);
        $this->assertSame('/en/articles/demo-topic', $draft['canonical_path']);
        $this->assertTrue($draft['schema_hold']);
        $this->assertFalse($draft['schema_eligibility']['article_schema']);

        $media = json_decode((string) file_get_contents($output.'/media/IMAGE_ASSET_MANIFEST.json'), true);
        $this->assertSame(['width' => 1600, 'height' => 900, 'exact' => false], $media['assets'][0]['dimensions_expected']);
        $this->assertFalse($media['assets'][0]['provenance']['competitor_asset']);
    }

    public function test_dry_run_and_ambiguous_inputs_fail_closed_without_output(): void
    {
        $source = $this->package();
        $output = $source.'-dry';
        $exitCode = Artisan::call('seo-agent:compile-mode-c-package', [
            '--package' => $source,
            '--output-dir' => $output,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertDirectoryDoesNotExist($output);

        $manifest = json_decode((string) file_get_contents($source.'/manifest.json'), true);
        $manifest['translation_group_id'] = str_repeat('x', 65);
        file_put_contents($source.'/manifest.json', json_encode($manifest));
        $this->assertSame(1, Artisan::call('seo-agent:compile-mode-c-package', [
            '--package' => $source,
            '--output-dir' => $output,
            '--json' => true,
        ]));
        $this->assertDirectoryDoesNotExist($output);
    }

    private function package(): string
    {
        $root = sys_get_temp_dir().'/seo-mode-c-compiler-'.bin2hex(random_bytes(5));
        foreach (['pages/zh-CN', 'pages/en', 'cms', 'contracts', 'review', 'codex', 'media'] as $directory) {
            mkdir($root.'/'.$directory, 0775, true);
        }
        file_put_contents($root.'/manifest.json', json_encode([
            'package_id' => 'demo',
            'operation_type' => 'new_article',
            'translation_group_id' => 'tg_demo_2026v1',
            'locales' => ['zh-CN', 'en'],
        ]));
        file_put_contents($root.'/pages/zh-CN/article.md', "# 示例\n\n正文。\n");
        file_put_contents($root.'/pages/en/article.md', "# Demo\n\nBody.\n");
        foreach (['zh-CN', 'en'] as $locale) {
            $canonical = '/'.($locale === 'zh-CN' ? 'zh' : 'en').'/articles/demo-topic';
            $base = [
                'locale' => $locale,
                'slug' => 'demo-topic',
                'translation_group_id' => 'tg_demo_2026v1',
                'title' => $locale === 'zh-CN' ? '示例文章标题' : 'Demo Article Title',
                'excerpt' => $locale === 'zh-CN' ? '这是用于确定性编译测试的安全摘要。' : 'A safe excerpt for deterministic compiler coverage.',
                'seo_title' => 'Demo title',
                'seo_description' => 'A bounded description for the daily package compiler test.',
                'canonical_url' => $canonical,
                'category_suggestion' => $locale === 'zh-CN' ? '职业探索' : 'Career Exploration',
                'category_slug' => 'career-exploration',
                'publish_allowed' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'claim_gate_status' => 'not_reviewed',
                'primary_keyword' => 'demo topic',
                'secondary_keywords' => ['demo'],
                'primary_hub_url' => '/'.($locale === 'zh-CN' ? 'zh' : 'en').'/tests/demo',
                'secondary_hub_urls' => [],
                'cover_media_asset_key' => 'article.demo.cover.v1',
                'cover_image_url' => 'https://assets.fermatmind.com/storage/demo-cover.jpg',
                'cover_image_alt' => 'Demo cover',
                'cover_image_width' => 1600,
                'cover_image_height' => 900,
                'cover_image_variants' => ['hero' => ['url' => 'https://assets.fermatmind.com/storage/demo-cover.jpg', 'width' => 1600, 'height' => 900]],
                'body_visual_asset_key' => 'article.demo.body.v1',
                'body_visual_image_url' => 'https://assets.fermatmind.com/storage/demo-body.jpg',
                'body_visual_fallback_authorized' => false,
                'og_image_url' => 'https://assets.fermatmind.com/storage/demo-og.jpg',
                'twitter_image_url' => 'https://assets.fermatmind.com/storage/demo-og.jpg',
                'social_image_metadata' => [
                    'media_library_asset_key' => 'article.demo.cover.v1',
                    'cover_image_url' => 'https://assets.fermatmind.com/storage/demo-cover.jpg',
                    'og_1200x630_variant' => ['url' => 'https://assets.fermatmind.com/storage/demo-og.jpg', 'width' => 1200, 'height' => 630],
                    'alt_text' => 'Demo cover',
                    'width' => 1600,
                    'height' => 900,
                ],
                'cta_slots' => ['primary' => ['href' => '/'.($locale === 'zh-CN' ? 'zh' : 'en').'/tests/demo', 'label' => 'Start']],
            ];
            file_put_contents($root.'/cms/CMS_IMPORT_DRAFT_'.$locale.'.json', json_encode($base));
            file_put_contents($root.'/cms/CMS_FIELDS_'.$locale.'.json', json_encode($base));
        }
        file_put_contents($root.'/contracts/DYNAMIC_CTA_CONTRACT.json', json_encode(['primary' => '/zh/tests/demo']));
        file_put_contents($root.'/contracts/INTERNAL_LINK_PLAN.json', json_encode(['links' => ['/zh/tests/demo']]));
        file_put_contents($root.'/review/claim_gate.md', "status: review_required\n");
        file_put_contents($root.'/review/operator_review.md', "required: true\n");
        file_put_contents($root.'/media/IMAGE_ASSET_MANIFEST.json', json_encode(['assets' => [[
            'asset_key' => 'article.demo.cover.v1',
            'alt_text' => 'Demo cover',
            'dimensions_expected' => '1600x900',
            'provenance' => 'GPT image generation',
        ]]]));

        return $root;
    }
}
