<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12LegacyConvergenceReceipt;
use Tests\TestCase;

final class SeoPlatform12G03LegacyConvergenceReceiptTest extends TestCase
{
    public function test_skill_is_only_a_mission_request_thin_client(): void
    {
        $receipt = app(Platform12LegacyConvergenceReceipt::class)->build();
        $skill = $receipt['skill_boundary'];

        $this->assertSame('legacy_convergence_receipt', $receipt['receipt_type']);
        $this->assertSame('seo.platform12_legacy_convergence_receipt.v1', $receipt['receipt_version']);
        $this->assertSame('PASS', $receipt['state']);
        $this->assertTrue($skill['constructs_mission_request']);
        $this->assertTrue($skill['validates_and_submits_only']);
        foreach (['owns_role_authority', 'owns_policy_authority', 'owns_runtime_authority', 'owns_tool_authority', 'owns_write_authority'] as $field) {
            $this->assertFalse($skill[$field]);
        }
        $this->assertSame([], $skill['forbidden_token_hits']);
        $this->assertTrue($skill['boundary_passed']);
    }

    public function test_fap_web_has_no_council_model_tool_signing_agent_or_write_authority(): void
    {
        $receipt = app(Platform12LegacyConvergenceReceipt::class)->build();
        $web = $receipt['fap_web_boundary'];

        $this->assertSame('frozen_cross_repository_exact_tree_inventory', $web['proof_basis']);
        $this->assertGreaterThan(0, $web['record_count']);
        $this->assertSame(0, $web['active_agent_count']);
        $this->assertFalse($web['council_client_present']);
        $this->assertSame('api_or_ui_projection_only', $web['allowed_council_surface']);
        foreach (['owns_model_authority', 'owns_tool_manifest_authority', 'owns_signing_authority', 'owns_agent_authority', 'owns_write_authority'] as $field) {
            $this->assertFalse($web[$field]);
        }
        $this->assertTrue($web['boundary_passed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['source_inventory_ref']['hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['source_inventory_ref']['fap_web_path_set_hash']);
    }

    public function test_authority_refs_are_canonical_backend_refs_and_scheduler_has_no_skill_or_frontend_call(): void
    {
        $receipt = app(Platform12LegacyConvergenceReceipt::class)->build();

        $this->assertTrue($receipt['all_authority_refs_canonical_backend']);
        $this->assertSame(['role_registry', 'role_capability_binding', 'policy_registry', 'notification_policy', 'tool_manifest', 'schema_vector'], array_keys($receipt['authority_refs']));
        foreach ($receipt['authority_refs'] as $ref) {
            $this->assertSame('fap-api', $ref['owner_repository']);
            $this->assertTrue($ref['canonical']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ref['hash']);
        }

        $scheduler = $receipt['scheduler_boundary'];
        $this->assertSame([], $scheduler['forbidden_reference_hits']);
        $this->assertSame(0, $scheduler['legacy_scheduler_entrypoint_count']);
        $this->assertFalse($scheduler['calls_skill']);
        $this->assertFalse($scheduler['calls_frontend']);
        $this->assertTrue($scheduler['boundary_passed']);
        $this->assertSame(0, $receipt['alternative_write_paths']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertSame(0, $receipt['writes']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'), $receipt['receipt_hash']);
    }

    public function test_cross_repository_inventory_tamper_holds_without_execution(): void
    {
        $inventory = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-platform-11a-inventory.v3.json')), true, 512, JSON_THROW_ON_ERROR);
        $inventory['fixed_boundaries']['fap_web_agent_authority'] = true;

        $receipt = app(Platform12LegacyConvergenceReceipt::class)->build($inventory);

        $this->assertSame('BOUNDARY_HOLD', $receipt['state']);
        $this->assertFalse($receipt['fap_web_boundary']['boundary_passed']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertSame(0, $receipt['alternative_write_paths']);
        $this->assertSame(0, $receipt['writes']);
    }
}
