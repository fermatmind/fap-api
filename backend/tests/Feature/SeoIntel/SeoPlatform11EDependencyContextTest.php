<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisContractValidator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisDependencySnapshotBuilder;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisEvidenceContextBuilder;
use Tests\TestCase;

final class SeoPlatform11EDependencyContextTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_snapshot_is_externally_sha_environment_and_current_registry_bound(): void
    {
        $builder = app(TechnicalDiagnosisDependencySnapshotBuilder::class);
        $ready = $builder->build(self::SHA, 'ci_candidate', $this->dependencyState());

        $this->assertSame('READY', $ready['status']);
        $this->assertTrue($builder->verify($ready, self::SHA, 'ci_candidate'));
        $this->assertFalse($builder->verify($ready, self::SHA, 'production_runtime'));
        $this->assertFalse($builder->verify($ready, str_repeat('b', 40), 'ci_candidate'));
        $this->assertSame([
            'dependency_ref_mismatch_bypass' => 0,
            'detector_ref_mismatch_bypass' => 0,
        ], $builder->negativeProbeMetrics($ready, self::SHA, 'ci_candidate'));

        foreach ([
            ['dependency_mode' => 'RUNTIME_READ_ONLY'],
            ['observed_active_sha' => self::SHA],
            ['deployment_revision' => str_repeat('b', 40)],
            ['source_capability_state' => 'unavailable'],
            ['page_family' => 'private'],
            ['locale' => 'fr'],
        ] as $mutation) {
            $held = $builder->build(self::SHA, 'ci_candidate', array_replace($this->dependencyState(), $mutation));
            $this->assertSame('DEPENDENCY_HOLD', $held['status']);
        }

        $tampered = $ready;
        $tampered['authority_revision'] = 'authority:tampered';
        unset($tampered['snapshot_hash'], $tampered['snapshot_seal']);
        $tampered['snapshot_hash'] = app(\App\Services\SeoAgentGovernance\SeoRegistryHasher::class)->hash($tampered);
        $tampered['snapshot_seal'] = hash('sha256', $tampered['snapshot_hash']);
        $this->assertFalse($builder->verify($tampered, self::SHA, 'ci_candidate'));
    }

    public function test_context_namespaces_sources_derives_counts_and_is_order_invariant(): void
    {
        $dependency = app(TechnicalDiagnosisDependencySnapshotBuilder::class)
            ->build(self::SHA, 'ci_candidate', $this->dependencyState());
        $bundles = $this->bundles();
        $request = app(TechnicalDiagnosisContractValidator::class)->sealRequest($this->request($bundles, $dependency));
        $builder = app(TechnicalDiagnosisEvidenceContextBuilder::class);
        $context = $builder->build($request, $bundles, $dependency);
        $reversed = $builder->build($request, array_reverse($bundles), $dependency);

        $this->assertSame('READY', $context['status']);
        $this->assertSame($context['context_hash'], $reversed['context_hash']);
        $this->assertSame('false_404', $context['namespaces']['detector']['detector_code']);
        $this->assertSame(2, $context['computed_evidence']['runtime_observation_count']);
        $this->assertSame(2, $context['computed_evidence']['node_count']);
        $this->assertTrue($context['computed_evidence']['direct_reproducible_observation']);
        $this->assertTrue($context['computed_evidence']['required_authority_sources_present']);
        $this->assertSame([
            'cross_source_field_bypass' => 0,
            'cross_source_overwrite_bypass' => 0,
            'bundle_order_variance_count' => 0,
        ], $builder->ownershipProbeMetrics());

        $conflicting = [...$bundles, $this->bundle('truth-conflict', 'url_truth_projection', 'url_truth_authority', [
            'authority_public' => false, 'url_truth_revision' => 'url-truth:v1',
        ])];
        $conflictRequest = app(TechnicalDiagnosisContractValidator::class)
            ->sealRequest($this->request($conflicting, $dependency));
        $first = $builder->build($conflictRequest, $conflicting, $dependency);
        $last = $builder->build($conflictRequest, array_reverse($conflicting), $dependency);
        $this->assertSame('AUTHORITY_CONFLICT_HOLD', $first['status']);
        $this->assertSame($first['context_hash'], $last['context_hash']);
        $this->assertSame([], $first['namespaces']['authority']['url_truth']);
    }

    public function test_context_holds_snapshot_detector_revision_and_field_ownership_mismatches(): void
    {
        $dependency = app(TechnicalDiagnosisDependencySnapshotBuilder::class)
            ->build(self::SHA, 'ci_candidate', $this->dependencyState());
        $bundles = $this->bundles();
        $validator = app(TechnicalDiagnosisContractValidator::class);
        $builder = app(TechnicalDiagnosisEvidenceContextBuilder::class);
        $base = $this->request($bundles, $dependency);

        foreach ([
            ['dependency_snapshot_ref.production_sha', str_repeat('b', 40)],
            ['dependency_snapshot_ref.environment', 'production_runtime'],
            ['dependency_snapshot_ref.snapshot_hash', str_repeat('b', 64)],
            ['detector_registry_ref.registry_hash', str_repeat('c', 64)],
            ['url_truth_revision', 'url-truth:wrong'],
            ['runtime_revision', 'runtime:wrong'],
            ['authority_revision', 'authority:wrong'],
            ['deployment_revision', str_repeat('b', 40)],
        ] as [$path, $value]) {
            $input = $base;
            data_set($input, $path, $value);
            $context = $builder->build($validator->sealRequest($input), $bundles, $dependency);
            $this->assertSame('DEPENDENCY_HOLD', $context['status'], $path);
        }

        $forged = $bundles;
        $forged[3] = $this->bundle('runtime-forged', 'runtime_observation', 'public_runtime_observation', [
            'runtime_status' => 404, 'backend_exists' => true, 'observation_id' => 'obs:forged', 'node_id' => 'node:forged',
        ]);
        $request = $validator->sealRequest($this->request($forged, $dependency));
        $this->assertSame('EVIDENCE_HOLD', $builder->build($request, $forged, $dependency)['status']);
    }

    /** @return array<string, mixed> */
    private function dependencyState(): array
    {
        return [
            'dependency_mode' => 'OFFLINE_FIXTURE', 'observed_active_sha' => null,
            'url_truth_revision' => 'url-truth:v1', 'url_truth_projection_hash' => str_repeat('1', 64),
            'runtime_evidence_revision' => 'runtime:v1', 'runtime_evidence_hash' => str_repeat('2', 64),
            'deployment_revision' => self::SHA, 'authority_revision' => 'authority:v1',
            'page_family' => 'tests', 'locale' => 'en', 'source_capability_state' => 'available',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function bundles(): array
    {
        return [
            $this->bundle('detector', 'detector_result', 'detector_observation', ['detector_code' => 'false_404']),
            $this->bundle('backend', 'backend_authority', 'backend_publication_authority', ['backend_exists' => true]),
            $this->bundle('truth', 'url_truth_projection', 'url_truth_authority', [
                'authority_public' => true, 'url_truth_revision' => 'url-truth:v1',
                'sanitized_public_url_reference' => 'https://example.test/en/tests/public-page',
            ]),
            $this->bundle('runtime-a', 'runtime_observation', 'public_runtime_observation', [
                'runtime_status' => 404, 'observation_id' => 'obs:a', 'node_id' => 'node:a',
                'sanitized_public_url_reference' => 'https://example.test/en/tests/public-page',
            ]),
            $this->bundle('runtime-b', 'runtime_observation', 'public_runtime_observation', [
                'runtime_status' => 404, 'observation_id' => 'obs:b', 'node_id' => 'node:b',
                'sanitized_public_url_reference' => 'https://example.test/en/tests/public-page',
            ]),
            $this->bundle('release', 'release_evidence', 'release_authority', [
                'deployment_sha' => self::SHA, 'deployment_revision' => self::SHA, 'production_sha' => self::SHA,
            ]),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function bundle(string $id, string $source, string $authority, array $payload): array
    {
        return app(SeoEvidenceBundleFactory::class)->create([
            'bundle_id' => 'bundle:'.$id, 'bundle_version' => 1, 'mission_id' => 'mission:technical-context',
            'source_type' => $source, 'source_ref' => hash('sha256', 'source:'.$id), 'authority_type' => $authority,
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'evidence_state' => 'verified',
            'freshness_state' => 'fresh', 'source_capability_state' => 'available',
            'retention_class' => 'public_runtime_observation', 'page_family' => 'tests', 'locale' => 'en',
            'authority_revision' => 'authority:v1', 'injection_scan_result' => 'pass',
            'source_license_class' => 'first_party', 'data_usage_purpose' => 'technical_diagnosis',
            'egress_decision' => 'not_required', 'lineage_refs' => [hash('sha256', 'lineage:'.$id)], 'payload' => $payload,
        ]);
    }

    /** @param list<array<string, mixed>> $bundles @param array<string, mixed> $dependency @return array<string, mixed> */
    private function request(array $bundles, array $dependency): array
    {
        return [
            'diagnosis_id' => 'diagnosis:technical-context', 'diagnosis_version' => 2,
            'mission_id' => 'mission:technical-context', 'run_id' => 'run:technical-context',
            'role_id' => 'seo.expert.technical_search_authority', 'mode_id' => 'technical_search_diagnosis',
            'page_family' => 'tests', 'locale' => 'en',
            'evidence_bundle_refs' => array_map(static fn (array $bundle): array => [
                'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
                'bundle_hash' => $bundle['bundle_hash'], 'source_type' => $bundle['source_type'],
                'authority_type' => $bundle['authority_type'],
            ], $bundles),
            'dependency_snapshot_ref' => app(TechnicalDiagnosisDependencySnapshotBuilder::class)->requestReference($dependency),
            'detector_registry_ref' => $dependency['detector_registry_ref'],
            'url_truth_revision' => $dependency['url_truth_revision'], 'runtime_revision' => $dependency['runtime_evidence_revision'],
            'deployment_revision' => self::SHA, 'authority_revision' => 'authority:v1',
            'requested_scope' => [
                'sanitized_public_refs' => ['https://example.test/en/tests/public-page'], 'max_urls' => 1,
                'page_family' => 'tests', 'locale' => 'en',
            ],
            'requested_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'execution_allowed' => false, 'allow_delegation' => false,
        ];
    }
}
