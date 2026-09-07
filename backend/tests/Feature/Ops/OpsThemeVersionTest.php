<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\OpsTheme;
use Filament\PanelRegistry;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Tests\TestCase;

final class OpsThemeVersionTest extends TestCase
{
    public function test_panel_renders_only_the_ops_theme_with_its_content_version(): void
    {
        $cssVersion = Css::make('forms')->package('filament/forms')->getVersion();
        $jsVersion = Js::make('app')->package('filament/filament')->getVersion();
        $appVersion = FilamentAsset::getAppVersion();
        $theme = app(PanelRegistry::class)->get('ops')->getTheme();
        $path = resource_path('css/filament/ops/theme.compiled.css');
        $this->assertInstanceOf(OpsTheme::class, $theme);
        $this->assertSame(hash_file('sha256', $path), $theme->getVersion());
        $this->assertSame(asset('css/app/ops-theme.css').'?v='.hash_file('sha256', $path), $theme->getHref());
        $this->assertStringContainsString('data-navigate-track', $theme->getHtml()->toHtml());
        $this->get('/ops/login')->assertOk()->assertSee($theme->getHref(), false);
        $this->assertSame($cssVersion, Css::make('forms')->package('filament/forms')->getVersion());
        $this->assertSame($jsVersion, Js::make('app')->package('filament/filament')->getVersion());
        $this->assertSame($appVersion, FilamentAsset::getAppVersion());
        $this->assertSame($path, FilamentAsset::getTheme('ops-theme')->getPath());
        $this->assertSame(public_path('css/app/ops-theme.css'), $theme->getPublicPath());
    }

    public function test_versions_follow_content_and_rollback_but_are_memoized_per_instance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ops-theme-test-');
        try {
            file_put_contents($path, 'body{color:red}');
            $first = OpsTheme::make('ops-theme', $path)->package('app');
            $version = $first->getVersion();
            $this->assertSame(hash('sha256', 'body{color:red}'), $version);
            $this->assertSame($version, OpsTheme::make('ops-theme', $path)->getVersion());
            file_put_contents($path, 'body{color:blue}');
            $this->assertSame($version, $first->getVersion());
            $this->assertSame(hash('sha256', 'body{color:blue}'), OpsTheme::make('ops-theme', $path)->getVersion());
            file_put_contents($path, 'body{color:red}');
            $this->assertSame($version, OpsTheme::make('ops-theme', $path)->getVersion());
        } finally {
            unlink($path);
        }
    }

    public function test_missing_or_unreadable_source_preserves_filament_fallback(): void
    {
        $fallback = Theme::make('ops-theme')->package('app')->getVersion();
        $this->assertSame($fallback, OpsTheme::make('ops-theme')->package('app')->getVersion());
        $path = tempnam(sys_get_temp_dir(), 'ops-theme-test-');
        try {
            chmod($path, 0000);
            clearstatcache(true, $path);
            if (! is_readable($path)) {
                $this->assertSame($fallback, OpsTheme::make('ops-theme', $path)->package('app')->getVersion());
            }
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
        $missing = OpsTheme::make('ops-theme', $path)->package('app');
        $this->assertSame($fallback, $missing->getVersion());
        $this->assertSame(asset('css/app/ops-theme.css').'?v='.$fallback, $missing->getHref());
    }
}
