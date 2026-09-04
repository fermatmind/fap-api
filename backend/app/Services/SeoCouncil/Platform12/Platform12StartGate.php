<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class Platform12StartGate
{
    private const HASH_PATTERN = '/^[a-f0-9]{64}$/D';

    private const SHA_PATTERN = '/^[a-f0-9]{40}$/D';

    /** @var list<string> */
    private const ZERO_WRITE_GUARDS = [
        'active_manifest_count', 'trusted_signing_key_count', 'production_permissions',
        'cms_writes', 'publish_writes', 'url_truth_writes', 'canonical_writes',
        'robots_writes', 'search_writes', 'business_writes',
    ];

    public function __construct(
        private readonly Platform11ContractRegistry $platform11,
        private readonly Platform12ContractRegistry $contracts,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $platform11Closeout
     * @param  array{state:string,total_minutes:?int,observation_count:int}  $operatorTimeBaseline
     * @return array<string, mixed>
     */
    public function build(
        array $platform11Closeout,
        string $productionSha,
        string $urlTruthProjectionHash,
        array $operatorTimeBaseline,
        string $nightlyState = 'NOT_OBSERVED',
    ): array {
        $manifest = $this->platform11->manifest();
        $binding = $this->platform11->binding();
        $schema = $this->contracts->startReceiptSchema();
        $closeoutHashValid = preg_match(self::HASH_PATTERN, (string) ($platform11Closeout['receipt_hash'] ?? '')) === 1
            && hash_equals(
                $this->hasher->hashWithout($platform11Closeout, 'receipt_hash'),
                (string) ($platform11Closeout['receipt_hash'] ?? ''),
            );
        $writeGuardsClosed = ($platform11Closeout['post12_agent_write_enabled'] ?? null) === false
            && ($platform11Closeout['execution_allowed'] ?? null) === false;
        foreach (self::ZERO_WRITE_GUARDS as $guard) {
            $writeGuardsClosed = $writeGuardsClosed && ($platform11Closeout[$guard] ?? null) === 0;
        }
        $runtimeWriteGuards = [
            'scheduler_enabled' => (bool) config('seo_council.scheduler_enabled', false),
            'mission_execution_enabled' => (bool) config('seo_council.mission_execution_enabled', false),
            'model_runtime_enabled' => (bool) config('seo_council.model_runtime_enabled', false),
            'tool_broker_enabled' => (bool) config('seo_council.tool_broker_enabled', false),
        ];
        $runtimeDisabled = ! in_array(true, $runtimeWriteGuards, true);
        $foundationAllowed = $closeoutHashValid
            && preg_match(self::SHA_PATTERN, $productionSha) === 1
            && preg_match(self::HASH_PATTERN, $urlTruthProjectionHash) === 1
            && ($platform11Closeout['receipt_version'] ?? null) === 'seo.platform11_closeout.v1'
            && ($platform11Closeout['production_sha'] ?? null) === $productionSha
            && ($platform11Closeout['SEO-PLATFORM-11'] ?? null) === 'CLOSED'
            && ($platform11Closeout['ready_for_12'] ?? null) === true
            && $writeGuardsClosed
            && $runtimeDisabled;
        $measurementState = ($operatorTimeBaseline['state'] ?? null) === 'OBSERVED'
            && ($operatorTimeBaseline['observation_count'] ?? 0) > 0
                ? 'OBSERVED'
                : 'NO_OBSERVATIONS';

        $receipt = [
            'receipt_version' => 'seo.platform12_start_receipt.v1',
            'production_sha' => $productionSha,
            'foundation_state' => $foundationAllowed ? 'READY_FOR_FOUNDATION_BUILD' : 'START_HOLD',
            'foundation_build_allowed' => $foundationAllowed,
            'runtime_activation_allowed' => false,
            'runtime_activation_state' => $nightlyState === 'GREEN'
                ? '12A_08_NOT_AUTHORIZED'
                : 'NIGHTLY_AND_12A_08_HOLD',
            'nightly_state' => in_array($nightlyState, ['GREEN', 'HOLD', 'NOT_OBSERVED'], true)
                ? $nightlyState
                : 'HOLD',
            'measurement_baseline_state' => $measurementState,
            'dependency_refs' => [
                'platform11_closeout' => [
                    'version' => $platform11Closeout['receipt_version'] ?? null,
                    'hash' => $platform11Closeout['receipt_hash'] ?? null,
                ],
                'role_registry' => $manifest['registry_ref'],
                'role_capability_binding' => $manifest['binding_ref'],
                'policy_registry' => $manifest['policy_ref'],
                'tool_manifest' => [
                    'id' => 'seo.platform11_deterministic_tool_registry',
                    'version' => Platform11ContractRegistry::BINDING_VERSION,
                    'hash' => $this->hasher->hash($binding['deterministic_tool_registry']),
                ],
                'schema_vector' => [
                    'id' => 'seo.platform11_schema_vector',
                    'version' => Platform11ContractRegistry::MANIFEST_VERSION,
                    'hash' => $this->hasher->hash($platform11Closeout['schema_refs'] ?? []),
                ],
                'page_family_policy' => [
                    'id' => PageFamilyPolicyRegistry::VERSION,
                    'version' => PageFamilyPolicyRegistry::VERSION,
                    'hash' => $this->pageFamilies->policyHash(),
                ],
                'url_truth_projection' => [
                    'id' => 'seo.url_truth_projection',
                    'version' => 'observed',
                    'hash' => $urlTruthProjectionHash,
                ],
                'start_receipt_schema' => [
                    'id' => $schema['schema_id'],
                    'version' => $schema['schema_version'],
                    'hash' => $schema['schema_hash'],
                ],
            ],
            'write_guards' => [
                ...$runtimeWriteGuards,
                'post12_agent_write_enabled' => false,
                'L2' => 'artifact_only',
                'L3' => false,
                'L4' => false,
                'cms_agent_write' => false,
                'publish' => false,
                'canonical_write' => false,
                'robots_write' => false,
                'url_truth_write' => false,
                'search_submission' => false,
                'active_manifest_count' => 0,
                'trusted_signing_key_count' => 0,
            ],
            'SEO-PLATFORM-11' => $foundationAllowed ? 'CLOSED' : 'HOLD',
            'SEO-PLATFORM-12' => $foundationAllowed ? 'FOUNDATION_BUILD_ALLOWED' : 'START_HOLD',
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }
}
