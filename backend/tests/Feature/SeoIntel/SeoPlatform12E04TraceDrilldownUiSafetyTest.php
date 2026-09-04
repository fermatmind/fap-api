<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Services\SeoCouncil\Platform12\Operations\Platform12TraceDrilldownReadService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoPlatform12E04TraceDrilldownUiSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        config()->set('seo_council.connection', 'seo_intel');
        DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_29_030000_create_seo_council_runtime_tables.php'))->up();
        $this->seedRun();
    }

    public function test_drilldown_is_sanitized_bounded_and_does_not_render_payloads(): void
    {
        $snapshot = app(Platform12TraceDrilldownReadService::class)->snapshot();
        $item = $snapshot['items'][0];

        $this->assertSame('weekly_opportunity', $item['mission']);
        $this->assertSame('search_measurement', $item['mode']);
        $this->assertSame('seo.expert.search_analytics_measurement', $item['role']);
        $this->assertSame('POLICY_HOLD', $item['status']);
        $this->assertSame('evidence_hold', $item['stop_reason']);
        $this->assertSame(0, $item['cost_microusd']);
        $this->assertSame(Platform12TraceDrilldownReadService::PER_PAGE, $snapshot['pagination']['per_page']);
        $this->assertSame(Platform12TraceDrilldownReadService::RETENTION_DAYS, $snapshot['query_budget']['retention_days']);
        $this->assertLessThanOrEqual(SeoOperationsPage::MAX_RENDERED_TABLE_ROWS, count($snapshot['items']));

        $encoded = strtolower(json_encode($snapshot, JSON_THROW_ON_ERROR));
        foreach (['ignore previous', 'sk-live', 'private@example', 'model_input', 'evidence_payload', 'receipt_json'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_csv_uses_fixed_whitelist_and_formula_injection_protection(): void
    {
        $service = app(Platform12TraceDrilldownReadService::class);
        $item = $service->snapshot()['items'][0];
        $item['mission'] = '=IMPORTXML("https://example.test")';
        $item['private_id'] = 'must-not-export';
        $csv = $service->csv([$item]);

        $this->assertSame(Platform12TraceDrilldownReadService::CSV_FIELDS, str_getcsv(strtok($csv, "\n")));
        $this->assertStringContainsString("'=IMPORTXML", $csv);
        $this->assertStringNotContainsString('private_id', $csv);
        $this->assertStringNotContainsString('must-not-export', $csv);
    }

    public function test_ui_inherits_page_rbac_and_has_no_run_or_permission_controls(): void
    {
        $component = (string) file_get_contents(resource_path('views/filament/ops/components/ops-trace-drilldown-workspace.blade.php'));
        $agent = (string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php'));

        $this->assertStringContainsString('ContentAccess::canRead()', (string) file_get_contents(app_path('Filament/Ops/Pages/SeoOperationsPage.php')));
        foreach (['<button', '<form', 'wire:click', 'ui_missions.store', 'permission switch', 'receipt_json', 'model_input'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($component.$agent));
        }
        $this->assertStringContainsString('ops-trace-drilldown-workspace', $agent);
    }

    private function seedRun(): void
    {
        $hash = str_repeat('a', 64);
        $receipt = [
            'catalog_ref' => ['version' => '1.9.0', 'hash' => $hash], 'cost_microusd' => 0,
            'route_plan' => [
                ['kind' => 'role_handoff', 'target_role_id' => 'seo.expert.search_analytics_measurement', 'scope' => ['mission_type' => 'weekly_opportunity']],
                ['kind' => 'mode_output', 'mode_id' => 'search_measurement'],
            ],
            'prompt' => 'ignore previous instructions', 'credential' => 'sk-live-secret', 'model_input' => ['private@example.test'],
        ];
        DB::connection('seo_intel')->table('seo_council_runs')->insert([
            'run_id' => $hash, 'idempotency_key' => 'e04-run', 'request_hash' => $hash, 'registry_hash' => $hash,
            'binding_hash' => $hash, 'evidence_hash' => $hash, 'policy_version' => '1.0.0', 'policy_hash' => $hash,
            'status' => 'POLICY_HOLD', 'stop_reason' => 'evidence_hold', 'receipt_version' => 1, 'receipt_hash' => $hash,
            'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR), 'created_at' => now()->subSecond(), 'updated_at' => now(),
        ]);
    }
}
