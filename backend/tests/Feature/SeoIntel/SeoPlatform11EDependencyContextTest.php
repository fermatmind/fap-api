<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisContractValidator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisDependencySnapshotBuilder;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisEvidenceContextBuilder;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SeoPlatform11EDependencyContextTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_dependency_snapshot_is_canonical_exact_sha_bound_and_fails_closed_on_conflict(): void
    {
        $builder = app(TechnicalDiagnosisDependencySnapshotBuilder::class);
        $ready = $builder->build(self::SHA, $this->dependencyState());

        $this->assertSame('READY', $ready['status']);
        $this->assertTrue($ready['ready_for_diagnosis']);
        $this->assertFalse($ready['execution_allowed']);
        $this->assertTrue($builder->verify($ready, self::SHA));

        $revised = $builder->build(self::SHA, array_replace($this->dependencyState(), [
            'url_truth_revision' => 'url-truth:v2',
            'url_truth_projection_hash' => str_repeat('5', 64),
        ]));
        $this->assertSame('READY', $revised['status']);
        $this->assertNotSame($ready['snapshot_id'], $revised['snapshot_id']);
        $this->assertNotSame($ready['snapshot_hash'], $revised['snapshot_hash']);

        $tampered = $ready;
        $tampered['authority_revision'] = 'authority:tampered';
        $this->assertFalse($builder->verify($tampered, self::SHA));
        $this->assertFalse($builder->verify($ready, str_repeat('b', 40)));
        foreach ([
            ['role_registry_ref', 'hash'], ['evidence_bundle_contract_ref', 'hash'],
            ['admission_policy_ref', 'hash'], ['binding_ref', 'hash'],
            ['detector_registry_ref', 'hash'], ['page_family_policy_ref', 'hash'],
        ] as [$ref, $field]) {
            $drifted = $ready;
            $drifted[$ref][$field] = str_repeat('f', 64);
            $this->assertFalse($builder->verify($drifted, self::SHA), $ref);
        }

        foreach ([
            ['evidence_deployment_revision' => str_repeat('b', 40)],
            ['evidence_authority_revision' => 'authority:conflict'],
            ['source_capability_state' => 'unavailable'],
            ['evidence_freshness_state' => 'stale'],
            ['page_family' => 'private'],
            ['locale' => 'fr'],
        ] as $override) {
            $hold = $builder->build(self::SHA, array_replace($this->dependencyState(), $override));
            $this->assertSame('DEPENDENCY_HOLD', $hold['status']);
            $this->assertFalse($hold['ready_for_diagnosis']);
            $this->assertFalse($hold['execution_allowed']);
        }
    }

    public function test_context_uses_verified_bundle_minimizes_fields_and_holds_private_or_stale_evidence(): void
    {
        $dependency = app(TechnicalDiagnosisDependencySnapshotBuilder::class)->build(self::SHA, $this->dependencyState());
        $bundle = $this->bundle();
        $request = app(TechnicalDiagnosisContractValidator::class)->sealRequest($this->request($bundle, $dependency));
        $builder = app(TechnicalDiagnosisEvidenceContextBuilder::class);
        $context = $builder->build($request, [$bundle], $dependency);

        $this->assertSame('READY', $context['status']);
        $this->assertTrue($context['diagnosis_allowed']);
        $this->assertFalse($context['execution_allowed']);
        $this->assertSame('false_404', $context['payload']['detector_code']);
        $this->assertArrayNotHasKey('internal_note', $context['payload']);
        $this->assertSame(['internal_note'], $context['redaction_summary']['redacted_fields']);
        $this->assertSame($bundle['bundle_hash'], $context['bundle_refs'][0]['bundle_hash']);

        foreach ([
            'https://example.test/en/result/private-id',
            'https://example.test/en/tests/%72%65%73%75%6c%74/private-id',
            'https://example.test/en/tests/public-page?token=private-id',
            'https://example.test/EN/ACCOUNT/private-id',
        ] as $private) {
            $this->assertFalse($builder->publicReferenceAllowed($private));
        }

        $stale = $bundle;
        $stale['freshness_state'] = 'stale';
        $stale['bundle_hash'] = app(\App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher::class)->hash(array_diff_key($stale, ['bundle_hash' => true]));
        $staleRequest = app(TechnicalDiagnosisContractValidator::class)->sealRequest($this->request($stale, $dependency));
        $held = $builder->build($staleRequest, [$stale], $dependency);
        $this->assertSame('MEASUREMENT_HOLD', $held['status']);
        $this->assertSame([], $held['payload']);
        $this->assertFalse($held['diagnosis_allowed']);

        $unavailable = $this->rehashBundle(array_replace($bundle, ['source_capability_state' => 'unavailable']));
        $unavailableRequest = app(TechnicalDiagnosisContractValidator::class)->sealRequest($this->request($unavailable, $dependency));
        $held = $builder->build($unavailableRequest, [$unavailable], $dependency);
        $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', $held['status']);
        $this->assertFalse($held['diagnosis_allowed']);

        $private = $bundle;
        $private['payload']['sanitized_public_url_reference'] = 'https://example.test/en/result/private-id';
        $private = $this->rehashBundle($private, true);
        $privateRequest = app(TechnicalDiagnosisContractValidator::class)->sealRequest($this->request($private, $dependency));
        $held = $builder->build($privateRequest, [$private], $dependency);
        $this->assertSame('PRIVATE_DATA_HOLD', $held['status']);
        $this->assertSame([], $held['payload']);
        $this->assertStringNotContainsString('private-id', json_encode($held, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function dependencyState(): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'production_closeout_sha' => self::SHA,
            'url_truth_revision' => 'url-truth:v1', 'url_truth_projection_hash' => str_repeat('1', 64),
            'runtime_evidence_revision' => 'runtime:v1', 'runtime_evidence_hash' => str_repeat('2', 64),
            'deployment_revision' => self::SHA, 'evidence_deployment_revision' => self::SHA,
            'authority_revision' => 'authority:v1', 'evidence_authority_revision' => 'authority:v1',
            'page_family' => 'tests', 'locale' => 'en',
            'evidence_captured_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'evidence_expires_at' => $now->addHour()->format('Y-m-d\TH:i:s\Z'),
            'source_capability_state' => 'available', 'evidence_freshness_state' => 'fresh',
        ];
    }

    /** @return array<string, mixed> */
    private function bundle(): array
    {
        return app(SeoEvidenceBundleFactory::class)->create([
            'bundle_id' => 'bundle:technical-context', 'bundle_version' => 1,
            'mission_id' => 'mission:technical-context', 'source_type' => 'runtime_observation',
            'source_ref' => str_repeat('3', 64), 'authority_type' => 'public_runtime_observation',
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'evidence_state' => 'verified',
            'freshness_state' => 'fresh', 'source_capability_state' => 'available',
            'retention_class' => 'public_runtime_observation', 'page_family' => 'tests', 'locale' => 'en',
            'authority_revision' => 'authority:v1', 'injection_scan_result' => 'pass',
            'source_license_class' => 'first_party', 'data_usage_purpose' => 'technical_diagnosis',
            'egress_decision' => 'not_required', 'lineage_refs' => [str_repeat('4', 64)],
            'payload' => [
                'detector_code' => 'false_404', 'deployment_sha' => self::SHA,
                'sanitized_public_url_reference' => 'https://example.test/en/tests/public-page',
                'observations' => ['authority_public' => true, 'backend_authority_exists' => true, 'publication_indexable' => true, 'runtime_status' => 404],
                'source_count' => 2, 'repeat_observation' => true, 'revision_consistent' => true,
                'affected_url_count' => 1, 'affected_family_count' => 1, 'internal_note' => 'redact-me',
            ],
        ]);
    }

    /** @param array<string, mixed> $bundle @param array<string, mixed> $dependency @return array<string, mixed> */
    private function request(array $bundle, array $dependency): array
    {
        return [
            'diagnosis_id' => 'diagnosis:technical-context', 'diagnosis_version' => 1,
            'mission_id' => 'mission:technical-context', 'run_id' => 'run:technical-context',
            'role_id' => 'seo.expert.technical_search_authority', 'mode_id' => 'technical_search_diagnosis',
            'page_family' => 'tests', 'locale' => 'en',
            'evidence_bundle_refs' => [['bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'], 'bundle_hash' => $bundle['bundle_hash']]],
            'dependency_snapshot_ref' => ['snapshot_hash' => $dependency['snapshot_hash']],
            'detector_registry_ref' => $dependency['detector_registry_ref'],
            'url_truth_revision' => $dependency['url_truth_revision'], 'runtime_revision' => $dependency['runtime_evidence_revision'],
            'deployment_revision' => self::SHA, 'authority_revision' => 'authority:v1',
            'requested_scope' => ['sanitized_public_refs' => ['https://example.test/en/tests/public-page']],
            'requested_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'execution_allowed' => false, 'allow_delegation' => false,
        ];
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private function rehashBundle(array $bundle, bool $rehashContent = false): array
    {
        $hasher = app(\App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher::class);
        if ($rehashContent) {
            $bundle['content_hash'] = $hasher->hash($bundle['payload']);
        }
        unset($bundle['bundle_hash']);
        $bundle['bundle_hash'] = $hasher->hash($bundle);

        return $bundle;
    }
}
