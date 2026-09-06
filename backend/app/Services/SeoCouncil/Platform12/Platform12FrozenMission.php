<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use InvalidArgumentException;

final readonly class Platform12FrozenMission
{
    private function __construct(public array $envelope, public MissionRequestData $request) {}

    public static function freeze(array $slot, array $evidence, array $vector, string $catalogHash): self
    {
        $hasher = app(SeoRegistryHasher::class);
        $evidenceHash = $hasher->hash($evidence);
        $request = [
            'mission_id' => self::requestMissionId($slot),
            'idempotency_key' => 'p12:'.self::opaqueDigest($hasher->hash([$catalogHash, $slot['slot_key']])),
            'mission_type' => 'bounded_review', 'family' => 'other_public', 'locale' => 'zh-CN',
            'review_domain' => 'technical', 'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'p12:'.self::opaqueDigest($evidenceHash), 'bundle_version' => 1, 'bundle_hash' => $evidenceHash,
                'evidence_type' => 'runtime_health', 'status' => 'READY',
                'authority_revision' => $catalogHash,
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0,
                'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'resume_from' => null,
        ];
        $envelope = ['schema_version' => 'seo.platform12_frozen_mission.v1',
            'slot' => $slot, 'catalog_hash' => $catalogHash, 'version_vector' => $vector,
            'request' => $request, 'evidence' => $evidence];
        $envelope['envelope_hash'] = $hasher->hash($envelope);

        return self::restore($envelope);
    }

    public static function restore(array $envelope): self
    {
        $hasher = app(SeoRegistryHasher::class);
        if (! self::exactKeys($envelope, ['schema_version', 'slot', 'catalog_hash', 'version_vector', 'request', 'evidence', 'envelope_hash'])
            || ($envelope['schema_version'] ?? null) !== 'seo.platform12_frozen_mission.v1'
            || ! in_array(data_get($envelope, 'slot.mission_id'), Platform12DailyMissionSet::IDS, true)
            || ! is_array($envelope['evidence']) || ! is_array($envelope['request'])
            || ! hash_equals($hasher->hashWithout($envelope, 'envelope_hash'), (string) $envelope['envelope_hash'])
            || data_get($envelope, 'request.evidence_bundle_refs.0.bundle_hash') !== $hasher->hash($envelope['evidence'])
            || data_get($envelope, 'request.mission_id') !== self::requestMissionId($envelope['slot'])
            || data_get($envelope, 'request.requested_role') !== null
            || data_get($envelope, 'request.review_domain') !== 'technical'
            || data_get($envelope, 'request.autonomy') !== 'L0'
            || ! self::safeEvidence($envelope['evidence'])
            || strlen(json_encode($envelope, JSON_THROW_ON_ERROR)) > 131072) {
            throw new InvalidArgumentException('FROZEN_MISSION_INVALID');
        }
        // MySQL JSON normalizes object key order. Restore the existing v1 budget
        // contract's canonical order without accepting missing or extra fields.
        $budgetKeys = ['model_calls', 'tool_calls', 'external_calls', 'execution_seconds', 'retry_count', 'context_bytes', 'cost_amount', 'currency'];
        if (! is_array($envelope['request']['budget'] ?? null)
            || ! self::exactKeys($envelope['request']['budget'], $budgetKeys)) {
            throw new InvalidArgumentException('FROZEN_MISSION_BUDGET_INVALID');
        }
        $envelope['request']['budget'] = array_replace(array_fill_keys($budgetKeys, null), $envelope['request']['budget']);
        $dimensions = Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS;
        if (! self::exactKeys($envelope['version_vector'], $dimensions)) {
            throw new InvalidArgumentException('FROZEN_MISSION_VECTOR_INVALID');
        }
        $envelope['version_vector'] = array_replace(array_fill_keys($dimensions, null), $envelope['version_vector']);
        $request = MissionRequestData::fromInput($envelope['request'], 'scheduler', app(CouncilContractValidator::class), $hasher);

        return new self($envelope, $request);
    }

    private static function safeEvidence(array $evidence): bool
    {
        if (! self::exactKeys($evidence, ['input', 'sources', 'source_gaps', 'captured_at', 'expires_at'])
            || ! is_array($evidence['input']) || ! is_array($evidence['sources'])
            || ! is_array($evidence['source_gaps'])) {
            return false;
        }
        if (! array_is_list($evidence['sources']) || count($evidence['sources']) > 16
            || ! array_is_list($evidence['source_gaps']) || count($evidence['source_gaps']) > 16) {
            return false;
        }
        foreach ($evidence['sources'] as $source) {
            if (! is_array($source) || ! self::exactKeys($source, ['id', 'hash', 'read_at', 'observed_at'])
                || ! is_string($source['id']) || preg_match('/^[a-z][a-z_]{0,63}$/D', $source['id']) !== 1
                || ! is_string($source['hash']) || preg_match('/^[a-f0-9]{64}$/D', $source['hash']) !== 1
                || ! is_string($source['read_at'])
                || ($source['observed_at'] !== null && ! is_string($source['observed_at']))) {
                return false;
            }
        }
        foreach ($evidence['source_gaps'] as $gap) {
            if (! is_string($gap) || preg_match('/^[a-z][a-z_]{0,63}$/D', $gap) !== 1) {
                return false;
            }
        }
        $fields = [
            'gsc' => ['availability', 'scheduled_receipt_status', 'trigger_mode', 'mapping_state', 'data_quality_state', 'window_state', 'row_count', 'data_max_date'],
            'runtime' => ['core_runtime_state', 'public_api_state', 'readback_state', 'production_sha', 'readback_sha'],
            'authority' => ['availability', 'revision_hash', 'current_public_count'],
            'url_truth' => ['availability', 'revision_hash', 'current_url_truth_count', 'wrong_canonical_count', 'false_noindex_count'],
            'clustering' => ['availability', 'issue_count', 'clustered_issue_count', 'dedupe_candidate_count', 'dedupe_unique_count'],
            'd1_observation' => ['availability', 'candidate_count', 'observed_count'],
            'runtime_observation' => ['availability', 'observation_count'],
            'sitemap_observation' => ['availability', 'observation_count'],
            'private_routes' => ['tested_count', 'rejected_count'],
            'query_security' => ['hmac_state', 'key_version_state', 'pii_state'],
            'drift' => ['role', 'binding', 'policy', 'tool', 'schema', 'prompt'],
            'evidence_freshness' => ['total_count', 'fresh_count', 'expired_count'],
            'injection' => ['prompt_state', 'tool_metadata_state'],
            'tools' => ['requested_count', 'authorized_count'],
            'posture' => ['retention_state', 'egress_state'],
        ];
        foreach ($evidence['input'] as $key => $value) {
            if ($key === 'evaluated_at') {
                continue;
            }
            if (! isset($fields[$key]) || ($value !== null && (! is_array($value)
                || array_diff(array_keys($value), $fields[$key]) !== []))) {
                return false;
            }
            foreach ($value ?? [] as $scalar) {
                if (! is_scalar($scalar) && $scalar !== null) {
                    return false;
                }
            }
        }
        // Only this closed two-count aggregate may use the private_routes label.
        // No private route, identity or payload is present in it.
        $scan = $evidence;
        if (isset($scan['input']['private_routes'])) {
            $scan['input']['negative_route_counts'] = $scan['input']['private_routes'];
            unset($scan['input']['private_routes']);
        }

        return ! app(PolicyGatewayPrivacyGuard::class)->containsPrivateData($scan);
    }

    private static function requestMissionId(array $slot): string
    {
        $index = array_search($slot['mission_id'] ?? null, Platform12DailyMissionSet::IDS, true);
        if ($index === false || ! is_string($slot['slot_key'] ?? null)
            || ! preg_match('/^'.preg_quote($slot['mission_id'], '/').':(\d{4}-\d{2}-\d{2})(:acceptance)?$/D', $slot['slot_key'], $matches)) {
            throw new InvalidArgumentException('FROZEN_MISSION_SLOT_INVALID');
        }

        // An opaque closed ID avoids treating a catalog's safety vocabulary as
        // private payload; the ordinary request privacy validator stays unchanged.
        return 'seo.platform12.daily_check_'.$index.':'.$matches[1].($matches[2] ?? '');
    }

    private static function opaqueDigest(string $hash): string
    {
        // Bijective hex alphabet encoding: deterministic, no numeric PII lookalikes.
        return strtr($hash, '0123456789abcdef', 'abcdefghijklmnop');
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
