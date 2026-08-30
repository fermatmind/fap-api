<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoCouncil\Measurement\MeasurementContractValidator;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceContextBuilder;
use Tests\Feature\SeoIntel\Concerns\BuildsMeasurementV2Context;
use Tests\TestCase;

final class SeoPlatform11FEvidencePrivacyTest extends TestCase
{
    use BuildsMeasurementV2Context;

    public function test_request_bundle_context_and_output_layers_reject_private_data(): void
    {
        $scanner = app(SeoPrivateDataScanner::class);
        foreach ([
            ['unknowns' => ['owner@example.com']],
            ['hypotheses' => ['Call +8613812345678']],
            ['metadata' => ['token' => 'sk-live-abcdefgh12345678']],
            ['facts' => ['payment' => '4242 4242 4242 4242']],
            ['nested' => ['opaque' => '550e8400-e29b-41d4-a716-446655440000']],
        ] as $probe) {
            $this->assertTrue($scanner->scan($probe)['private_data_present']);
        }

        [$bundle, $request] = $this->measurementBundleAndRequest();
        foreach ([
            'owner@example.com', '+8613812345678', 'Bearer abc.def.ghi', '4242 4242 4242 4242',
            str_repeat('a', 16).'4111111111111111'.str_repeat('b', 32),
        ] as $private) {
            $tampered = $bundle;
            $tampered['payload']['unknowns'] = [$private];
            $tampered = $this->rehashBundle($tampered);
            $this->assertFalse(app(SeoEvidenceBundleVerifier::class)->verify($tampered)['valid']);
            $context = $this->buildForBundle($request, $tampered);
            $this->assertSafeHold($context);
        }

        $unsafeRequest = $request;
        $unsafeRequest['metadata'] = ['email' => 'owner@example.com'];
        $unsafeRequest = app(MeasurementContractValidator::class)->sealRequest($unsafeRequest);
        $this->assertSafeHold(app(MeasurementEvidenceContextBuilder::class)->build($unsafeRequest, [$bundle]));
    }

    public function test_available_and_unavailable_bundle_conflict_is_unverified_and_never_ready(): void
    {
        [$available, $request] = $this->measurementBundleAndRequest();
        $held = app(\App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory::class)->create([
            'bundle_id' => 'bundle:measurement-held', 'bundle_version' => 2,
            'mission_id' => $available['mission_id'], 'source_type' => 'gsc_aggregate',
            'source_ref' => str_repeat('c', 64), 'authority_type' => 'measurement_readmodel',
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'evidence_state' => 'blocked',
            'freshness_state' => 'stale', 'source_capability_state' => 'held',
            'retention_class' => 'first_party_aggregate', 'page_family' => 'tests', 'locale' => 'en',
            'authority_revision' => $available['authority_revision'], 'source_license_class' => 'first_party',
            'data_usage_purpose' => 'measurement_review', 'egress_decision' => 'not_required',
            'lineage_refs' => [], 'payload' => $available['payload'],
        ]);
        $request['evidence_bundle_refs'][] = [
            'bundle_id' => $held['bundle_id'], 'bundle_version' => $held['bundle_version'],
            'bundle_hash' => $held['bundle_hash'], 'source_type' => $held['source_type'],
            'authority_type' => $held['authority_type'],
        ];
        $request = app(MeasurementContractValidator::class)->sealRequest($request);
        $context = app(MeasurementEvidenceContextBuilder::class)->build($request, [$available, $held]);

        $this->assertSafeHold($context);
        $this->assertSame('unverified', $context['source_capability']['state']);
        $this->assertTrue($context['source_capability']['conflict_detected']);
        $this->assertSame('hold', $context['measurement_state']['state']);
    }

    public function test_private_urls_and_bundle_identity_conflicts_fail_closed_and_clear_evidence(): void
    {
        $negative = app(SeoPrivateRouteNegativeSet::class);
        foreach (['/results/secret', '/%72esults/secret', '/ReSuLtS/secret', '/en/account/history'] as $path) {
            $this->assertTrue($negative->classify($path)['private'], $path);
        }

        [$bundle, $request] = $this->measurementBundleAndRequest();
        foreach (['/%72esults/secret', '/ReSuLtS/secret', '/en/account/history'] as $privatePath) {
            $tampered = $bundle;
            $tampered['payload']['unknowns'] = [$privatePath];
            $tampered = $this->rehashBundle($tampered);
            $this->assertSafeHold($this->buildForBundle($request, $tampered));
        }

        foreach ([
            ['bundle_version', 3], ['mission_id', 'mission:other'], ['authority_type', 'other_readmodel'],
            ['page_family', 'articles'], ['locale', 'zh-CN'], ['authority_revision', str_repeat('b', 64)],
            ['source_type', 'public_funnel_aggregate'], ['expires_at', now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z')],
        ] as [$field, $value]) {
            $tampered = $bundle;
            $tampered[$field] = $value;
            $tampered = $this->rehashBundle($tampered);
            $this->assertSafeHold($this->buildForBundle($request, $tampered));
        }
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $bundle @return array<string, mixed> */
    private function buildForBundle(array $request, array $bundle): array
    {
        $request['evidence_bundle_refs'][0] = [
            'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
            'bundle_hash' => $bundle['bundle_hash'], 'source_type' => $bundle['source_type'],
            'authority_type' => $bundle['authority_type'],
        ];
        $request = app(MeasurementContractValidator::class)->sealRequest($request);

        return app(MeasurementEvidenceContextBuilder::class)->build($request, [$bundle]);
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private function rehashBundle(array $bundle): array
    {
        $hasher = app(SeoEvidenceCanonicalHasher::class);
        $bundle['content_hash'] = $hasher->hash($bundle['payload']);
        $bundle['bundle_hash'] = $hasher->hashWithout($bundle, 'bundle_hash');

        return $bundle;
    }

    /** @param array<string, mixed> $context */
    private function assertSafeHold(array $context): void
    {
        $this->assertSame('HOLD', $context['status']);
        $this->assertSame([], $context['bundle_refs']);
        $this->assertSame([], $context['metrics']);
        $this->assertSame(['verified_facts' => [], 'associations' => [], 'hypotheses' => [], 'unknowns' => []], $context['facts']);
        $this->assertSame('page_family:held', $context['page_family']);
        $this->assertSame('und', $context['locale']);
    }
}
