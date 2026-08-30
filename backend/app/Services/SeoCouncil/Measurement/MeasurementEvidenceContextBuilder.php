<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Context\SeoEvidenceContextBuilder;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class MeasurementEvidenceContextBuilder
{
    /** @var array<string, list<string>> */
    private const ALLOWED_FIELDS = [
        'search_measurement' => [
            'windows', 'branded_non_branded', 'detector_findings', 'freshness', 'mapping_state',
            'quality_gate_status', 'window_complete', 'current_window_readable',
            'valid_measurement_present', 'explicit_zero_proof', 'all_relevant_values_zero',
            'verified_facts', 'associations', 'hypotheses', 'unknowns',
        ],
        'commercial_funnel_cro' => [
            'windows', 'stage_coverage', 'freshness', 'revision_hash', 'mapping_state',
            'quality_gate_status', 'window_complete', 'current_window_readable',
            'valid_measurement_present', 'explicit_zero_proof', 'all_relevant_values_zero',
            'verified_facts', 'associations', 'hypotheses', 'unknowns',
        ],
    ];

    public function __construct(
        private readonly SeoEvidenceContextBuilder $baseContexts,
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly SeoPrivateDataScanner $scanner,
        private readonly SeoPrivateRouteNegativeSet $negativeSet,
        private readonly MeasurementPrivacyScanner $measurementPrivacy,
        private readonly MeasurementStateResolver $states,
        private readonly MeasurementContractValidator $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @param list<array<string, mixed>> $bundles @return array<string, mixed> */
    public function build(array $request, array $bundles): array
    {
        $status = 'READY';
        if ($this->measurementPrivacy->request($request)) {
            $status = 'HOLD';
        }
        if (! $this->contracts->request($request)) {
            $status = 'HOLD';
        }

        $modeId = (string) ($request['mode_id'] ?? 'search_measurement');
        $roleId = (string) ($request['role_id'] ?? 'seo.expert.search_analytics_measurement');
        $pageFamily = (string) ($request['page_family'] ?? 'page_family:held');
        $locale = (string) ($request['locale'] ?? 'und');
        if ($this->baseContexts->scopeDecision('bounded_review', $roleId, $pageFamily, $locale) !== 'READY') {
            $status = 'HOLD';
        }

        $refs = [];
        $payload = [];
        $capabilities = [];
        $freshnessStates = [];
        $revisions = [];
        $requested = [];
        foreach ((array) ($request['evidence_bundle_refs'] ?? []) as $ref) {
            if (is_array($ref)) {
                $requested[(string) ($ref['bundle_hash'] ?? '')] = $ref;
            }
        }

        foreach ($bundles as $bundle) {
            $metadata = array_diff_key($bundle, ['payload' => true]);
            $metadataPrivate = $this->scanner->scan($metadata, SeoPrivateDataScanner::BUNDLE_METADATA_HASH_PATHS)['private_data_present'];
            $payloadPrivate = $this->scanner->scan($bundle['payload'] ?? null, SeoPrivateDataScanner::MINIMIZED_PAYLOAD_HASH_PATHS)['private_data_present'];
            $verification = $this->verifier->verify($bundle);
            $hash = (string) ($bundle['bundle_hash'] ?? '');
            $ref = $requested[$hash] ?? null;
            if ($metadataPrivate || $payloadPrivate || ! $verification['valid']
                || ! is_array($ref)
                || ($bundle['bundle_id'] ?? null) !== ($ref['bundle_id'] ?? null)
                || ($bundle['bundle_version'] ?? null) !== ($ref['bundle_version'] ?? null)
                || ($bundle['source_type'] ?? null) !== ($ref['source_type'] ?? null)
                || ($bundle['authority_type'] ?? null) !== ($ref['authority_type'] ?? null)
                || ($bundle['mission_id'] ?? null) !== ($request['mission_id'] ?? null)
                || ($bundle['page_family'] ?? null) !== ($request['page_family'] ?? null)
                || ($bundle['locale'] ?? null) !== ($request['locale'] ?? null)
                || $this->containsPrivateUrl($bundle)) {
                $status = 'HOLD';

                continue;
            }
            $allowedSource = match ($modeId) {
                'search_measurement' => 'gsc_aggregate',
                'commercial_funnel_cro' => 'public_funnel_aggregate',
                default => '',
            };
            if (($bundle['source_type'] ?? null) !== $allowedSource) {
                $status = 'HOLD';

                continue;
            }
            $bundlePayload = (array) ($bundle['payload'] ?? []);
            if (array_diff(array_keys($bundlePayload), self::ALLOWED_FIELDS[$modeId] ?? []) !== []) {
                $status = 'HOLD';

                continue;
            }
            foreach ($bundlePayload as $field => $value) {
                if (array_key_exists($field, $payload) && $payload[$field] !== $value) {
                    $status = 'HOLD';
                }
                $payload[$field] = $value;
            }
            $capabilities[] = (string) ($bundle['source_capability_state'] ?? 'held');
            $freshnessStates[] = (string) ($bundle['freshness_state'] ?? 'unknown');
            $revisions[] = (string) ($bundle['authority_revision'] ?? '');
            $refs[] = [
                'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
                'bundle_hash' => $hash, 'source_type' => $bundle['source_type'], 'authority_type' => $bundle['authority_type'],
            ];
        }
        if (count($refs) !== count($requested) || $refs === []) {
            $status = 'HOLD';
        }
        $uniqueRevisions = array_values(array_unique(array_filter($revisions, 'strlen')));
        $authorityConflict = count($uniqueRevisions) !== 1
            || ($uniqueRevisions[0] ?? null) !== ($request['authority_revision'] ?? null);
        $unavailable = array_intersect($capabilities, ['unavailable', 'held']) !== [];
        $available = in_array('available', $capabilities, true);
        $evidence = [
            'api_ready' => false, 'property_verified' => false, 'adapter_ready' => false,
            'quality_gate_passed' => ($payload['quality_gate_status'] ?? null) === 'pass',
            'current_window_readable' => ($payload['current_window_readable'] ?? false) === true,
            'manual_export_verified' => false, 'unavailable_proven' => $unavailable,
            'expected_evidence_present' => $refs !== [], 'window_complete' => ($payload['window_complete'] ?? false) === true,
            'valid_measurement_present' => ($payload['valid_measurement_present'] ?? false) === true,
            'explicit_zero_proof' => ($payload['explicit_zero_proof'] ?? false) === true,
            'all_relevant_values_zero' => ($payload['all_relevant_values_zero'] ?? false) === true,
            'mapping_failed' => ($payload['mapping_state'] ?? null) !== 'mapped',
            'freshness_state' => $freshnessStates !== [] && count(array_unique($freshnessStates)) === 1 ? $freshnessStates[0] : 'unknown',
            'authority_conflict' => $authorityConflict,
            'privacy_failed' => $status !== 'READY', 'policy_hold' => $status !== 'READY',
            'authority_revision' => $uniqueRevisions[0] ?? 'unavailable',
            'source_ref' => $refs[0]['bundle_hash'] ?? 'unavailable',
            'windows' => [7, 28, 90], 'freshness' => (array) ($payload['freshness'] ?? []),
        ];
        if ($unavailable && $available) {
            $evidence['unavailable_proven'] = true;
            $evidence['quality_gate_passed'] = true;
            $evidence['current_window_readable'] = true;
        }
        $source = $this->states->sourceCapability($evidence);
        $measurement = $this->states->measurementState($evidence);
        if (($source['conflict_detected'] ?? false) === true
            || ! in_array($source['state'] ?? null, ['api_ready', 'available'], true)
            || ! in_array($measurement['state'] ?? null, ['valid', 'valid_zero'], true)) {
            $status = 'HOLD';
        }

        $metrics = array_intersect_key($payload, array_flip($modeId === 'search_measurement'
            ? ['windows', 'branded_non_branded', 'detector_findings', 'freshness', 'mapping_state']
            : ['windows', 'stage_coverage', 'freshness', 'revision_hash', 'mapping_state']));
        $facts = [
            'verified_facts' => $this->strings($payload['verified_facts'] ?? []),
            'associations' => $this->strings($payload['associations'] ?? []),
            'hypotheses' => $this->strings($payload['hypotheses'] ?? []),
            'unknowns' => $this->strings($payload['unknowns'] ?? []),
        ];
        if ($status !== 'READY') {
            $refs = [];
            $metrics = [];
            $facts = ['verified_facts' => [], 'associations' => [], 'hypotheses' => [], 'unknowns' => []];
            $pageFamily = 'page_family:held';
            $locale = 'und';
        }
        $context = [
            'version' => 'seo.measurement_evidence_context.v2',
            'request_hash' => (string) ($request['request_hash'] ?? hash('sha256', 'measurement:held')),
            'role_id' => $roleId, 'mode_id' => $modeId, 'page_family' => $pageFamily, 'locale' => $locale,
            'windows' => [7, 28, 90], 'bundle_refs' => $refs,
            'source_capability' => $source, 'measurement_state' => $measurement,
            'metrics' => $metrics, 'facts' => $facts, 'status' => $status,
            'measurement_allowed' => $status === 'READY', 'execution_allowed' => false,
        ];
        $context['context_hash'] = $this->hasher->hash($context);
        if (! $this->contracts->context($context) || $this->measurementPrivacy->context($context)) {
            $safeMode = in_array($modeId, ['search_measurement', 'commercial_funnel_cro'], true)
                ? $modeId
                : 'search_measurement';
            $context = [
                ...$context,
                'request_hash' => preg_match('/^[a-f0-9]{64}$/D', (string) ($context['request_hash'] ?? '')) === 1
                    ? $context['request_hash']
                    : hash('sha256', 'measurement:held'),
                'role_id' => $safeMode === 'search_measurement'
                    ? 'seo.expert.search_analytics_measurement'
                    : 'seo.expert.commercial_funnel_cro',
                'mode_id' => $safeMode, 'page_family' => 'page_family:held', 'locale' => 'und',
                'bundle_refs' => [], 'metrics' => [],
                'facts' => ['verified_facts' => [], 'associations' => [], 'hypotheses' => [], 'unknowns' => []],
                'status' => 'HOLD', 'measurement_allowed' => false, 'execution_allowed' => false,
            ];
            unset($context['context_hash']);
            $context['context_hash'] = $this->hasher->hash($context);
        }

        return $context;
    }

    private function containsPrivateUrl(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->containsPrivateUrl($child)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && (str_contains($value, '/') || str_contains($value, '://'))
            && $this->negativeSet->classify($value)['private'];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
