<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisContractRegistry;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisContractValidator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisModeRegistry;
use Tests\TestCase;

final class SeoPlatform11EContractsTest extends TestCase
{
    public function test_versioned_contract_manifest_and_runtime_capability_are_frozen_and_disabled(): void
    {
        $registry = app(TechnicalDiagnosisContractRegistry::class);
        $manifest = $registry->manifest();
        $generated = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-technical-diagnosis-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('seo.technical_diagnosis_contract_manifest.v1', $manifest['manifest_id']);
        $this->assertCount(7, $manifest['contracts']);
        $this->assertTrue($registry->verify($generated));
        $drifted = $generated;
        $drifted['mode']['version'] = 'drifted';
        $this->assertFalse($registry->verify($drifted));
        foreach ($manifest['contracts'] as $contract) {
            $schema = $registry->schema($contract['id']);
            $this->assertSame($contract['version'], $schema['schema_version']);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertNotEmpty($schema['required']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['hash']);
        }

        $this->assertSame(RoleCapabilityBindingRegistry::V1_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json')));
        $this->assertSame('655d25e227e33f08dc8e8589a414a6a755572450bb9f7da740f7b5d47df40a73', hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json')));

        $runtime = app(TechnicalDiagnosisModeRegistry::class)->capabilitySnapshot();
        $this->assertSame('OFFLINE_EVAL_READY', $runtime['mode_state']);
        foreach (['production_model_enabled', 'production_tool_enabled', 'production_execution_enabled', 'production_write_enabled', 'allow_delegation', 'external_egress_enabled', 'execution_allowed'] as $field) {
            $this->assertFalse($runtime[$field], $field);
        }
        foreach (['active_production_manifests', 'trusted_production_keys', 'production_permissions'] as $field) {
            $this->assertSame(0, $runtime[$field], $field);
        }
    }

    public function test_request_contract_rejects_missing_and_unknown_fields_and_hash_drift(): void
    {
        $validator = app(TechnicalDiagnosisContractValidator::class);
        $request = $validator->sealRequest($this->request());

        $this->assertTrue($validator->request($request));

        $missing = $request;
        unset($missing['mission_id']);
        $this->assertFalse($validator->request($missing));

        $unknown = $request;
        $unknown['requested_role'] = 'seo.expert.technical_search_authority';
        $this->assertFalse($validator->request($unknown));

        $drift = $request;
        $drift['locale'] = 'zh-CN';
        $this->assertFalse($validator->request($drift));
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'diagnosis_id' => 'diagnosis:contract', 'diagnosis_version' => 1,
            'mission_id' => 'mission:contract', 'run_id' => 'run:contract',
            'role_id' => 'seo.expert.technical_search_authority', 'mode_id' => 'technical_search_diagnosis',
            'page_family' => 'tests', 'locale' => 'en',
            'evidence_bundle_refs' => [['bundle_id' => 'bundle:contract', 'bundle_version' => 1, 'bundle_hash' => str_repeat('a', 64)]],
            'dependency_snapshot_ref' => ['snapshot_hash' => str_repeat('b', 64)],
            'detector_registry_ref' => ['version' => 'v1', 'hash' => str_repeat('c', 64)],
            'url_truth_revision' => 'url-truth:v1', 'runtime_revision' => 'runtime:v1',
            'deployment_revision' => str_repeat('d', 40), 'authority_revision' => 'authority:v1',
            'requested_scope' => ['sanitized_public_refs' => ['https://example.test/en/tests/public-page']],
            'requested_at' => '2026-08-30T00:00:00Z',
            'execution_allowed' => false, 'allow_delegation' => false,
        ];
    }
}
