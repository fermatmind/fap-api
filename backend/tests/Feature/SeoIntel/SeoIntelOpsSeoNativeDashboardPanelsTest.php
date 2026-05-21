<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsSeoNativeDashboardPanelsTest extends TestCase
{
    #[Test]
    public function blade_renders_issue_and_queue_detail_panels_with_safe_columns_only(): void
    {
        $view = strtolower((string) file_get_contents(resource_path('views/filament/ops/pages/seo-dashboard-access.blade.php')));

        foreach ([
            'issue queue detail panel',
            'search channel queue detail panel',
            'canonical path',
            'locale',
            'page_entity_type',
            'issue_type',
            'severity',
            'source_system',
            'source_engine',
            'status',
            'lifecycle_state',
            'detected_at',
            'updated_at',
            'source_authority',
            'channel',
            'eligibility_state',
            'approval_state',
            'execution_state',
            'indexability_state',
            'claim_boundary_state',
            'private_flow',
            'event_type summary',
        ] as $required) {
            $this->assertStringContainsString($required, $view);
        }

        foreach ([
            'metadata_json',
            'attributes_json',
            'event_payload',
            'reason_codes',
            '<iframe',
            '<x-filament::button',
            '<button',
            'wire:click',
            'approvequeueitem',
            'retryqueueitem',
            'submitqueueitem',
            'submitsearchchannel',
            'searchchannelqueuewriteservice',
            'searchchannelqueuesubmission',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }

    #[Test]
    public function docs_and_artifact_lock_detail_panel_boundaries(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-seo-native-dashboard-panels.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-seo-native-dashboard-panels.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'ops-seo-native-dash-03',
            'issue queue detail panel',
            'search channel queue detail panel',
            'read-only filter dimensions',
            'no payload drilldown',
            'no approve/retry/submit buttons',
            'no mutation controls',
            'no raw json',
            'next task: `ops-seo-native-dash-04`',
            '"next_task": "ops-seo-native-dash-04"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }

        foreach ([
            '"event_payload_displayed": true',
            '"reason_codes_displayed": true',
            '"mutation_controls_added": true',
            '"metabase_exposure_added": true',
            '"external_api_call_added": true',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
    }
}
