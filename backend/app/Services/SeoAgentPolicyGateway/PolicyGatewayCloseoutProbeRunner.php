<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class PolicyGatewayCloseoutProbeRunner
{
    private const TEST_KEY_ID = 'closeout-ephemeral-key';

    public function __construct(
        private readonly Application $application,
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly PolicyGatewayContractValidator $contracts,
        private readonly AdmissionPolicy $admission,
        private readonly PolicyGatewayRegistry $registry,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly PageFamilyPolicyRegistry $families,
        private readonly PolicyDecisionFactory $decisions,
    ) {}

    /** @return array{manifest_contract:array<string,mixed>,execution_scope_binding:array<string,mixed>} */
    public function run(): array
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('Sodium is required.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $verifier = new ActionManifestVerifier(
            $this->contracts,
            $this->registry,
            $this->roles,
            $this->hasher,
            $this->application,
            [self::TEST_KEY_ID => base64_encode(sodium_crypto_sign_publickey($keypair))],
        );

        return [
            'manifest_contract' => $this->manifestContractProbes($verifier, $secretKey),
            'execution_scope_binding' => $this->executionScopeBindingProbes($verifier, $secretKey),
        ];
    }

    /** @return array<string, mixed> */
    private function manifestContractProbes(ActionManifestVerifier $verifier, string $secretKey): array
    {
        $probes = [
            'review_state_invalid' => ['approval' => ['review_state' => 'invalid']],
            'authority_revision_empty' => ['authority_revision' => ''],
            'canary_stage_empty' => ['canary_stage' => ''],
        ];
        $results = [];
        $rejected = 0;
        foreach ($probes as $probeId => $overrides) {
            $result = $verifier->verify($this->manifest($verifier, $secretKey, $overrides));
            $passed = $result === ['valid' => false, 'code' => 'MANIFEST_CONTRACT_INVALID'];
            $rejected += (int) $passed;
            $results[] = [
                'probe_id' => $probeId,
                'outcome' => $passed ? 'REJECTED' : 'BYPASS',
                'reason_code' => $passed ? 'MANIFEST_CONTRACT_INVALID' : 'PROBE_FAILED',
            ];
        }

        return [
            'total' => count($probes),
            'rejected' => $rejected,
            'bypass' => count($probes) - $rejected,
            'probes' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function executionScopeBindingProbes(ActionManifestVerifier $verifier, string $secretKey): array
    {
        $policy = new ExecutionPolicy(
            $this->privacy,
            $this->contracts,
            $this->admission,
            $verifier,
            $this->registry,
            $this->families,
            $this->decisions,
        );
        $baselineManifest = $this->manifest($verifier, $secretKey);
        $baselineRequest = $this->executionRequest($baselineManifest);
        $probes = [];

        $probes[] = $this->executionProbe(
            $policy,
            'role_binding_mismatch',
            $this->executionRequest($this->manifest($verifier, $secretKey, [
                'role_id' => 'seo.expert.search_analytics_measurement',
            ])),
            'DENY',
            'MANIFEST_ROLE_BINDING_MISMATCH',
        );

        $missionRequest = $this->executionRequest($this->manifest($verifier, $secretKey, [
            'role_id' => 'seo.orchestrator',
            'mission_type' => 'weekly_opportunity',
        ]));
        $missionRequest['admission_request'] = $this->admissionRequest(
            roleId: 'seo.orchestrator',
            missionType: 'global_portfolio',
        );
        $probes[] = $this->executionProbe(
            $policy,
            'mission_binding_mismatch',
            $missionRequest,
            'DENY',
            'MANIFEST_MISSION_BINDING_MISMATCH',
        );

        $probes[] = $this->executionProbe(
            $policy,
            'autonomy_binding_expansion',
            $this->executionRequest($this->manifest($verifier, $secretKey, ['autonomy' => 'L1'])),
            'DENY',
            'MANIFEST_AUTONOMY_BINDING_MISMATCH',
        );

        $probes[] = $this->executionProbe(
            $policy,
            'target_environment_mismatch',
            $this->executionRequest($this->manifest($verifier, $secretKey, [
                'target_environment' => $this->mismatchedEnvironment(),
            ])),
            'DENY',
            'MANIFEST_TARGET_ENVIRONMENT_MISMATCH',
        );

        $probes[] = $this->executionProbe(
            $policy,
            'evidence_threshold_unmet',
            $this->executionRequest($this->manifest($verifier, $secretKey, [
                'evidence_threshold' => ['minimum_bundle_count' => 2],
            ])),
            'HOLD',
            'EVIDENCE_THRESHOLD_UNMET',
        );

        $canaryRequest = $baselineRequest;
        $canaryRequest['action_scope']['canary_stage'] = 'stage_1';
        $probes[] = $this->executionProbe(
            $policy,
            'canary_stage_mismatch',
            $canaryRequest,
            'DENY',
            'CANARY_STAGE_MISMATCH',
        );

        $probes[] = $this->executionProbe(
            $policy,
            'approval_pending',
            $this->executionRequest($this->manifest($verifier, $secretKey, [
                'approval' => ['review_state' => 'pending'],
            ])),
            'HOLD',
            'APPROVAL_PENDING',
        );
        $probes[] = $this->executionProbe(
            $policy,
            'approval_rejected',
            $this->executionRequest($this->manifest($verifier, $secretKey, [
                'approval' => ['review_state' => 'rejected'],
            ])),
            'DENY',
            'APPROVAL_REJECTED',
        );
        $probes[] = $this->executionProbe(
            $policy,
            'approval_unknown',
            $this->executionRequest($this->manifest($verifier, $secretKey, [
                'approval' => ['review_state' => 'unknown'],
            ])),
            'DENY',
            'APPROVAL_UNKNOWN',
        );

        $blastRadiusRequest = $baselineRequest;
        $blastRadiusRequest['action_scope']['blast_radius'] = 'shared_layer';
        $probes[] = $this->executionProbe(
            $policy,
            'blast_radius_scope_mismatch',
            $blastRadiusRequest,
            'DENY',
            'BLAST_RADIUS_SCOPE_MISMATCH',
        );

        $denied = count(array_filter($probes, static fn (array $probe): bool => $probe['outcome'] === 'DENIED'));
        $held = count(array_filter($probes, static fn (array $probe): bool => $probe['outcome'] === 'HELD'));

        return [
            'total' => count($probes),
            'denied' => $denied,
            'held' => $held,
            'bypass' => count($probes) - $denied - $held,
            'probes' => $probes,
        ];
    }

    /** @param array<string, mixed> $request @return array{probe_id:string,outcome:string,reason_code:string} */
    private function executionProbe(
        ExecutionPolicy $policy,
        string $probeId,
        array $request,
        string $expectedDecision,
        string $expectedReason,
    ): array {
        $decision = $policy->decide($request, 'cli');
        $passed = ($decision['decision'] ?? null) === $expectedDecision
            && ($decision['execution_allowed'] ?? true) === false
            && in_array($expectedReason, (array) ($decision['reason_codes'] ?? []), true);

        return [
            'probe_id' => $probeId,
            'outcome' => $passed ? ($expectedDecision === 'DENY' ? 'DENIED' : 'HELD') : 'BYPASS',
            'reason_code' => $passed ? $expectedReason : 'PROBE_FAILED',
        ];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function manifest(ActionManifestVerifier $verifier, string $secretKey, array $overrides = []): array
    {
        $gateway = $this->registry->registry();
        $now = CarbonImmutable::now('UTC');
        $manifest = [
            'schema_version' => 'seo.action_scoped_manifest.v1',
            'manifest_id' => 'manifest:closeout-scope-probe',
            'manifest_version' => '1.0.0',
            'policy_registry_ref' => ['id' => $gateway['registry_id'], 'version' => $gateway['registry_version'], 'hash' => $gateway['registry_hash']],
            'role_id' => 'seo.expert.technical_search_authority',
            'mission_type' => 'bounded_review',
            'capability_id' => 'seo.runtime_health_review',
            'target_environment' => $this->application->environment(),
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
            'expiry' => [
                'not_before' => $now->subMinute()->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => $now->addMinute()->format('Y-m-d\TH:i:s\Z'),
            ],
            'revocation' => ['registry_id' => 'seo.manifest_revocation_registry', 'registry_version' => '1.0.0'],
        ];
        $manifest = array_replace_recursive($manifest, $overrides);
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);
        $manifest['signature'] = ['algorithm' => 'Ed25519', 'key_id' => self::TEST_KEY_ID, 'value' => ''];
        $manifest['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
            $verifier->signatureMessage($manifest),
            $secretKey,
        ));

        return $manifest;
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function executionRequest(array $manifest): array
    {
        return [
            'schema_version' => 'seo.policy_execution_request.v1',
            'caller_type' => 'cli',
            'admission_request' => $this->admissionRequest(),
            'manifest' => $manifest,
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
        ];
    }

    /** @return array<string, mixed> */
    private function admissionRequest(
        string $roleId = 'seo.expert.technical_search_authority',
        string $missionType = 'bounded_review',
    ): array {
        $now = CarbonImmutable::now('UTC');
        $context = [
            'schema_version' => 'seo.evidence_context.v1',
            'context_id' => str_repeat('b', 64),
            'context_version' => 1,
            'mission_id' => 'mission:closeout-scope-probe',
            'mission_type' => $missionType,
            'role_id' => $roleId,
            'page_family' => 'tests',
            'locale' => 'en',
            'built_at' => $now->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->addMinute()->format('Y-m-d\TH:i:s\Z'),
            'bundle_refs' => [['bundle_id' => 'bundle:closeout-scope-probe', 'bundle_version' => 1, 'bundle_hash' => str_repeat('c', 64)]],
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
        $context['context_hash'] = $this->hasher->hash($context);

        return [
            'schema_version' => 'seo.policy_admission_request.v1',
            'caller_type' => 'cli',
            'mission_id' => 'mission:closeout-scope-probe',
            'mission_type' => $missionType,
            'requested_role_id' => $roleId,
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

    private function mismatchedEnvironment(): string
    {
        return match ($this->application->environment()) {
            'testing' => 'staging',
            'staging' => 'production',
            default => 'testing',
        };
    }
}
