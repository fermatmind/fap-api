<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentPolicyGateway\ActionManifestVerifier;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayCallerGuard;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayContractRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayStatusProjection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class SeoPolicyGatewayCloseout extends Command
{
    protected $signature = 'seo:policy-gateway-closeout {--expected-sha=} {--json}';

    protected $description = 'Verify the deterministic deny-only SEO Policy Gateway for an exact release SHA';

    public function handle(
        PolicyGatewayRegistry $registry,
        PolicyGatewayContractRegistry $contracts,
        PolicyGatewayCallerGuard $callers,
        ActionManifestVerifier $manifests,
        SeoEvidenceCanonicalHasher $hasher,
    ): int {
        try {
            $releaseSha = $this->releaseSha();
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            if (preg_match('/^[a-f0-9]{40}$/', $expectedSha) !== 1 || ! hash_equals($expectedSha, $releaseSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            if ($registry->dependencyStatus() !== 'READY') {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'DEPENDENCY_HOLD'], self::FAILURE);
            }
            $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-policy-gateway-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($artifact) || ! $contracts->verify($artifact)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'CONTRACT_MANIFEST_INVALID'], self::FAILURE);
            }

            $admissionBypass = 0;
            $executionBypass = 0;
            $entrypointBypass = 0;
            $l4AllowCount = 0;
            foreach ($registry->callerTypes() as $callerType) {
                $request = $this->admissionRequest($callerType, $hasher);
                $admission = $callers->admission($callerType, $request);
                $admissionBypass += (int) (($admission['execution_allowed'] ?? true) || ($admission['decision'] ?? null) === 'ALLOW');
                $l4 = $request;
                $l4['autonomy'] = 'L4';
                $l4Decision = $callers->admission($callerType, $l4);
                $l4AllowCount += (int) (($l4Decision['execution_allowed'] ?? true) || ($l4Decision['decision'] ?? null) === 'ALLOW');

                $execution = $callers->execution($callerType, [
                    'schema_version' => 'seo.policy_execution_request.v1',
                    'caller_type' => $callerType,
                    'admission_request' => $request,
                    'manifest' => $this->unsignedManifest($registry, $hasher),
                    'action_scope' => [
                        'family' => 'tests',
                        'locale' => 'en',
                        'action' => 'backend_dry_run',
                        'fields' => ['title'],
                        'url_count' => 1,
                        'claim_risk' => 'R1',
                        'blast_radius' => 'single_url',
                        'shared_layer' => false,
                        'rollback_ready' => true,
                        'rollback_unit' => 'exact_url',
                        'authority_revision' => str_repeat('a', 64),
                        'measurement_state' => 'READY',
                        'canary_stage' => 'stage_0',
                    ],
                ]);
                $executionBypass += (int) (($execution['execution_allowed'] ?? true) || ($execution['decision'] ?? null) === 'ALLOW');
                $entrypointBypass += (int) (($admission['policy_registry_id'] ?? null) !== PolicyGatewayRegistry::REGISTRY_ID
                    || ($execution['policy_registry_id'] ?? null) !== PolicyGatewayRegistry::REGISTRY_ID);
            }

            $gateway = $registry->registry();
            $guards = (array) $gateway['guards'];
            $trust = $registry->trustRegistry();
            $manifestProbe = $manifests->verify($this->unsignedManifest($registry, $hasher));
            $manifestBypass = (int) $manifestProbe['valid'];
            $receipt = [
                'contract_version' => 'seo.policy_gateway_closeout.v1',
                'release_sha' => $releaseSha,
                'policy_registry_id' => $gateway['registry_id'],
                'policy_registry_version' => $gateway['registry_version'],
                'policy_registry_hash' => $gateway['registry_hash'],
                '11a_registry_hash' => PolicyGatewayRegistry::ROLE_REGISTRY_HASH,
                '11b_contract_manifest_hash' => PolicyGatewayRegistry::EVIDENCE_MANIFEST_HASH,
                'page_family_policy_hash' => PolicyGatewayRegistry::PAGE_FAMILY_POLICY_HASH,
                'release_separation_policy_hash' => PolicyGatewayRegistry::RELEASE_SEPARATION_POLICY_HASH,
                'state' => 'DEPLOYED_DISABLED',
                'mode' => PolicyGatewayStatusProjection::MODE,
                'decision_allow_count' => 0,
                'admission_bypass' => $admissionBypass,
                'execution_bypass' => $executionBypass,
                'manifest_bypass' => $manifestBypass,
                'entrypoint_bypass' => $entrypointBypass,
                'l4_allow_count' => $l4AllowCount,
                'active_manifest_count' => count((array) ($trust['active_manifest_ids'] ?? [])),
                'trusted_signing_key_count' => count((array) ($trust['trusted_public_keys'] ?? [])),
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'business_writes' => 0,
                'cms_writes' => 0,
                'url_truth_writes' => 0,
                'search_submissions' => 0,
            ];
            if ($receipt['admission_bypass'] !== 0 || $receipt['execution_bypass'] !== 0
                || $receipt['manifest_bypass'] !== 0 || $receipt['entrypoint_bypass'] !== 0
                || $receipt['l4_allow_count'] !== 0 || $receipt['active_manifest_count'] !== 0
                || $receipt['trusted_signing_key_count'] !== 0 || $guards['global_write_gate'] !== false
                || $guards['post12_agent_write_enabled'] !== false) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'POLICY_GATEWAY_BYPASS_DETECTED'], self::FAILURE);
            }
            $receipt['receipt_hash'] = $hasher->hash($receipt);

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'POLICY_GATEWAY_CLOSEOUT_FAILED'], self::FAILURE);
        }
    }

    /** @return array<string, mixed> */
    private function admissionRequest(string $callerType, SeoEvidenceCanonicalHasher $hasher): array
    {
        $now = CarbonImmutable::now('UTC');
        $context = [
            'schema_version' => 'seo.evidence_context.v1',
            'context_id' => str_repeat('b', 64),
            'context_version' => 1,
            'mission_id' => 'mission:closeout',
            'mission_type' => 'bounded_review',
            'role_id' => 'seo.expert.technical_search_authority',
            'page_family' => 'tests',
            'locale' => 'en',
            'built_at' => $now->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->addMinute()->format('Y-m-d\TH:i:s\Z'),
            'bundle_refs' => [['bundle_id' => 'bundle:closeout', 'bundle_version' => 1, 'bundle_hash' => str_repeat('c', 64)]],
            'source_capability_states' => ['available'],
            'evidence_summary' => ['bundle_count' => 1, 'private_data_present' => false],
            'payload' => ['revision_hash' => str_repeat('a', 64)],
            'status' => 'READY',
            'execution_allowed' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'write_permissions' => [],
            'tool_allowlist' => [],
            'egress_allowlist' => [],
        ];
        $context['context_hash'] = $hasher->hash($context);

        return [
            'schema_version' => 'seo.policy_admission_request.v1',
            'caller_type' => $callerType,
            'mission_id' => 'mission:closeout',
            'mission_type' => 'bounded_review',
            'requested_role_id' => 'seo.expert.technical_search_authority',
            'family' => 'tests',
            'locale' => 'en',
            'claim_risk' => 'R1',
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'execution_seconds' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'deadline_seconds' => 0,
            'tool_scope' => [],
            'egress_scope' => [],
            'evidence_context' => $context,
            'request_metadata' => ['source_label' => 'closeout', 'correlation_hash' => str_repeat('d', 64)],
        ];
    }

    /** @return array<string, mixed> */
    private function unsignedManifest(PolicyGatewayRegistry $registry, SeoEvidenceCanonicalHasher $hasher): array
    {
        $gateway = $registry->registry();
        $now = CarbonImmutable::now('UTC');
        $manifest = [
            'schema_version' => 'seo.action_scoped_manifest.v1',
            'manifest_id' => 'manifest:closeout-probe',
            'manifest_version' => '1.0.0',
            'policy_registry_ref' => ['id' => $gateway['registry_id'], 'version' => $gateway['registry_version'], 'hash' => $gateway['registry_hash']],
            'role_id' => 'seo.expert.technical_search_authority',
            'mission_type' => 'bounded_review',
            'capability_id' => 'seo.runtime_health_review',
            'target_environment' => 'testing',
            'family' => 'tests',
            'locale' => 'en',
            'action' => 'backend_dry_run',
            'allowed_fields' => ['title'],
            'forbidden_fields' => ['private'],
            'autonomy' => 'L0',
            'max_urls' => 1,
            'shared_layer_allowed' => false,
            'evidence_threshold' => ['minimum_bundle_count' => 1, 'required_status' => 'READY'],
            'rollback_unit' => 'exact_url',
            'approval' => ['surface_id' => 'seo_agent_draft_review', 'review_state' => 'approved', 'production_execution_separate' => true],
            'authority_revision' => str_repeat('a', 64),
            'canary_stage' => 'stage_0',
            'expiry' => ['not_before' => $now->subMinute()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $now->addMinute()->format('Y-m-d\TH:i:s\Z')],
            'revocation' => ['registry_id' => 'seo.manifest_revocation_registry', 'registry_version' => '1.0.0'],
        ];
        $manifest['manifest_hash'] = $hasher->hash($manifest);
        $manifest['signature'] = ['algorithm' => 'Ed25519', 'key_id' => 'untrusted-closeout-key', 'value' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES))];

        return $manifest;
    }

    private function releaseSha(): string
    {
        $revision = dirname(base_path()).'/REVISION';
        if (is_file($revision)) {
            return strtolower(trim((string) file_get_contents($revision)));
        }
        $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();

        return strtolower(trim($process->getOutput()));
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
