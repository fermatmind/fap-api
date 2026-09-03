<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use RuntimeException;

final class Platform11ContractRegistry
{
    public const REGISTRY_VERSION = '2.0.0';

    public const BINDING_VERSION = '4.0.0';

    public const POLICY_VERSION = '2.0.0';

    public const MANIFEST_VERSION = '4.0.0';

    public const MISSION_SCHEMA_VERSION = '2.0.0';

    public const REGISTRY_V1_FILE_SHA256 = '5810f56e9c0e8cdf766a1baddd1480a05ef7755a675b0ad6e2aa6b944509962b';

    public const BINDING_V1_FILE_SHA256 = 'adb88f7c02f1a44069d36d22ee5e6d0413071960aa2957e872d7547465252932';

    public const BINDING_V2_FILE_SHA256 = '655d25e227e33f08dc8e8589a414a6a755572450bb9f7da740f7b5d47df40a73';

    public const BINDING_V3_FILE_SHA256 = '923409ae6082c48944b4d77a8443fc26507abdb1ce10d64c998b91dfea7c1a44';

    public const POLICY_V1_FILE_SHA256 = '3cc280b76af8fc88b01742c00c01ffe91690fa577e7df122df2ed2615e1c05fe';

    /** @var array<string, string> */
    private const LEGACY_PROMPT_HASHES = [
        'career-content-agent.v1.md' => '20ae9e99fc251639dbbfaf34209dbdd03504e13b7d66470850615c2e7a2a14ad',
        'seo-expert-review.v1.md' => '7b68cec6c8a1a7d33dd2dc1d922f1c670128ce6c961a5eaba5451d039e15370c',
        'seo-independent-reviewer.v1.md' => '84ffa12aaa80d597c0104bdeabf05ed2aa0c0d015a7ef585e71e5c51926743f0',
        'seo-orchestrator.v1.md' => '397f5df2bc10925aaf80f729a3a55a1711da44355fef17373f7bb0f554cfde6a',
    ];

    /** @var list<string> */
    private const PUBLIC_FAMILIES = ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public'];

    /** @var list<string> */
    private const LOCALES = ['en', 'zh-CN'];

