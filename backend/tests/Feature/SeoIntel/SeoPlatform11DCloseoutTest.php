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

        $this->assertSame('seo.council_closeout.v1', $receipt['contract_version']);
        $this->assertSame($sha, $receipt['release_sha']);
        $this->assertSame('DEPLOYED_DISABLED', $receipt['state']);
        $this->assertSame('DETERMINISTIC_ROUTE_HOLD_ONLY', $receipt['runtime_mode']);
        $this->assertSame(1, $receipt['unique_seo_orchestrator_count']);
        $this->assertSame('READY', $receipt['binding_status']);
        $this->assertSame('READY', $receipt['dependency_status']);
        $this->assertSame(0, $receipt['contract_schema_hash_drift']);
        $this->assertSame('5/5', $receipt['entrypoints_present']);
        $this->assertSame(1, $receipt['routing']['all_team_invocation_count']['numerator']);
        $this->assertSame(0, $receipt['routing']['unauthorized_all_team_invocation_count']['numerator']);
        $this->assertSame('not_observed', $receipt['operator_time_baseline']['state'] === 'NO_OBSERVATIONS' ? 'not_observed' : 'observed');
        foreach ([
            'caller_role_bypass', 'active_legacy_seo_agent_entrypoints', 'peer_delegation_bypass',
            'unauthorized_all_team_calls',
            'budget_timeout_retry_idempotency_bypass', 'unresolved_conflict_execution_bypass',
            'career_chain_order_bypass', 'metadata_private_data_bypass', 'l4_allow_count',
            'model_calls', 'tool_calls', 'external_calls', 'agent_write_permissions', 'business_writes',
            'cms_writes', 'url_truth_writes', 'search_writes', 'active_manifests', 'trusted_signing_keys',
        ] as $field) {
            $this->assertSame(0, $receipt[$field], $field);
        }
        $this->assertFalse($receipt['external_trace_export']);
        $this->assertFalse($receipt['shared_agent_memory']);
        $this->assertFalse($receipt['mission_persistence_enabled']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertSame('unavailable_manifest_validator_risk_open', $receipt['career_runtime']);
        $this->assertSame('seo.action_scoped_manifest.v1', $receipt['action_manifest_ref']['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);

        $encoded = strtolower(json_encode($receipt, JSON_THROW_ON_ERROR));
        foreach (['raw_query', 'page_body', 'prompt_text', 'private_identifier', 'exception_message'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }
}
