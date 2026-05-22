<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsSeoCrawlerObservationUiTest extends TestCase
{
    #[Test]
    public function crawler_observation_ui_renders_safe_aggregate_surface_only(): void
    {
        $view = strtolower((string) file_get_contents(resource_path('views/filament/ops/pages/seo-dashboard-access.blade.php')));

        foreach ([
            'crawler observation overview',
            'seo_crawler_log_daily_aggregates',
            'bot_family',
            'surface_family',
            'route_family',
            'http_status',
            'query_risk_state',
            'private_path_blocked',
            'hit_count',
            'last_seen_at',
            'no raw logs',
            'no scheduler',
            'search submission actions',
        ] as $required) {
            $this->assertStringContainsString($required, $view);
        }

        foreach ([
            'path_hash',
            'idempotency_key',
            'raw_user_agent',
            'raw_request_uri',
            'metadata_json',
            'attributes_json',
            'event_payload',
            '<iframe',
            '<x-filament::button',
            '<button',
            'wire:click',
            'approvequeueitem',
            'retryqueueitem',
            'submitqueueitem',
            'crawlerlogaggregatestoragewriter',
            'searchchannelqueuewriteservice',
            'searchchannelsubmissionexecutor',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }

    #[Test]
    public function docs_and_artifact_lock_crawler_observation_ui_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-ops-observation-ui.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/crawler-log-ops-observation-ui.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'crawler-log-10',
            'ops seo crawler observation ui',
            'seo_crawler_log_daily_aggregates',
            'crawler_safety_counters',
            'recent_safe_aggregate_rows',
            'no action buttons',
            'no raw log read',
            'no raw persistence',
            'no search submission',
            'next task: `crawler-log-11`',
            '"next_task": "crawler-log-11"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }

        foreach ([
            '"no_action_buttons": false',
            '"no_raw_log_read": false',
            '"no_search_submission": false',
            '"no_metabase_iframe": false',
            '"no_metabase_proxy": false',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
    }
}
