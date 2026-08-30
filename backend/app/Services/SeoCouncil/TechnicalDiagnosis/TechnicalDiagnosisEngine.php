<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class TechnicalDiagnosisEngine
{
    /** @var list<string> */
    private const FORBIDDEN_ACTIONS = [
        'canonical_write', 'robots_write', 'noindex_write', 'sitemap_write', 'llms_write',
        'url_truth_write', 'cms_publish', 'search_submission', 'external_tool',
        'peer_delegation', 'l4', 'execution_command', 'action_manifest',
    ];

    public function __construct(
        private readonly TechnicalDiagnosisContractValidator $contracts,
        private readonly TechnicalDiagnosisEvidenceContextBuilder $contexts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @param array<string, mixed> $context @return array<string, mixed> */
    public function diagnose(array $request, array $context): array
    {
        if (! $this->contracts->request($request)
            || ! $this->contextValid($context, (string) ($request['request_hash'] ?? ''))
            || ($context['status'] ?? null) !== 'READY'
            || ($context['diagnosis_allowed'] ?? null) !== true) {
            return $this->hold($request, $this->safeStatus((string) ($context['status'] ?? 'EVIDENCE_HOLD')));
        }
        $payload = (array) ($context['payload'] ?? []);
        $refs = $this->contexts->sanitizePublicReferences((array) ($request['requested_scope']['sanitized_public_refs'] ?? []));
        if (count($refs) !== count((array) ($request['requested_scope']['sanitized_public_refs'] ?? []))) {
            return $this->hold($request, 'PRIVATE_DATA_HOLD');
        }
        if ($this->forbiddenActionRequested($payload)) {
            return $this->hold($request, 'POLICY_HOLD');
        }
        $detector = data_get($payload, 'detector_code');
        $observations = (array) ($payload['observations'] ?? []);
        if (! is_string($detector)) {
            return $this->hold($request, 'EVIDENCE_HOLD');
        }
        if (($observations['authority_conflict'] ?? false) === true) {
            return $this->findingOutput($request, $context, $this->outcome('authority_conflict_hold', false, 'blocked', 'HOLD', true));
        }
        if (in_array($observations['authority_created_by'] ?? null, ['runtime', 'sitemap', 'feed', 'cache'], true)) {
            return $this->findingOutput($request, $context, $this->outcome('authority_invention_hold', false, 'blocked', 'HOLD', true));
        }

        $outcome = match ($detector) {
            'false_404' => $this->false404($observations),
            'false_noindex' => $this->falseNoindex($observations),
            'empty_shell' => $this->emptyShell($observations),
            'canonical_drift' => $this->canonicalDrift($observations),
            'hreflang_drift' => $this->hreflangDrift($observations),
            'cache_revision_drift' => $this->cacheDrift($observations),
            'feed_drift' => $this->feedDrift($observations),
            'shared_api_root_cause' => $this->sharedRoot($observations),
            default => $this->outcome('unknown_detector_hold', false, 'blocked', 'HOLD', true),
        };

        return $this->findingOutput($request, $context, $outcome);
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function false404(array $o): array
    {
        if (($o['retired'] ?? false) === true && ($o['runtime_status'] ?? null) === 404) {
            return $this->outcome('valid_retired_404', false, 'verified_fact', 'P3');
        }
        if (($o['authority_public'] ?? false) !== true || ($o['backend_exists'] ?? false) !== true) {
            return $this->outcome('authority_conflict_hold', false, 'blocked', 'HOLD', true);
        }
        if (($o['runtime_status'] ?? null) !== 404) {
            return $this->outcome('runtime_observation_insufficient', false, 'unknown', 'HOLD', true);
        }
        if ((int) ($o['observation_count'] ?? 0) < 2 || (int) ($o['node_count'] ?? 0) < 2) {
            return $this->outcome('runtime_observation_insufficient', false, 'unknown', 'HOLD', true);
        }

        return $this->outcome('verified_false_404', true, 'verified_fact', $this->impactSeverity($o));
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function falseNoindex(array $o): array
    {
        if (($o['policy_indexable'] ?? false) === false && ($o['observed_noindex'] ?? false) === true) {
            return $this->outcome('policy_authorized_noindex', false, 'verified_fact', 'P3');
        }
        if (($o['policy_indexable'] ?? false) === true && ($o['observed_noindex'] ?? false) === true
            && ($o['revision_consistent'] ?? false) === true) {
            return $this->outcome('verified_false_noindex', true, 'verified_fact', $this->impactSeverity($o));
        }
        if (($o['policy_indexable'] ?? false) === true && ($o['cached_noindex'] ?? false) === true) {
            return $this->outcome('stale_noindex_hypothesis', true, 'supported_hypothesis', 'P2', false, ['cache_revision_recheck']);
        }

        return $this->outcome('authority_conflict_hold', false, 'blocked', 'HOLD', true);
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function emptyShell(array $o): array
    {
        if (($o['runtime_status'] ?? null) !== 200) {
            return $this->outcome('insufficient_runtime_evidence', false, 'unknown', 'HOLD', true);
        }
        if (($o['visible_content_present'] ?? false) === true && ($o['required_modules_complete'] ?? false) === true) {
            return $this->outcome('valid_200_content', false, 'verified_fact', 'P3');
        }
        if (($o['jsonld_has_content'] ?? false) === true && ($o['visible_content_present'] ?? false) === false) {
            return $this->outcome('visible_schema_parity_hold', true, 'blocked', 'HOLD', true);
        }
        if (($o['api_payload_complete'] ?? true) === false) {
            return $this->outcome('api_payload_gap', true, 'verified_fact', $this->impactSeverity($o));
        }
        if (($o['skeleton_only'] ?? false) === true || ($o['visible_content_present'] ?? true) === false) {
            return $this->outcome('verified_empty_shell', true, 'verified_fact', $this->impactSeverity($o));
        }

        return $this->outcome('partial_render_failure', true, 'supported_hypothesis', 'P2', false, ['renderer_and_hydration_recheck']);
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function canonicalDrift(array $o): array
    {
        if (($o['backend_canonical'] ?? null) === null || ($o['url_truth_canonical'] ?? null) === null) {
            return $this->outcome('canonical_authority_hold', false, 'blocked', 'HOLD', true);
        }
        $values = array_filter([
            $o['backend_canonical'] ?? null, $o['url_truth_canonical'] ?? null,
            $o['frontend_canonical'] ?? null, $o['final_url'] ?? null,
            $o['feed_canonical'] ?? null,
        ], 'is_string');
        if (count(array_unique($values)) === 1 && ($o['historical_alias_as_canonical'] ?? false) === false) {
            return $this->outcome('valid_canonical', false, 'verified_fact', 'P3');
        }

        return $this->outcome('verified_canonical_drift', true, 'verified_fact', $this->impactSeverity($o));
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function hreflangDrift(array $o): array
    {
        if (($o['invented_locale'] ?? false) === true || ($o['counterpart_authority_exists'] ?? null) === null) {
            return $this->outcome('hreflang_authority_hold', false, 'blocked', 'HOLD', true);
        }
        if (($o['self_reference'] ?? false) === true && ($o['reciprocal'] ?? false) === true
            && ($o['canonical_consistent'] ?? false) === true && ($o['counterpart_indexable'] ?? false) === true) {
            return $this->outcome('valid_hreflang', false, 'verified_fact', 'P3');
        }
        if (($o['reciprocal'] ?? true) === false) {
            return $this->outcome('hreflang_non_reciprocal', true, 'verified_fact', 'P2');
        }

        return $this->outcome('hreflang_missing', true, 'verified_fact', 'P2');
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function cacheDrift(array $o): array
    {
        $revisions = array_filter([
            $o['production_sha'] ?? null, $o['deployment_revision'] ?? null,
            $o['frontend_release'] ?? null, $o['cache_revision'] ?? null,
            $o['active_pointer'] ?? null,
        ], 'is_string');
        if (($o['lkg_used_as_authority'] ?? false) === true) {
            return $this->outcome('cache_authority_hold', false, 'blocked', 'HOLD', true);
        }
        if (count(array_unique($revisions)) <= 1 && ($o['partial_deployment'] ?? false) === false) {
            return $this->outcome('valid_revision_alignment', false, 'verified_fact', 'P3');
        }

        return $this->outcome(
            ($o['deployment_revision'] ?? null) !== ($o['production_sha'] ?? null) ? 'deployment_revision_drift' : 'cache_revision_drift',
            true,
            'verified_fact',
            $this->impactSeverity($o),
        );
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function feedDrift(array $o): array
    {
        if (($o['private_feed_url'] ?? false) === true) {
            return $this->outcome('private_feed_leak', true, 'verified_fact', 'P1', true);
        }
        if (($o['feed_creates_authority'] ?? false) === true) {
            return $this->outcome('feed_authority_hold', false, 'blocked', 'HOLD', true);
        }
        if (($o['sitemap_matches_authority'] ?? false) === true
            && ($o['llms_matches_authority'] ?? false) === true
            && ($o['llms_full_matches_authority'] ?? false) === true) {
            return $this->outcome('valid_feed_alignment', false, 'verified_fact', 'P3');
        }

        return $this->outcome(
            ($o['sitemap_matches_authority'] ?? true) === false ? 'sitemap_drift' : 'llms_feed_drift',
            true,
            'verified_fact',
            'P2',
        );
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function sharedRoot(array $o): array
    {
        $shared = is_string($o['shared_component'] ?? null)
            && (int) ($o['affected_url_count'] ?? 0) > 1
            && (int) ($o['affected_family_count'] ?? 0) > 0;
        if ($shared) {
            return $this->outcome('shared_api_root_cause', true, 'verified_fact', $this->impactSeverity($o), false, [], true);
        }

        return $this->outcome('independent_url_root_causes', true, 'verified_fact', 'P2');
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $context @param array<string, mixed> $outcome @return array<string, mixed> */
    private function findingOutput(array $request, array $context, array $outcome): array
    {
        $payload = (array) $context['payload'];
        $evidenceRefs = array_values(array_map(static fn (array $ref): string => (string) ($ref['bundle_hash'] ?? ''), (array) $context['bundle_refs']));
        $refs = $this->contexts->sanitizePublicReferences((array) ($request['requested_scope']['sanitized_public_refs'] ?? []));
        $affectedUrls = max(0, (int) ($payload['affected_url_count'] ?? count($refs)));
        $affectedFamilies = max(0, (int) ($payload['affected_family_count'] ?? ($affectedUrls > 0 ? 1 : 0)));
        $shared = (bool) $outcome['shared'];
        $scope = [
            'scope_kind' => $shared ? 'shared_layer' : ($affectedUrls === 1 ? 'single_url' : ($affectedUrls > 1 ? 'url_cohort' : 'unknown')),
            'page_family' => (string) $request['page_family'],
            'locale' => (string) $request['locale'],
            'sanitized_public_refs' => $refs,
            'affected_url_count' => $affectedUrls,
            'affected_family_count' => $affectedFamilies,
            'shared_component' => $shared ? (string) ($payload['shared_component'] ?? 'public_api') : null,
        ];
        $verifiedFacts = $outcome['state'] === 'verified_fact' ? [[
            'fact_code' => $outcome['code'],
            'evidence_refs' => $evidenceRefs,
            'reproducible' => true,
        ]] : [];
        $hypotheses = [];
        if (in_array($outcome['state'], ['supported_hypothesis', 'unverified_hypothesis'], true)) {
            $hypotheses[] = [
                'hypothesis_id' => $this->hasher->hash([$request['diagnosis_id'], $outcome['code']]),
                'label' => $outcome['code'],
                'evidence_state' => $outcome['state'],
                'supporting_evidence_refs' => $evidenceRefs,
                'falsification_checks' => $outcome['checks'] === [] ? ['collect_current_direct_evidence'] : $outcome['checks'],
            ];
        }
        $hold = (bool) $outcome['hold'];
        $gap = $hold ? [
            'gap_code' => $outcome['code'],
            'missing_evidence' => ['fresh_current_authority_and_runtime_evidence'],
            'blocked_decision' => 'technical_discovery_review',
            'required_owner' => 'backend_or_sre',
            'acceptance_check' => 'rebuild_verified_context_on_current_revisions',
        ] : null;
        $owner = $this->owner((string) $outcome['code']);
        $recommendation = [
            'kind' => $outcome['issue'] && ! $hold ? 'repair_candidate' : 'no_execution_candidate',
            'owner' => $owner,
            'candidate_code' => $outcome['issue'] && ! $hold ? 'repair_'.$outcome['code'] : 'none',
            'execution_allowed' => false,
        ];
        $finding = [
            'finding_id' => $this->hasher->hash([$request['diagnosis_id'], $outcome['code'], $context['context_hash']]),
            'finding_version' => 1,
            'detector_code' => $outcome['code'],
            'page_family' => $request['page_family'],
            'locale' => $request['locale'],
            'affected_scope' => $scope,
            'evidence_refs' => $evidenceRefs,
            'evidence_state' => $outcome['state'],
            'authority_revision' => $request['authority_revision'],
            'runtime_revision' => $request['runtime_revision'],
            'deployment_revision' => $request['deployment_revision'],
            'severity' => $outcome['severity'],
            'verified_facts' => $verifiedFacts,
            'root_cause_hypotheses' => $hypotheses,
            'confidence' => $this->confidence($outcome['state'], $payload),
            'shared_layer_impact' => $shared,
            'blast_radius' => ['affected_family_count' => $affectedFamilies, 'affected_url_count' => $affectedUrls],
            'recommended_owner' => $owner,
            'recommended_path' => $recommendation,
            'acceptance_checks' => ['current_authority_parity', 'current_revision_runtime_recheck', 'private_negative_set_zero'],
            'evidence_gap' => $gap,
            'hold_reason' => $hold ? $outcome['code'] : null,
            'discovery_decision' => $hold ? 'HOLD_DISCOVERY' : 'ALLOW_DISCOVERY_REVIEW',
            'execution_allowed' => false,
            'write_permission' => false,
            'search_submission_allowed' => false,
        ];
        $output = [
            'output_id' => $this->hasher->hash([$finding['finding_id'], $request['request_hash']]),
            'output_version' => 1,
            'diagnosis_id' => $request['diagnosis_id'] ?? 'diagnosis:held',
            'status' => $hold ? 'HOLD' : 'READY',
            'verified_facts' => $verifiedFacts,
            'root_cause_hypotheses' => $hypotheses,
            'confidence' => $finding['confidence'],
            'affected_scope' => $scope,
            'recommended_path' => $recommendation,
            'evidence_gap' => $gap,
            'hold_reason' => $hold ? $outcome['code'] : null,
            'findings' => [$finding],
            'discovery_decision' => $finding['discovery_decision'],
            'execution_allowed' => false,
            'write_permission' => false,
            'search_submission_allowed' => false,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function hold(array $request, string $reason): array
    {
        $scope = [
            'scope_kind' => 'unknown', 'page_family' => (string) ($request['page_family'] ?? 'held'),
            'locale' => (string) ($request['locale'] ?? 'und'), 'sanitized_public_refs' => [],
            'affected_url_count' => 0, 'affected_family_count' => 0, 'shared_component' => null,
        ];
        $path = ['kind' => 'no_execution_candidate', 'owner' => 'none', 'candidate_code' => 'none', 'execution_allowed' => false];
        $gap = [
            'gap_code' => $reason, 'missing_evidence' => ['validated_ready_context'],
            'blocked_decision' => 'technical_discovery_review', 'required_owner' => 'none',
            'acceptance_check' => 'resolve_hold_and_rebuild_context',
        ];
        $output = [
            'output_id' => $this->hasher->hash([$request['diagnosis_id'] ?? 'held', $reason]),
            'output_version' => 1,
            'diagnosis_id' => $request['diagnosis_id'] ?? 'diagnosis:held',
            'status' => 'HOLD',
            'verified_facts' => [],
            'root_cause_hypotheses' => [],
            'confidence' => 'insufficient',
            'affected_scope' => $scope,
            'recommended_path' => $path,
            'evidence_gap' => $gap,
            'hold_reason' => $reason,
            'findings' => [],
            'discovery_decision' => 'HOLD_DISCOVERY',
            'execution_allowed' => false,
            'write_permission' => false,
            'search_submission_allowed' => false,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }

    /** @param array<string, mixed> $context */
    private function contextValid(array $context, string $requestHash): bool
    {
        return ($context['request_hash'] ?? null) === $requestHash
            && ($context['execution_allowed'] ?? null) === false
            && is_string($context['context_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($context, 'context_hash'), (string) $context['context_hash']);
    }

    /** @param array<string, mixed> $payload */
    private function forbiddenActionRequested(array $payload): bool
    {
        $observations = (array) ($payload['observations'] ?? []);
        $actions = array_merge((array) ($payload['requested_actions'] ?? []), (array) ($observations['requested_actions'] ?? []));
        if (array_intersect($actions, self::FORBIDDEN_ACTIONS) !== []) {
            return true;
        }

        return ($payload['requested_execution'] ?? false) === true
            || ($payload['requested_allow'] ?? false) === true
            || ($observations['requested_execution'] ?? false) === true
            || ($observations['requested_allow'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private function outcome(string $code, bool $issue, string $state, string $severity, bool $hold = false, array $checks = [], bool $shared = false): array
    {
        return compact('code', 'issue', 'state', 'severity', 'hold', 'checks', 'shared');
    }

    /** @param array<string, mixed> $observations */
    private function impactSeverity(array $observations): string
    {
        return (int) ($observations['affected_url_count'] ?? 0) >= 10 ? 'P1' : 'P2';
    }

    /** @param array<string, mixed> $payload */
    private function confidence(string $state, array $payload): string
    {
        if ($state === 'verified_fact'
            && ($payload['revision_consistent'] ?? false) === true
            && ($payload['repeat_observation'] ?? false) === true
            && (int) ($payload['source_count'] ?? 0) >= 2) {
            return 'high';
        }
        if ($state === 'verified_fact' && (int) ($payload['source_count'] ?? 0) >= 1) {
            return 'medium';
        }
        if ($state === 'supported_hypothesis') {
            return 'low';
        }

        return 'insufficient';
    }

    private function owner(string $code): string
    {
        return match (true) {
            str_contains($code, 'canonical'), str_contains($code, 'hreflang'), str_contains($code, 'shell') => 'frontend',
            str_contains($code, 'cache'), str_contains($code, 'deployment'), str_contains($code, '404') => 'sre',
            str_contains($code, 'feed'), str_contains($code, 'api') => 'backend',
            default => 'backend_or_sre',
        };
    }

    private function safeStatus(string $status): string
    {
        return in_array($status, [
            'EVIDENCE_HOLD', 'DEPENDENCY_HOLD', 'SOURCE_CAPABILITY_UNAVAILABLE',
            'MEASUREMENT_HOLD', 'PRIVATE_DATA_HOLD', 'AUTHORITY_CONFLICT_HOLD',
        ], true) ? $status : 'EVIDENCE_HOLD';
    }
}