    /** @var array<string, array{role:string,autonomy:string,authority:string,capabilities:list<string>,prompt:string}> */
    public const DOMAINS = [
        'intent_query_ownership' => [
            'role' => 'seo.expert.content_entity_quality', 'autonomy' => 'L1', 'authority' => 'candidate_only',
            'capabilities' => ['seo.intent_query_ownership'], 'prompt' => 'seo.intent_query_ownership.prompt.v1.md',
        ],
        'editorial_draft' => [
            'role' => 'seo.expert.content_entity_quality', 'autonomy' => 'L1', 'authority' => 'candidate_only',
            'capabilities' => ['seo.content_claim_entity_audit', 'seo.editorial_cms_draft', 'seo.internal_link_recommendation'],
            'prompt' => 'seo.editorial_cms_draft.prompt.v1.md',
        ],
        'runtime_qa' => [
            'role' => 'seo.expert.public_content_stability', 'autonomy' => 'L0', 'authority' => 'review_verdict',
            'capabilities' => ['seo.runtime_qa_readback_attribution'], 'prompt' => 'seo.runtime_qa_readback_attribution.prompt.v1.md',
        ],
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function registry(): array
    {
        $registry = $this->json($this->path('docs/seo/generated/seo-agent-role-capability-registry.v1.json'));
        $registry['schema_version'] = 'seo.role_capability_registry.v2';
        $registry['registry_version'] = self::REGISTRY_VERSION;
        $registry['registry_status'] = 'frozen';
        $registry['supersedes'] = [
            'version' => '1.0.0',
            'file_sha256' => self::REGISTRY_V1_FILE_SHA256,
        ];
        foreach ($this->capabilities() as $capability) {
            $registry['capabilities'][] = $capability;
        }
        foreach ($registry['roles'] as &$role) {
            if (($role['role_id'] ?? null) === 'seo.independent_reviewer') {
                $role['prompt_ref'] = $this->promptRef('seo.independent_policy_experiment_safety_review.prompt.v1.md');
                unset($role['role_hash']);
                $role['role_hash'] = $this->hasher->hash($role);
            }
        }
        unset($role, $registry['registry_hash']);
        $registry['registry_hash'] = $this->hasher->hash($registry);

        return $registry;
    }

    /** @return array<string, mixed> */
    public function binding(): array
    {
        $binding = $this->json($this->path('resources/seo-agent/council/bindings/seo.role_capability_binding.v3.json'));
        $registry = $this->registry();
        $binding['schema_version'] = 'seo.role_capability_binding.v4';
        $binding['binding_version'] = self::BINDING_VERSION;
        $binding['supersedes'] = ['version' => '3.0.0', 'file_sha256' => self::BINDING_V3_FILE_SHA256];
        $binding['registry_ref'] = $this->reference('fermatmind.seo.role_capability_registry', self::REGISTRY_VERSION, (string) $registry['registry_hash']);
        $roleBindings = &$binding['role_bindings'];
        array_push(
            $roleBindings['seo.expert.content_entity_quality'],
            'seo.intent_query_ownership',
            'seo.content_claim_entity_audit',
            'seo.editorial_cms_draft',
            'seo.internal_link_recommendation',
        );
        $roleBindings['seo.expert.public_content_stability'][] = 'seo.runtime_qa_readback_attribution';
        $roleBindings['seo.independent_reviewer'][] = 'seo.independent_policy_experiment_safety_review';
        $binding['evidence_type_registry'] = array_values(array_unique(array_merge(
            $binding['evidence_type_registry'],
            ['query_owner', 'url_truth', 'page_family_policy', 'competitive_handoff', 'cms_readback', 'experiment_ledger', 'frozen_artifact', 'intent_ownership', 'public_content_authority'],
        )));
        foreach ($binding['missions'] as &$mission) {
            $mission['mission_version'] = self::BINDING_VERSION;
            if (($mission['mission_id'] ?? null) === 'bounded_review') {
                foreach (self::DOMAINS as $domain => $definition) {
                    $mission['selector']['variants'][] = [
                        'value' => $domain,
                        'required_capabilities' => $definition['capabilities'],
                        'eligible_roles' => [$definition['role']],
                        'deterministic_tools' => ['seo.platform11_deterministic_runner'],
                        'required_evidence' => match ($domain) {
                            'intent_query_ownership' => ['query_owner', 'url_truth', 'page_family_policy', 'search_measurement', 'competitive_handoff'],
                            'editorial_draft' => ['content_claim', 'entity', 'duplicate', 'lifecycle', 'url_truth', 'competitive_handoff'],
                            default => ['runtime_health', 'cms_readback', 'cache_projection', 'experiment_ledger'],
                        },
                        'reviewer_requirement' => 'selected_role_review_only',
                        'autonomy' => $definition['autonomy'],
                        'authority_ceiling' => $definition['authority'],
                        'prompt_ref' => $this->promptRef($definition['prompt']),
                    ];
                }
            }
            if (($mission['mission_id'] ?? null) === 'independent_registry_review') {
                $mission['required_capabilities'] = ['seo.independent_policy_experiment_safety_review'];
                $mission['deterministic_tools'] = ['seo.platform11_deterministic_runner'];
                $mission['required_evidence'] = ['frozen_artifact'];
                $mission['prompt_ref'] = $this->promptRef('seo.independent_policy_experiment_safety_review.prompt.v1.md');
                $mission['autonomy'] = 'L0';
            }
        }
        unset($mission);
        $binding['deterministic_tool_registry'][] = [
            'tool_id' => 'seo.platform11_deterministic_runner',
            'service_class' => Platform11Coordinator::class,
            'execution_kind' => 'deterministic_internal_service',
            'agent_tool_invocation' => false,
            'model_invocation' => false,
            'external_egress' => false,
            'write_permissions' => [],
            'execution_allowed' => false,
        ];
        $binding['negative_guarantees'] = array_merge((array) $binding['negative_guarantees'], [
            'new_agent_count' => 0,
            'delegation_count' => 0,
            'post12_agent_write_enabled' => false,
        ]);
        unset($binding['binding_hash']);
        $binding['binding_hash'] = $this->hasher->hash($binding);

        return $binding;
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        $policy = $this->json($this->path('resources/seo-agent/policy-gateway/seo.policy_gateway_registry.v1.json'));
        $registry = $this->registry();
        $binding = $this->binding();
        $mission = $this->missionSchema();
        $policy['registry_version'] = self::POLICY_VERSION;
        $policy['registry_state'] = 'frozen_deny_only';
        $policy['contract_manifest'] = 'seo.policy_gateway_contract_manifest.v2';
        $policy['supersedes'] = ['version' => '1.0.0', 'file_sha256' => self::POLICY_V1_FILE_SHA256];
        $policy['dependencies']['role_capability_registry'] = $this->reference((string) $registry['registry_id'], self::REGISTRY_VERSION, (string) $registry['registry_hash']);
        $policy['dependencies']['role_capability_binding'] = $this->reference((string) $binding['binding_id'], self::BINDING_VERSION, (string) $binding['binding_hash']);
        $policy['dependencies']['mission_request'] = $this->reference((string) $mission['schema_id'], self::MISSION_SCHEMA_VERSION, (string) $mission['schema_hash']);
        $policy['guards'] = array_merge((array) $policy['guards'], [
            'global_write_gate' => false,
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'model_invocation_enabled' => false,
            'tool_invocation_enabled' => false,
            'external_egress_enabled' => false,
            'post12_agent_write_enabled' => false,
        ]);
        $policy['domain_autonomy'] = [
            'intent_query_ownership' => ['L1'],
            'editorial_draft' => ['L1'],
            'runtime_qa' => ['L0'],
            'independent_registry_review' => ['L0'],
        ];
        unset($policy['registry_hash']);
        $policy['registry_hash'] = $this->hasher->hash($policy);

        return $policy;
    }

    /** @return array<string, mixed> */
    public function missionSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.mission_request.v2',
            'schema_version' => self::MISSION_SCHEMA_VERSION,
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'mission_id', 'idempotency_key', 'mission_type', 'family', 'locale', 'review_domain', 'requested_role', 'evidence_bundle_refs', 'autonomy', 'budget', 'tool_scope', 'egress_scope', 'mode_input'],
            'properties' => [
                'schema_version' => ['const' => 'seo.mission_request.v2'],
                'mission_id' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'],
                'idempotency_key' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'],
                'mission_type' => ['enum' => ['bounded_review', 'independent_registry_review']],
                'family' => ['enum' => self::PUBLIC_FAMILIES],
                'locale' => ['enum' => self::LOCALES],
                'review_domain' => ['enum' => ['intent_query_ownership', 'editorial_draft', 'runtime_qa', null]],
                'requested_role' => ['type' => ['string', 'null']],
                'evidence_bundle_refs' => ['type' => 'array', 'maxItems' => 32],
                'autonomy' => ['enum' => ['L0', 'L1']],
                'budget' => ['type' => 'object'],
                'tool_scope' => ['const' => []],
                'egress_scope' => ['const' => []],
                'mode_input' => ['type' => 'object'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, array<string, mixed>> */
    public function intentSchemas(): array
    {
        $fields = [
            'request' => ['query_hmac', 'query_cluster_id', 'intent_label', 'locale', 'query_family_key', 'evidence_refs'],
            'candidate' => ['intent', 'primary_owner_candidate', 'supporting_owners', 'authority_gap', 'locale_reasoning', 'evidence_refs', 'confidence', 'execution_allowed'],
            'cannibalization_cluster' => ['query_cluster_id', 'locale', 'owner_hashes', 'status', 'evidence_refs'],
            'owner_change_proposal' => ['current_owner_hash', 'proposed_owner_hash', 'reason_code', 'evidence_refs', 'execution_allowed'],
            'output' => ['intent', 'primary_owner_candidate', 'supporting_owners', 'cannibalization_cluster', 'authority_gap', 'locale_reasoning', 'owner_change_proposal', 'abstain_reason', 'evidence_refs', 'confidence', 'execution_allowed'],
            'receipt' => ['receipt_version', 'run_id', 'context_id', 'request_hash', 'output_hash', 'role_id', 'capability_id', 'status', 'negative_metrics', 'model_calls', 'tool_calls', 'external_calls', 'write_count', 'execution_allowed', 'receipt_hash'],
        ];
        $schemas = [];
        foreach ($fields as $name => $required) {
            $schema = [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'schema_id' => 'seo.intent_ownership_'.$name.'.v1',
                'schema_version' => '1.0.0',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => $required,
            ];
            $schema['schema_hash'] = $this->hasher->hash($schema);
            $schemas[$name] = $schema;
        }

        return $schemas;
    }

    /** @return array<string, mixed> */
    public function intentMode(): array
    {
        $schemas = $this->intentSchemas();
        $mode = [
            'mode_id' => 'seo.intent_query_ownership',
            'mode_version' => '1.0.0',
            'review_domain' => 'intent_query_ownership',
            'role_id' => 'seo.expert.content_entity_quality',
            'autonomy' => 'L1',
            'authority_ceiling' => 'candidate_only',
            'prompt_ref' => $this->promptRef('seo.intent_query_ownership.prompt.v1.md'),
            'schema_refs' => array_map(fn (array $schema): array => $this->reference((string) $schema['schema_id'], '1.0.0', (string) $schema['schema_hash']), $schemas),
            'tool_allowlist' => [],
            'egress_allowlist' => [],
            'write_permissions' => [],
            'allow_delegation' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'external_egress' => false,
            'execution_allowed' => false,
        ];
        $mode['mode_hash'] = $this->hasher->hash($mode);

        return $mode;
    }

    /** @return array<string, array<string, mixed>> */
    public function editorialSchemas(): array
    {
        $fields = [
            'request' => ['owner_candidate_hash', 'locale', 'source_claim_locale_map', 'authority_revision'],
            'draft_package' => [
                'title', 'seo_title', 'meta_description', 'refresh_brief', 'direct_answer', 'faq_or_modules',
                'internal_link_candidates', 'source_claim_locale_map', 'schema_candidate', 'duplicate_risk',
                'material_change', 'page_necessity', 'information_gain', 'template_overlap',
                'locale_specific_value', 'scaled_content_risk', 'evidence_refs', 'authority_revision', 'package_hash',
            ],
            'output' => ['status', 'draft_emitted', 'hold_reason', 'draft_package', 'artifact_only', 'dry_run_only', 'cms_write', 'publish', 'execution_allowed'],
            'receipt' => ['receipt_version', 'run_id', 'context_id', 'request_hash', 'output_hash', 'role_id', 'capability_sequence', 'role_call_count', 'status', 'negative_metrics', 'model_calls', 'tool_calls', 'external_calls', 'write_count', 'execution_allowed', 'receipt_hash'],
        ];
        $schemas = [];
        foreach ($fields as $name => $required) {
            $schema = [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'schema_id' => 'seo.editorial_draft_'.$name.'.v1',
                'schema_version' => '1.0.0',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => $required,
            ];
            $schema['schema_hash'] = $this->hasher->hash($schema);
            $schemas[$name] = $schema;
        }

        return $schemas;
    }

    /** @return array<string, mixed> */
    public function editorialMode(): array
    {
        $schemas = $this->editorialSchemas();
        $mode = [
            'mode_id' => 'seo.editorial_cms_draft',
            'mode_version' => '1.0.0',
            'review_domain' => 'editorial_draft',
            'role_id' => 'seo.expert.content_entity_quality',
            'capability_sequence' => [
                'seo.content_claim_entity_audit',
                'seo.editorial_cms_draft',
                'seo.internal_link_recommendation',
            ],
            'autonomy' => 'L1',
            'authority_ceiling' => 'candidate_only',
            'prompt_refs' => [
                $this->promptRef('seo.content_claim_entity_audit.prompt.v1.md'),
                $this->promptRef('seo.editorial_cms_draft.prompt.v1.md'),
                $this->promptRef('seo.internal_link_recommendation.prompt.v1.md'),
            ],
            'schema_refs' => array_map(fn (array $schema): array => $this->reference((string) $schema['schema_id'], '1.0.0', (string) $schema['schema_hash']), $schemas),
            'artifact_only' => true,
            'dry_run_only' => true,
            'cms_write' => false,
            'publish' => false,
            'tool_allowlist' => [],
            'egress_allowlist' => [],
            'write_permissions' => [],
            'allow_delegation' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'external_egress' => false,
            'execution_allowed' => false,
        ];
        $mode['mode_hash'] = $this->hasher->hash($mode);

        return $mode;
    }

    /** @return array<string, mixed> */
    public function l2ManifestSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.post12_l2_cms_draft_manifest.v1',
            'schema_version' => '1.0.0',
            'state' => 'IMPLEMENTED_WRITE_DISABLED',
            'additionalProperties' => false,
            'allowed_fields' => ['title', 'seo_title', 'meta_description', 'draft_body', 'draft_faq', 'draft_modules', 'draft_internal_links', 'draft_schema'],
            'permanently_forbidden_fields' => ['slug', 'canonical', 'robots', 'noindex', 'publication_state', 'publish', 'unpublish', 'delete', 'redirect', 'scoring', 'private_result', 'url_truth', 'search_submission'],
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'adapter' => Post12L2DraftWriteAdapter::class,
            'execution_allowed' => false,
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $registry = $this->registry();
        $binding = $this->binding();
        $policy = $this->policy();
        $mission = $this->missionSchema();
        $mode = $this->intentMode();
        $editorial = $this->editorialMode();
        $l2 = $this->l2ManifestSchema();
        $manifest = [
            'manifest_id' => 'seo.council_contract_manifest.v4',
            'manifest_version' => self::MANIFEST_VERSION,
            'production_baseline_sha' => 'f136b43af2af10a0b70608b7403fe75eafda8dd4',
            'registry_ref' => $this->reference((string) $registry['registry_id'], self::REGISTRY_VERSION, (string) $registry['registry_hash']),
            'binding_ref' => $this->reference((string) $binding['binding_id'], self::BINDING_VERSION, (string) $binding['binding_hash']),
            'mission_request_ref' => $this->reference((string) $mission['schema_id'], self::MISSION_SCHEMA_VERSION, (string) $mission['schema_hash']),
            'policy_ref' => $this->reference((string) $policy['registry_id'], self::POLICY_VERSION, (string) $policy['registry_hash']),
            'intent_mode_ref' => $this->reference((string) $mode['mode_id'], '1.0.0', (string) $mode['mode_hash']),
            'editorial_mode_ref' => $this->reference((string) $editorial['mode_id'], '1.0.0', (string) $editorial['mode_hash']),
            'l2_manifest_schema_ref' => $this->reference((string) $l2['schema_id'], '1.0.0', (string) $l2['schema_hash']),
            'evidence_privacy_ref' => $this->fileRef('seo.evidence_contract_manifest.v5', '5.0.0', 'docs/seo/generated/seo-agent-evidence-contract-manifest.v5.json'),
            'policy_gateway_ref' => $this->fileRef('seo.policy_gateway_contract_manifest.v1', '1.0.0', 'docs/seo/generated/seo-policy-gateway-contract-manifest.v1.json'),
            'legacy_frozen_files' => $this->legacyFiles(),
            'global_guards' => $policy['guards'],
            'role_count' => count($registry['roles']),
            'seo_orchestrator_count' => count(array_filter($registry['roles'], static fn (array $role): bool => ($role['role_id'] ?? null) === 'seo.orchestrator')),
            'new_agent_count' => 0,
            'delegation_count' => 0,
            'execution_allowed' => false,
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    public function verifyGenerated(): bool
    {
        try {
            foreach ($this->legacyFiles() as $legacy) {
                $relative = preg_replace('#^backend/#', '', (string) $legacy['path']) ?: '';
                if (! hash_equals((string) $legacy['sha256'], hash_file('sha256', $this->path($relative)) ?: '')) {
                    return false;
                }
            }
            $expected = $this->artifacts();
            foreach ($expected as $relative => $value) {
                $actual = $this->json($this->path($relative));
                if (! hash_equals($this->hasher->hash($value), $this->hasher->hash($actual))) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function artifacts(): array
    {
        $schemas = $this->intentSchemas();
        $editorialSchemas = $this->editorialSchemas();
        $artifacts = [
            'docs/seo/generated/seo-agent-role-capability-registry.v2.json' => $this->registry(),
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v4.json' => $this->binding(),
            'resources/seo-agent/policy-gateway/seo.policy_gateway_registry.v2.json' => $this->policy(),
            'resources/seo-agent/council/schemas/seo.mission_request.v2.schema.json' => $this->missionSchema(),
            'resources/seo-agent/council/platform11/intent-ownership/seo.intent_ownership_mode.v1.json' => $this->intentMode(),
            'resources/seo-agent/council/platform11/editorial-draft/seo.editorial_draft_mode.v1.json' => $this->editorialMode(),
            'resources/seo-agent/council/platform11/editorial-draft/seo.post12_l2_cms_draft_manifest.v1.schema.json' => $this->l2ManifestSchema(),
            'docs/seo/generated/seo-council-contract-manifest.v4.json' => $this->manifest(),
        ];
        foreach ($schemas as $name => $schema) {
            $artifacts['resources/seo-agent/council/platform11/intent-ownership/schemas/seo.intent_ownership_'.$name.'.v1.schema.json'] = $schema;
        }
        foreach ($editorialSchemas as $name => $schema) {
            $artifacts['resources/seo-agent/council/platform11/editorial-draft/schemas/seo.editorial_draft_'.$name.'.v1.schema.json'] = $schema;
        }

        return $artifacts;
    }

    /** @return list<array<string, mixed>> */
    private function capabilities(): array
    {
        $definitions = [
            'seo.intent_query_ownership' => ['Query Owner and URL Truth read models', 'seo.intent_query_ownership.prompt.v1.md'],
            'seo.content_claim_entity_audit' => ['Evidence Bundle and public content authority', 'seo.content_claim_entity_audit.prompt.v1.md'],
            'seo.editorial_cms_draft' => ['Evidence-backed draft package', 'seo.editorial_cms_draft.prompt.v1.md'],
            'seo.internal_link_recommendation' => ['Public URL Truth read model', 'seo.internal_link_recommendation.prompt.v1.md'],
            'seo.runtime_qa_readback_attribution' => ['Runtime readback and Experiment Ledger', 'seo.runtime_qa_readback_attribution.prompt.v1.md'],
            'seo.independent_policy_experiment_safety_review' => ['Frozen artifact and policy manifests', 'seo.independent_policy_experiment_safety_review.prompt.v1.md'],
        ];
        $capabilities = [];
        foreach ($definitions as $id => [$authority, $prompt]) {
            $capability = [
                'capability_id' => $id,
                'capability_version' => '1.0.0',
                'classification' => 'deterministic_review_mode',
                'authority_source' => $authority,
                'runtime_state' => 'dormant_not_authorized',
                'agent_invocable' => false,
                'write_boundaries' => [],
                'write_permissions' => [],
                'model_invocation' => false,
                'external_egress' => false,
                'allow_delegation' => false,
                'execution_allowed' => false,
                'prompt_ref' => $this->promptRef($prompt),
                'owner_repository' => 'fap-api',
            ];
            $capability['capability_hash'] = $this->hasher->hash($capability);
            $capabilities[] = $capability;
        }

        return $capabilities;
    }

    /** @return list<array{path:string,sha256:string}> */
    private function legacyFiles(): array
    {
        $files = [
            'docs/seo/generated/seo-agent-role-capability-registry.v1.json' => self::REGISTRY_V1_FILE_SHA256,
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v1.json' => self::BINDING_V1_FILE_SHA256,
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v2.json' => self::BINDING_V2_FILE_SHA256,
            'resources/seo-agent/council/bindings/seo.role_capability_binding.v3.json' => self::BINDING_V3_FILE_SHA256,
            'resources/seo-agent/policy-gateway/seo.policy_gateway_registry.v1.json' => self::POLICY_V1_FILE_SHA256,
        ];
        foreach (self::LEGACY_PROMPT_HASHES as $file => $hash) {
            $files['resources/seo-agent/prompts/'.$file] = $hash;
        }

        return array_map(static fn (string $path, string $hash): array => ['path' => 'backend/'.$path, 'sha256' => $hash], array_keys($files), array_values($files));
    }

    /** @return array{id:string,version:string,hash:string} */
    private function promptRef(string $file): array
    {
        $path = $this->path('resources/seo-agent/council/platform11/prompts/'.$file);

        return $this->reference(str_replace('.v1.md', '', $file), '1.0.0', $this->hasher->promptHash((string) file_get_contents($path)));
    }

    /** @return array{id:string,version:string,hash:string,path:string} */
    private function fileRef(string $id, string $version, string $relative): array
    {
        $path = $this->path($relative);

        return [...$this->reference($id, $version, hash_file('sha256', $path) ?: ''), 'path' => 'backend/'.$relative];
    }

    /** @return array{id:string,version:string,hash:string} */
    private function reference(string $id, string $version, string $hash): array
    {
        return ['id' => $id, 'version' => $version, 'hash' => $hash];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('PLATFORM11_CONTRACT_INVALID');
        }

        return $value;
    }

    private function path(string $relative): string
    {
        return base_path($relative);
    }
}
