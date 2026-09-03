<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11DCloseoutTest extends TestCase
{
    public function test_closeout_receipt_is_exact_sha_bound_and_asserts_all_negative_guarantees(): void
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();
        $sha = trim($process->getOutput());
        $tester = new CommandTester(Artisan::all()['seo:council-closeout']);

        $this->assertSame(0, $tester->execute(['--expected-sha' => $sha, '--json' => true]));
        $receipt = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('seo.council_closeout.v2', $receipt['contract_version']);
        $this->assertSame($sha, $receipt['source_sha']);
        $this->assertSame(1, $receipt['unique_orchestrator_probe_total']);
        $this->assertSame('3.0.0', $receipt['binding_version']);
        $this->assertSame('READY', $receipt['dependency_status']);
        $this->assertSame(0, $receipt['contract_schema_hash_drift_count']);
        $this->assertSame(5, $receipt['five_entrypoint_probe_total']);
        $this->assertSame(5, $receipt['five_entrypoint_probe_passed']);
        $this->assertSame(3, $receipt['csrf_negative_probe_total']);
        $this->assertSame(1, $receipt['receipt_projection_probe_total']);
        $this->assertSame('CLOSED', $receipt['SEO-PLATFORM-11D']);
        $this->assertTrue($receipt['ready_for_11E']);
        $this->assertSame(1, $receipt['routing']['all_team_invocation_count']['numerator']);
        $this->assertSame(0, $receipt['routing']['unauthorized_all_team_invocation_count']['numerator']);
        $this->assertSame('not_observed', $receipt['operator_time_baseline']['state'] === 'NO_OBSERVATIONS' ? 'not_observed' : 'observed');
        foreach ([
            'binding_schema_probe_failed', 'binding_hash_drift_count', 'unbound_mission_count',
            'unknown_role_count', 'unknown_capability_count', 'unknown_tool_count',
            'admission_deny_bypass', 'admission_hold_bypass', 'requested_role_expansion_bypass',
            'csrf_bypass', 'career_chain_bypass', 'policy_reason_overwrite_count',
            'unauthorized_route_execution_count', 'model_calls', 'tool_calls', 'external_calls',
            'business_writes', 'cms_writes', 'url_truth_writes', 'search_writes',
            'active_manifest_count', 'trusted_key_count', 'l4_allow_count', 'production_permissions',
            'active_legacy_seo_agent_entrypoints', 'receipt_projection_bypass',
        ] as $field) {
            $this->assertSame(0, $receipt[$field], $field);
        }
        $this->assertFalse($receipt['mission_persistence_enabled']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertSame('unavailable_manifest_validator_risk_open', $receipt['career_runtime']);
        $this->assertSame('seo.action_scoped_manifest.v1', $receipt['action_manifest_ref']['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);

        $encoded = strtolower(json_encode($receipt, JSON_THROW_ON_ERROR));
        foreach (['raw_query', 'page_body', 'prompt_text', 'private_identifier', 'exception_message'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'":', $encoded);
        }
    }
}
