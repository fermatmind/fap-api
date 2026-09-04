<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12LegacyCallerInventory;
use App\Services\SeoCouncil\Platform12\Platform12LegacyRetirementPlan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform12G02LegacyRetirementPlanTest extends TestCase
{
    public function test_no_entrypoint_is_tombstoned_archived_or_redirected_without_g01_zero_call_proof(): void
    {
        $plan = app(Platform12LegacyRetirementPlan::class)->build();

        $this->assertSame('seo.platform12_legacy_retirement_plan.v1', $plan['receipt_version']);
        $this->assertSame('NO_ELIGIBLE_SUPERSEDED_ENTRYPOINTS', $plan['state']);
        $this->assertSame([], $plan['eligible_entrypoints']);
        $this->assertSame([], $plan['tombstone_actions']);
        $this->assertSame([], $plan['redirect_actions']);
        $this->assertSame([], $plan['artifact_archive_actions']);
        $this->assertSame(35, $plan['summary']['deferred_count']);
        $this->assertSame(6, $plan['summary']['protected_active_operation_count']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($plan, 'receipt_hash'), $plan['receipt_hash']);
        $this->assertFalse($plan['destructive_data_deletion']);
        $this->assertFalse($plan['authority_created']);
        $this->assertFalse($plan['runtime_switches_changed']);
        $this->assertFalse($plan['execution_allowed']);
        $this->assertSame(0, $plan['writes']);
    }

    public function test_active_production_seo_operations_are_preserved_and_not_owned_by_council(): void
    {
        $plan = app(Platform12LegacyRetirementPlan::class)->build();
        $protected = collect($plan['protected_current_operations'])->keyBy('caller_id');

        foreach ([
            'production.weekly_decisions',
            'production.gsc_sync',
            'production.seo_conversion_funnel',
            'production.url_truth_reconciliation',
            'production.runtime_probe',
            'production.sitemap_cache_warm',
        ] as $callerId) {
            $this->assertSame('active_not_owned_by_council', $protected[$callerId]['state']);
            $this->assertStringNotContainsString('Council', $protected[$callerId]['authority_owner']);
        }

        $this->assertSame([
            'exact_sha_ci_receipts',
            'authority_packages_and_manifests',
            'audit_and_history_records',
            'rollback_and_lkg_evidence',
        ], $plan['preserved_evidence']);
        $this->assertSame(0, $plan['scheduler_changes']);
    }

    public function test_tampered_zero_call_or_retired_state_holds_without_tombstone_or_redirect(): void
    {
        $inventory = app(Platform12LegacyCallerInventory::class)->build();
        $inventory['legacy_entrypoints'][0]['classification'] = 'retired';
        $inventory['legacy_entrypoints'][0]['delete_ready'] = true;
        $inventory['inventory_hash'] = app(SeoRegistryHasher::class)->hashWithout($inventory, 'inventory_hash');

        $plan = app(Platform12LegacyRetirementPlan::class)->build($inventory);

        $this->assertSame('INVENTORY_HOLD', $plan['state']);
        $this->assertSame([], $plan['tombstone_actions']);
        $this->assertSame([], $plan['redirect_actions']);
        $this->assertSame([], $plan['artifact_archive_actions']);
        $this->assertFalse($plan['execution_allowed']);
        $this->assertSame(0, $plan['writes']);
    }

    public function test_legacy_commands_remain_disabled_and_no_duplicate_ui_redirect_is_invented(): void
    {
        $artisan = new Process(['php', 'artisan', 'list', '--raw', '--no-ansi'], base_path(), ['APP_ENV' => 'local']);
        $artisan->mustRun();

        $this->assertStringNotContainsString('seo'.'-agent:', $artisan->getOutput());
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php'));
        $this->assertStringContainsString('ops-trace-drilldown-workspace', $workspace);
        $this->assertStringNotContainsString('<form', $workspace);
        $this->assertStringNotContainsString('redirect(', $workspace);
    }
}
