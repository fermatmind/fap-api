<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\LocalizeOpsUiResponse;
use App\Http\Middleware\SetOpsLocale;
use App\Support\OpsI18n\OpsUiText;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class OpsUiLocalizationResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', SetOpsLocale::class, LocalizeOpsUiResponse::class])
            ->get('/ops/test-ui-localization', static fn () => response(
                '<main><h1>Delivery tools</h1><label>Order number</label><button>Request</button><p>Permission boundary</p></main>'
            ));

        Route::middleware(['web', SetOpsLocale::class, LocalizeOpsUiResponse::class])
            ->get('/ops/test-ui-localization-json', static fn () => response()->json([
                'html' => '<span>Content workspace</span><span>Taxonomy</span>',
                'label' => 'Release workspace',
            ]));
    }

    public function test_explicit_zh_ops_mode_localizes_hardcoded_html_ui_text(): void
    {
        $this->withSession([
            SetOpsLocale::SESSION_KEY => 'zh_CN',
            SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ])
            ->get('/ops/test-ui-localization')
            ->assertOk()
            ->assertSee('交付工具', false)
            ->assertSee('订单号', false)
            ->assertSee('请求', false)
            ->assertSee('权限边界', false)
            ->assertDontSee('Delivery tools', false)
            ->assertDontSee('Order number', false);
    }

    public function test_non_explicit_locale_keeps_legacy_test_surface_stable(): void
    {
        $this->get('/ops/test-ui-localization')
            ->assertOk()
            ->assertSee('Delivery tools', false)
            ->assertSee('Order number', false);
    }

    public function test_explicit_zh_ops_mode_localizes_livewire_style_json_fragments(): void
    {
        $this->withSession([
            SetOpsLocale::SESSION_KEY => 'zh_CN',
            SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ])
            ->get('/ops/test-ui-localization-json')
            ->assertOk()
            ->assertJsonPath('html', '<span>内容工作台</span><span>分类体系</span>')
            ->assertJsonPath('label', '发布工作区');
    }

    public function test_core_ops_page_strings_have_zh_mappings(): void
    {
        app()->setLocale('zh_CN');

        foreach ([
            'Delivery tools',
            'Content workspace',
            'Content release',
            'SEO operations',
            'Question Option Distribution Table',
            'Quality Flags Breakdown',
            'Post-release observability',
            'Global search',
            'Order Lookup',
            'Secure link',
        ] as $text) {
            $this->assertNotSame($text, OpsUiText::translate($text), $text);
        }
    }

    public function test_ops_blade_ui_literals_are_covered_by_zh_mapping_or_allowlist(): void
    {
        app()->setLocale('zh_CN');

        $translations = OpsUiText::translations();
        $missing = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/filament/ops')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            preg_match_all('/\b(?:title|description|eyebrow|label|placeholder|empty-title|empty-description|empty-eyebrow)="([^"]*[A-Za-z][^"]*)"/', $contents, $attributeMatches);
            preg_match_all('/>\s*([A-Z][A-Za-z0-9 &\/:.,;()\'’\-+]+?)\s*</', $contents, $textMatches);

            foreach ([...$attributeMatches[1], ...$textMatches[1]] as $text) {
                $text = trim((string) $text);

                if (OpsUiText::isAllowedToken($text) || array_key_exists($text, $translations)) {
                    continue;
                }

                $missing[] = $path.' :: '.$text;
            }
        }

        $this->assertSame([], $missing);
    }
}
