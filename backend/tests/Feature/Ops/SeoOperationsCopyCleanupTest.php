<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Tests\TestCase;

final class SeoOperationsCopyCleanupTest extends TestCase
{
    public function test_seo_workspace_omits_redundant_operational_descriptions(): void
    {
        $page = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));
        $workbench = (string) file_get_contents(resource_path('views/filament/ops/components/ops-seo-workbench-workspace.blade.php'));
        $technical = (string) file_get_contents(resource_path('views/filament/ops/components/ops-technical-health-workspace.blade.php'));
        $content = (string) file_get_contents(resource_path('views/filament/ops/components/ops-content-publishing-workspace.blade.php'));
        $experiments = (string) file_get_contents(resource_path('views/filament/ops/components/ops-experiment-ledger-workspace.blade.php'));

        foreach ([
            'seo_operations.description',
            'platform.description',
            'performance.description',
            'opportunities.description',
            'platform.page_family',
        ] as $copyKey) {
            $this->assertStringNotContainsString($copyKey, $page);
        }

        foreach ([
            '.trend.description',
            '.trend.hold',
            '.decisions.description',
            '.decisions.hold_description',
            '.health.description',
            '.inspector.description',
            '.inspector.hold',
        ] as $copyKey) {
            $this->assertStringNotContainsString($copyKey, $workbench);
        }

        foreach ([
            '.reliability.description',
            '.clusters.description',
            '.clusters.hold_description',
            '.evidence.description',
            '.samples.description',
            '.samples.hold',
        ] as $copyKey) {
            $this->assertStringNotContainsString($copyKey, $technical);
        }

        foreach ([
            ".description')",
            '.authority.description',
            '.editor.description',
            '.preview.description',
            '.preview.hold',
            '.release.description',
            '.hold_description',
            '.release.lastmod_rule',
        ] as $copyKey) {
            $this->assertStringNotContainsString($copyKey, $content);
        }

        foreach ([
            ".description')",
            '.hold_description',
            '.inspector_description',
            '.causality_note',
        ] as $copyKey) {
            $this->assertStringNotContainsString($copyKey, $experiments);
        }

        $this->assertStringContainsString(':description="\'\'"', $page);
        $this->assertStringContainsString(':description="\'\'"', $workbench);
        $this->assertStringContainsString(':description="\'\'"', $technical);
        $this->assertStringContainsString(':description="\'\'"', $content);
        $this->assertStringContainsString(':description="\'\'"', $experiments);

        $stateMessage = (string) file_get_contents(resource_path('views/filament/ops/components/ops-state-message.blade.php'));
        $notConnected = (string) file_get_contents(resource_path('views/filament/ops/components/ops-not-connected.blade.php'));

        $this->assertStringContainsString('@if (filled($displayDescription))', $stateMessage);
        $this->assertStringContainsString('@if (filled($description))', $notConnected);
    }
}
