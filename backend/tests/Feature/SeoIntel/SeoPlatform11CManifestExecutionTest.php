<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\ActionManifestVerifier;
use App\Services\SeoAgentPolicyGateway\ExecutionPolicy;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayContractValidator;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SeoPlatform11CManifestExecutionTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ed25519_verify_only_accepts_ephemeral_test_key_and_rejects_invalid_states(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T10:00:00Z');
        [$verifier, $secretKey] = $this->createVerifier();
        $manifest = $this->manifest($verifier, $secretKey);
        $this->assertSame(['valid' => true, 'code' => 'PASS'], $verifier->verify($manifest));

        $production = app(ActionManifestVerifier::class);
        $this->assertSame('MANIFEST_KEY_UNKNOWN', $production->verify($manifest)['code']);

        $tampered = $manifest;
        $tampered['max_urls'] = 2;
        $this->assertSame('MANIFEST_HASH_INVALID', $verifier->verify($tampered)['code']);

        $expired = $this->manifest($verifier, $secretKey, ['expiry' => ['not_before' => '2026-08-29T09:00:00Z', 'expires_at' => '2026-08-29T10:00:00Z']]);
        $this->assertSame('MANIFEST_EXPIRED', $verifier->verify($expired)['code']);
        $future = $this->manifest($verifier, $secretKey, ['expiry' => ['not_before' => '2026-08-29T10:00:01Z', 'expires_at' => '2026-08-29T11:00:00Z']]);
        $this->assertSame('MANIFEST_NOT_YET_VALID', $verifier->verify($future)['code']);
        $revoked = $this->manifest($verifier, $secretKey, ['manifest_id' => 'manifest:revoked-test']);
        $this->assertSame('MANIFEST_REVOKED_OR_REGISTRY_MISMATCH', $verifier->verify($revoked)['code']);
        $wildcard = $this->manifest($verifier, $secretKey, ['allowed_fields' => ['*']]);
        $this->assertSame('MANIFEST_CONTRACT_INVALID', $verifier->verify($wildcard)['code']);
        $unknownField = $this->manifest($verifier, $secretKey, ['allowed_fields' => ['unknown_field']]);
        $this->assertSame('MANIFEST_FIELD_SCOPE_INVALID', $verifier->verify($unknownField)['code']);

        foreach ([
            'review_state' => ['approval' => ['review_state' => 'in_review']],
            'authority_revision' => ['authority_revision' => ''],
            'canary_stage' => ['canary_stage' => ''],
        ] as $name => $overrides) {
            $invalidContract = $this->manifest($verifier, $secretKey, $overrides);
            $this->assertSame('MANIFEST_CONTRACT_INVALID', $verifier->verify($invalidContract)['code'], $name);
        }
    }

    public function test_manifest_target_environment_is_bound_in_testing_staging_and_production(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T10:00:00Z');
        $previousEnvironment = $this->app->environment();

        try {
            foreach ([
                'testing' => 'staging',
                'staging' => 'production',
                'production' => 'testing',
            ] as $environment => $mismatch) {
                $this->app->detectEnvironment(static fn (): string => $environment);
                [$verifier, $secretKey] = $this->createVerifier();
                $this->assertSame('PASS', $verifier->verify($this->manifest($verifier, $secretKey))['code'], $environment);
                $manifest = $this->manifest($verifier, $secretKey, ['target_environment' => $mismatch]);
                $this->assertSame('MANIFEST_TARGET_ENVIRONMENT_MISMATCH', $verifier->verify($manifest)['code'], $environment);
            }
        } finally {
            $this->app->detectEnvironment(static fn (): string => $previousEnvironment);
        }
    }

    public function test_execution_rechecks_admission_and_valid_manifest_but_global_and_canary_gates_hold(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T10:00:00Z');
        [$verifier, $secretKey] = $this->createVerifier();
        $this->app->instance(ActionManifestVerifier::class, $verifier);
        $policy = app(ExecutionPolicy::class);
        $manifest = $this->manifest($verifier, $secretKey);
        $request = $this->executionRequest($manifest);

        $decision = $policy->decide($request, 'api');
        $this->assertSame('HOLD', $decision['decision']);
        $this->assertContains('ROLE_CAPABILITY_BINDING_UNAVAILABLE', $decision['reason_codes']);
        $this->assertContains('CANARY_STATE_UNAVAILABLE', $decision['reason_codes']);
        $this->assertContains('GLOBAL_WRITE_GATE_DISABLED', $decision['reason_codes']);
        $this->assertContains('POST12_AGENT_WRITE_DISABLED', $decision['reason_codes']);
        $this->assertFalse($decision['execution_allowed']);

        foreach ([
            'url_limit' => ['url_count' => 2],
            'forbidden_field' => ['fields' => ['private']],
            'scope' => ['family' => 'career'],
            'revision' => ['authority_revision' => str_repeat('f', 64)],
            'rollback' => ['rollback_ready' => false],
            'shared_layer' => ['shared_layer' => true, 'blast_radius' => 'shared_layer'],
        ] as $name => $mutation) {
            $invalid = $request;
            $invalid['action_scope'] = array_replace($invalid['action_scope'], $mutation);
            $result = $policy->decide($invalid, 'api');
            $this->assertSame('DENY', $result['decision'], $name);
            $this->assertFalse($result['execution_allowed'], $name);
        }

        $fakeDecision = $request;
        $fakeDecision['admission_decision'] = ['decision' => 'ALLOW'];
        $this->assertSame('DENY', $policy->decide($fakeDecision, 'api')['decision']);

        $denyOverHoldManifest = $this->manifest($verifier, $secretKey, [
            'role_id' => 'seo.expert.search_analytics_measurement',
            'evidence_threshold' => ['minimum_bundle_count' => 2],
        ]);
        $denyOverHold = $policy->decide($this->executionRequest($denyOverHoldManifest), 'api');
        $this->assertSame('DENY', $denyOverHold['decision']);
        $this->assertContains('MANIFEST_ROLE_BINDING_MISMATCH', $denyOverHold['reason_codes']);
        $this->assertNotContains('EVIDENCE_THRESHOLD_UNMET', $denyOverHold['reason_codes']);
        $this->assertFalse($denyOverHold['execution_allowed']);
    }

    /** @return array{ActionManifestVerifier,string} */
    private function createVerifier(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $verifier = new ActionManifestVerifier(
            app(PolicyGatewayContractValidator::class),
            app(PolicyGatewayRegistry::class),
            app(SeoRoleCapabilityRegistry::class),
            app(SeoEvidenceCanonicalHasher::class),
            $this->app,
            ['test-key' => base64_encode($publicKey)],
        );

        return [$verifier, $secretKey];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function manifest(ActionManifestVerifier $verifier, string $secretKey, array $overrides = []): array
    {
        $registry = app(PolicyGatewayRegistry::class)->registry();
        $manifest = [
            'schema_version' => 'seo.action_scoped_manifest.v1',
            'manifest_id' => 'manifest:test',
            'manifest_version' => '1.0.0',
            'policy_registry_ref' => ['id' => $registry['registry_id'], 'version' => $registry['registry_version'], 'hash' => $registry['registry_hash']],
            'role_id' => 'seo.expert.technical_search_authority',
            'mission_type' => 'bounded_review',
            'capability_id' => 'seo.runtime_health_review',
            'target_environment' => $this->app->environment(),
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
            'expiry' => ['not_before' => '2026-08-29T09:00:00Z', 'expires_at' => '2026-08-29T11:00:00Z'],
            'revocation' => ['registry_id' => 'seo.manifest_revocation_registry', 'registry_version' => '1.0.0'],
        ];
        $manifest = array_replace_recursive($manifest, $overrides);
        $manifest['manifest_hash'] = app(SeoEvidenceCanonicalHasher::class)->hash($manifest);
        $manifest['signature'] = ['algorithm' => 'Ed25519', 'key_id' => 'test-key', 'value' => ''];
        $manifest['signature']['value'] = base64_encode(sodium_crypto_sign_detached($verifier->signatureMessage($manifest), $secretKey));

        return $manifest;
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function executionRequest(array $manifest): array
    {
        return [
            'schema_version' => 'seo.policy_execution_request.v1',
            'caller_type' => 'api',
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
    private function admissionRequest(): array
    {
        $context = [
            'schema_version' => 'seo.evidence_context.v1', 'context_id' => str_repeat('b', 64), 'context_version' => 1,
            'mission_id' => 'mission:test', 'mission_type' => 'bounded_review', 'role_id' => 'seo.expert.technical_search_authority',
            'page_family' => 'tests', 'locale' => 'en', 'built_at' => '2026-08-29T09:59:00Z', 'expires_at' => '2026-08-29T10:30:00Z',
            'bundle_refs' => [['bundle_id' => 'bundle:test', 'bundle_version' => 1, 'bundle_hash' => str_repeat('c', 64)]],
            'source_capability_states' => ['available'], 'evidence_summary' => ['bundle_count' => 1, 'private_data_present' => false],
            'payload' => ['revision_hash' => str_repeat('a', 64)], 'status' => 'READY', 'execution_allowed' => false,
            'model_invocation' => false, 'tool_invocation' => false, 'write_permissions' => [], 'tool_allowlist' => [], 'egress_allowlist' => [],
        ];
        $context['context_hash'] = app(SeoEvidenceCanonicalHasher::class)->hash($context);

        return [
            'schema_version' => 'seo.policy_admission_request.v1', 'caller_type' => 'api', 'mission_id' => 'mission:test',
            'mission_type' => 'bounded_review', 'requested_role_id' => 'seo.expert.technical_search_authority', 'family' => 'tests',
            'locale' => 'en', 'claim_risk' => 'R1', 'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'execution_seconds' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'deadline_seconds' => 0, 'tool_scope' => [], 'egress_scope' => [], 'evidence_context' => $context,
            'request_metadata' => ['source_label' => 'test', 'correlation_hash' => str_repeat('d', 64)],
        ];
    }
}
