<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12LegacyCallerInventory;
use Tests\TestCase;

final class SeoPlatform12G01LegacyCallerInventoryTest extends TestCase
{
    public function test_inventory_is_versioned_hash_bound_and_read_only(): void
    {
        $inventory = app(Platform12LegacyCallerInventory::class)->build();

        $this->assertSame('seo.platform12_legacy_caller_inventory.v1', $inventory['schema_version']);
        $this->assertSame('1.0.0', $inventory['inventory_version']);
        $this->assertSame('READ_ONLY', $inventory['inventory_state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $inventory['definition_hash']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($inventory, 'inventory_hash'), $inventory['inventory_hash']);
        $this->assertTrue($inventory['read_only']);
        $this->assertFalse($inventory['execution_allowed']);
        $this->assertFalse($inventory['runtime_switches_changed']);
        $this->assertSame(0, $inventory['writes']);
    }

    public function test_all_legacy_callers_scan_every_required_domain_before_deletion_classification(): void
    {
        $inventory = app(Platform12LegacyCallerInventory::class)->build();
        $expectedDomains = ['code', 'laravel_schedule', 'ci_deploy_nightly', 'documentation', 'external_scripts', 'audit_history'];

        $this->assertSame($expectedDomains, array_keys($inventory['scan_domains']));
        $this->assertCount(35, $inventory['legacy_entrypoints']);
        foreach ($inventory['legacy_entrypoints'] as $entrypoint) {
            $this->assertSame($expectedDomains, array_keys($entrypoint['references']));
            $this->assertContains($entrypoint['classification'], ['retired', 'deferred']);
            if ($entrypoint['delete_ready']) {
                $this->assertTrue($entrypoint['zero_call_proven']);
                $this->assertSame(0, $entrypoint['reference_count']);
                $this->assertNotSame('', $entrypoint['replacement']);
            } else {
                $this->assertFalse($entrypoint['zero_call_proven']);
                $this->assertGreaterThan(0, $entrypoint['reference_count']);
            }
        }

        $byEntrypoint = collect($inventory['legacy_entrypoints'])->keyBy('entrypoint');
        $this->assertNotEmpty($byEntrypoint['seo-agent:run']['references']['code']);
        $this->assertNotEmpty($byEntrypoint['seo-agent:run']['references']['documentation']);
        $this->assertNotEmpty($byEntrypoint['seo-agent:run']['references']['audit_history']);
        foreach ($inventory['legacy_entrypoints'] as $entrypoint) {
            $this->assertSame([], $entrypoint['references']['laravel_schedule']);
            $this->assertSame([], $entrypoint['references']['ci_deploy_nightly']);
        }
    }

    public function test_current_callers_bind_real_evidence_and_protect_existing_production_operations(): void
    {
        $inventory = app(Platform12LegacyCallerInventory::class)->build();
        $callers = collect($inventory['current_callers'])->keyBy('caller_id');

        $this->assertSame(0, $inventory['summary']['unverified_current_evidence_count']);
        foreach ($inventory['current_callers'] as $caller) {
            $this->assertTrue($caller['evidence_verified'], $caller['caller_id']);
            $this->assertNotSame('', $caller['authority_owner']);
            $this->assertNotSame('', $caller['audit_history_value']);
        }

        foreach ([
            'production.weekly_decisions',
            'production.gsc_sync',
            'production.seo_conversion_funnel',
            'production.url_truth_reconciliation',
            'production.runtime_probe',
            'production.sitemap_cache_warm',
        ] as $callerId) {
            $this->assertSame('active_not_owned_by_council', $callers[$callerId]['state']);
            $this->assertNull($callers[$callerId]['replacement']);
        }

        $this->assertSame('deferred', $callers['council.scheduler']['state']);
        $this->assertSame('SEO-PLATFORM-12A-08 activation decision', $callers['council.scheduler']['replacement']);
        $this->assertSame('deferred', $callers['council.ui_submission_route']['state']);
        $this->assertSame('active_foundation', $callers['council.local_skill']['state']);
        $this->assertSame('active_validation', $callers['delivery.council_ci_closeout']['state']);
        $this->assertSame('active_validation', $callers['delivery.council_deploy_closeout']['state']);
    }
}
