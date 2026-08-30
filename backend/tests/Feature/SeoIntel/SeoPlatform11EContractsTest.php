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
        $generated = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-technical-diagnosis-contract-manifest.v2.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('seo.technical_diagnosis_contract_manifest.v2', $manifest['manifest_id']);
        $this->assertCount(9, $manifest['contracts']);
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
        foreach ([
            'seo.technical_diagnosis_mode.v1.json' => 'd4829d7c79a0b8e4a8b0039a4db54cc96f93f31d9900b11b81322cbdf50a852f',
            'seo.technical_diagnosis_policy.v1.json' => '10e3608c566d1ee63df1e428a3c539051145400df76f2af169e8f0f7e00b97ec',
            'seo.technical_search_diagnosis.prompt.v1.md' => '516d910fc2d2b1eb9be2a450168a07a546245e59f47d359bfb85597273127d04',
            'fixtures/seo.technical_diagnosis_fixtures.v1.json' => '648e6e2ed371ff2209bef86a2024029909f111f73f7daf3b4a68329630df7b16',
        ] as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', resource_path('seo-agent/council/technical-diagnosis/'.$file)), $file);
        }
        $this->assertSame('f2a59865ba9ee20eab5c57add96a540787dd8a7828e7d108e839b5b2a8fbe6d9', hash_file('sha256', base_path('docs/seo/generated/seo-technical-diagnosis-contract-manifest.v1.json')));
        foreach ([
            'seo.technical_diagnosis_request.v1.schema.json' => '15a6d21cd6e836a697e02dab3c1a199b9b35815b96015e0b957c14598710f202',
            'seo.technical_affected_scope.v1.schema.json' => 'c6bf805b8ed302367ad0e126892c6f46cdb76b51d62ccb90f94862cba55b5d90',
            'seo.technical_root_cause_hypothesis.v1.schema.json' => '1bc9c6fe908db03acb1c0b818e1d97fac361d97da6f0ca4128aef098d97abc10',
            'seo.technical_evidence_gap.v1.schema.json' => 'fbac688393512fcbbb65938ec608a5e2adab4d0e525a3312e6661beb58565876',
            'seo.technical_diagnosis_finding.v1.schema.json' => '65b422480789897473937070d1639bb0e06b069d320a6624fa9283454367ca4a',
            'seo.technical_diagnosis_output.v1.schema.json' => 'e33881b4c3eb65ef8f1a1b5af6f05eec2c28e1c3ae5016dc8c501f6857dc99fd',
            'seo.technical_diagnosis_receipt.v1.schema.json' => '03f6100aa7e1dfb0b53f380d77093f4650d3f5e9befe4b002a2107d50649f688',
        ] as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', resource_path('seo-agent/council/technical-diagnosis/schemas/'.$file)), $file);
        }

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
            'diagnosis_id' => 'diagnosis:contract', 'diagnosis_version' => 2,
            'mission_id' => 'mission:contract', 'run_id' => 'run:contract',
            'role_id' => 'seo.expert.technical_search_authority', 'mode_id' => 'technical_search_diagnosis',
            'page_family' => 'tests', 'locale' => 'en',
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:contract', 'bundle_version' => 1, 'bundle_hash' => str_repeat('a', 64),
                'source_type' => 'runtime_observation', 'authority_type' => 'public_runtime_observation',
            ]],
            'dependency_snapshot_ref' => [
                'snapshot_id' => 'snapshot:contract', 'snapshot_version' => 'seo.technical_diagnosis_dependency_snapshot.v2',
                'snapshot_hash' => str_repeat('b', 64), 'production_sha' => str_repeat('d', 40), 'environment' => 'ci_candidate',
            ],
            'detector_registry_ref' => ['registry_version' => 'v1', 'registry_hash' => str_repeat('c', 64)],
            'url_truth_revision' => 'url-truth:v1', 'runtime_revision' => 'runtime:v1',
            'deployment_revision' => str_repeat('d', 40), 'authority_revision' => 'authority:v1',
            'requested_scope' => [
                'sanitized_public_refs' => ['https://example.test/en/tests/public-page'], 'max_urls' => 1,
                'page_family' => 'tests', 'locale' => 'en',
            ],
            'requested_at' => '2026-08-30T00:00:00Z',
            'execution_allowed' => false, 'allow_delegation' => false,
        ];
    }
}
