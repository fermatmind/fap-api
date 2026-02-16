<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class TemplateLintInPackTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = storage_path('framework/testing/content_lint_' . uniqid('', true));
        $packDir = $this->tmpRoot . '/default/CN_MAINLAND/zh-CN/TEMPLATE-LINT-v1';
        File::ensureDirectoryExists($packDir);

        file_put_contents($packDir . '/manifest.json', json_encode([
            'schema_version' => 'pack-manifest@v1',
            'pack_id' => 'TEST.template.lint.v1',
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => 'v1',
            'assets' => [
                'cards' => ['report_cards_traits.json'],
                'policies' => ['report_section_policies.json'],
            ],
            'fallback' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        file_put_contents($packDir . '/report_section_policies.json', json_encode([
            'schema' => 'fap.report.section_policies.v1',
            'items' => [
                'traits' => [
                    'target_cards' => 1,
                    'min_cards' => 1,
                    'max_cards' => 1,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        file_put_contents($packDir . '/report_cards_traits.json', json_encode([
            'schema' => 'fap.report.cards.v1',
            'items' => [
                [
                    'id' => 'traits_1',
                    'section' => 'traits',
                    'title' => 'Hello {{unknown_tpl_var}}',
                    'desc' => 'Desc',
                    'bullets' => ['B1'],
                    'tips' => ['T1'],
                    'tags' => ['kind:core'],
                    'priority' => 10,
                    'access_level' => 'preview',
                    'module_code' => 'core_full',
                ],
            ],
            'rules' => [
                'min_cards' => 1,
                'target_cards' => 1,
                'max_cards' => 1,
                'fallback_tags' => ['kind:core'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        config()->set('content_packs.root', $this->tmpRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function test_content_lint_fails_on_unknown_template_variable(): void
    {
        $this->artisan('content:lint --all')
            ->expectsOutputToContain('[FAIL] TEST.template.lint.v1')
            ->expectsOutputToContain('unknown_tpl_var')
            ->assertExitCode(1);
    }
}
