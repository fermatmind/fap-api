<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Support\Str;
use Tests\TestCase;

final class OpsProductionSurfaceMatrixTest extends TestCase
{
    public function test_all_production_resources_and_pages_remain_in_the_visual_migration_matrix(): void
    {
        $resourceFiles = glob(app_path('Filament/Ops/Resources/*Resource.php')) ?: [];
        $pageFiles = glob(app_path('Filament/Ops/Pages/*.php')) ?: [];

        $this->assertCount(33, $resourceFiles, 'The production Resource inventory changed.');
        $this->assertCount(35, $pageFiles, 'The production Page inventory changed.');

        foreach ($resourceFiles as $resourceFile) {
            $source = (string) file_get_contents($resourceFile);

            $this->assertStringContainsString('function table(', $source, basename($resourceFile));
        }

        foreach ($pageFiles as $pageFile) {
            $source = (string) file_get_contents($pageFile);

            if (! preg_match('/protected static string \$view = \'([^\']+)\'/', $source, $matches)) {
                continue;
            }

            $viewPath = resource_path('views/'.Str::replace('.', '/', $matches[1]).'.blade.php');
            $this->assertFileExists($viewPath, basename($pageFile));
        }
    }

    public function test_production_panel_is_bound_to_the_shared_context_shell(): void
    {
        $topbar = (string) file_get_contents(
            resource_path('views/filament/ops/hooks/topbar-context.blade.php'),
        );

        $this->assertStringContainsString('<x-filament-ops::ops-context-bar', $topbar);
        $this->assertStringContainsString('title="Fermat Ops"', $topbar);
        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringContainsString('filament.ops.hooks.topbar-context', $provider);
    }
}
