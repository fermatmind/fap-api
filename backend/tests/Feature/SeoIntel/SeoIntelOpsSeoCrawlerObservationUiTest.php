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
        $page = strtolower((string) file_get_contents(app_path('Filament/Ops/Pages/SeoOperationsPage.php')));
        $view = strtolower((string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php')));
        $composition = strtolower((string) file_get_contents(app_path('Services/Ops/SeoOperationsReadService.php')));
        $crawlerReadModel = strtolower((string) file_get_contents(app_path('Services/SeoIntel/OpsDashboard/SeoCrawlerLogObservationReadService.php')));

        $this->assertStringContainsString("protected static ?string \$slug = 'seo-operations';", $page);
        $this->assertStringContainsString('use app\\services\\ops\\seooperationsreadservice;', $page);
        $this->assertStringContainsString('app(seooperationsreadservice::class)', $page);
        $this->assertStringContainsString('use app\\services\\seointel\\opsdashboard\\seocrawlerlogobservationreadservice;', $composition);
        $this->assertSame(2, substr_count($composition, 'new seocrawlerlogobservationreadservice'));
        $this->assertStringContainsString("'search_submission_allowed' => false", $composition);

        preg_match_all("/data_get\\(\\\$platformoverview, 'crawler\\.([^']+)'\\)/", $view, $crawlerFields);
        $this->assertSame([], array_values(array_unique($crawlerFields[1])));
        $this->assertStringContainsString('<x-filament-ops::ops-technical-health-workspace', $view);

        foreach ([
            'seo_crawler_log_daily_aggregates',
            'total_count',
            'total_hits',
            'aggregates',
            'safety_counts',
            'bot_family',
            'surface_family',
            'route_family',
            'http_status',
            'query_risk_state',
            'private_path_blocked',
            'hit_count',
            'last_seen_at',
        ] as $required) {
            $this->assertStringContainsString($required, $crawlerReadModel);
        }

        foreach ([
            'path_hash',
            'query_hash',
            'session_id_hash',
            'evidence_hash',
            'idempotency_key',
            'raw_user_agent',
            'raw_request_uri',
            'raw_uri',
            'metadata_json',
            'attributes_json',
            'event_payload',
            'raw_payload',
            'original_payload',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $crawlerReadModel);
        }

        $privateFields = array_fill_keys([
            'canonical_url_hash', 'query_hash', 'evidence_hash', 'evidence_fingerprint', 'session_id_hash',
        ], 'must-not-leak');
        $reader = app(\App\Services\Ops\SeoOperationsReadService::class);
        $sanitize = new \ReflectionMethod($reader, 'sanitize');
        $this->assertSame(
            ['total_count' => 1, 'rows' => [['hit_count' => 2]]],
            $sanitize->invoke($reader, $privateFields + ['total_count' => 1, 'rows' => [$privateFields + ['hit_count' => 2]]]),
        );

        $crawlerBoundary = $crawlerReadModel."\n".$composition;
        foreach ([
            'approvequeueitem',
            'retryqueueitem',
            'submitqueueitem',
            'crawlerlogaggregatestoragewriter',
            'searchchannelqueuewriteservice',
            'searchchannelsubmissionexecutor',
            'searchchannelsubmissionservice',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $crawlerBoundary);
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
