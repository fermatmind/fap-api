<?php

declare(strict_types=1);

namespace App\Services\SeoAgentGovernance;

use JsonException;
use RuntimeException;

final class SeoRoleCapabilityRegistry
{
    public const REGISTRY_ID = 'fermatmind.seo.role_capability_registry';

    public const REGISTRY_VERSION = '1.0.0';

    public const BACKEND_ID = 'fap_api.seo_agent_backend.v1';

    private const PUBLIC_PAGE_FAMILIES = [
        'tests',
        'articles_topics',
        'career',
        'personality',
        'trust_method_help',
        'other_public',
    ];

    private const LEGACY_SEO_AGENT_COMMANDS = [
        'article-cms-publish-canary',
        'article-draft-claim-risk-qa',
        'article-draft-preview-runtime-qa',
        'article-post-publish-propagation-dry-run',
        'article-release',
        'auto-rollback-guard',
        'cms-draft-package-dry-run',
        'cms-draft-payload-repair-canary',
        'cms-draft-readback-qa',
        'cms-draft-write',
        'cms-faq-gap-scan',
        'cms-publish-auto-canary',
        'cms-publish-canary',
        'cms-tdk-gap-scan',
        'codex-review-runner',
        'compile-mode-c-package',
        'gsc-batch-draft-qa-support',
        'gsc-cohort-handoff',
        'gsc-draft-publish-gate-readiness',
        'gsc-opportunity-auto-draft',
        'gsc-post-publish-feedback',
        'gsc-remaining-candidate-batch-plan',
        'l5a-candidate-review',
        'l5a-cms-draft-write-canary',
        'l5a-contentpage-publish-canary',
        'l5a-indexnow-submit-canary',
        'opportunity-aggregate',
        'post-publish-indexnow-auto',
        'post-publish-search-submit',
        'priority-queue-scheduler',
        'replace-article-covers',
        'run',
        'runtime-seo-qa-scan',
        'weekly-draft-write-auto',
        'weekly-readonly-runner',
    ];

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly SeoPromptRegistry $prompts,
        private readonly SeoPolicyRegistry $policies,
    ) {}

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function registry(): array
    {
        $registry = $this->registryWithoutHash();
        $registry['registry_hash'] = $this->hasher->hash($registry);

        return $registry;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function registryWithoutHash(): array
    {
        $prompts = $this->prompts->definitions();
        $policies = $this->policies->definitions();
        $schemas = $this->schemas();

        return [
            'schema_version' => 'seo.role_capability_registry.v1',
            'registry_id' => self::REGISTRY_ID,
            'registry_version' => self::REGISTRY_VERSION,
            'registry_status' => 'frozen',
            'owner_repository' => 'fap-api',
            'source_repository_snapshots' => [
                ['repository' => 'fap-api', 'sha' => 'c80612517e2c6f83586d46b579c1c8353205514d', 'evidence_state' => 'verified'],
                ['repository' => 'fap-web', 'sha' => '16b5e655b4ae3e2c74bb265b90568676bbcd55dc', 'evidence_state' => 'verified'],
            ],
            'architecture_decisions' => [
                'runtime_framework' => 'laravel_native',
                'orchestration_pattern' => 'flow_first',
                'crewai_runtime_dependency' => false,
                'shared_agent_memory' => false,
                'delegation_default' => false,
                'external_trace_export' => false,
            ],
            'global_guards' => [
                'read_only_gsc' => true,
                'search_submission_allowed' => false,
                'post12_agent_write_enabled' => false,
                'l4_state' => 'dormant_not_authorized',
                'agent_default_write_permission' => false,
                'deterministic_system_final_veto' => true,
                'model_invocation_enabled' => false,
                'runtime_model_invocation_enabled' => false,
                'fap_web_agent_authority' => false,
            ],
            'roles' => $this->roles($prompts, $policies, $schemas),
            'capabilities' => $this->capabilities(),
            'entrypoint_bindings' => array_map(static fn (string $type): array => [
                'entrypoint_type' => $type,
                'backend_id' => self::BACKEND_ID,
                'binding_state' => 'frozen_contract_only',
                'execution_authorized' => false,
            ], ['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui']),
            'superseded_assets' => $this->supersededAssets(),
        ];
    }

    /**
     * @return array<string, array{id:string,version:string,hash:string,path:string}>
     * @throws JsonException
     */
    public function schemas(): array
    {
        $definitions = [];
        foreach (['seo-role-input.v1.schema.json', 'seo-role-output.v1.schema.json'] as $file) {
            $path = resource_path('seo-agent/schemas/'.$file);
            $bytes = file_get_contents($path);
            if (! is_string($bytes)) {
                throw new RuntimeException('SEO schema is unreadable: '.$file);
            }

            $schema = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($schema) || ! is_string($schema['schema_id'] ?? null) || ! is_string($schema['schema_version'] ?? null)) {
                throw new RuntimeException('SEO schema identity is invalid: '.$file);
            }

            $definitions[$schema['schema_id']] = [
                'id' => $schema['schema_id'],
                'version' => $schema['schema_version'],
                'hash' => $this->hasher->hash($schema),
                'path' => 'backend/resources/seo-agent/schemas/'.$file,
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<string, array{id:string,version:string,hash:string,path:string}>  $prompts
     * @param  array<string, array{id:string,version:string,hash:string,path:string}>  $policies
     * @param  array<string, array{id:string,version:string,hash:string,path:string}>  $schemas
     * @return list<array<string, mixed>>
     */
    private function roles(array $prompts, array $policies, array $schemas): array
    {
        $input = $this->reference($schemas['seo.role_input']);
        $output = $this->reference($schemas['seo.role_output']);
        $executionHold = $this->reference($policies['seo.execution_hold']);
        $evidenceBoundary = $this->reference($policies['seo.evidence_boundary']);
        $releaseSeparation = $this->reference($policies['seo.release_separation']);

        $roles = [
            $this->role(
                'seo.orchestrator',
                'active_agent',
                'orchestrator',
                'Coordinate fixed Laravel flows and emit recommendations without executing tools.',
                ['global_portfolio', 'weekly_opportunity', 'monthly_portfolio', 'breakthrough_sprint'],
                $input,
                $output,
                $this->reference($prompts['seo.orchestrator.prompt']),
                [$executionHold, $evidenceBoundary, $releaseSeparation],
                self::PUBLIC_PAGE_FAMILIES,
                'recommendation_only',
            ),
        ];

        $experts = [
            ['seo.expert.technical_search_authority', 'Review backend/frontend/live search authority parity.'],
            ['seo.expert.search_analytics_measurement', 'Review sanitized read-only search measurement evidence.'],
            ['seo.expert.content_entity_quality', 'Review content, entity, claim, and duplication evidence.'],
            ['seo.expert.competitor_research', 'Review public competitor structure without copying content.'],
            ['seo.expert.public_content_stability', 'Review public runtime, cache, and projection stability evidence.'],
            ['seo.expert.commercial_funnel_cro', 'Review aggregate public-to-product funnel evidence.'],
        ];

        foreach ($experts as [$roleId, $goal]) {
            $roles[] = $this->role(
                $roleId,
                'review_mode',
                'expert_mode',
                $goal,
                ['bounded_review'],
                $input,
                $output,
                $this->reference($prompts['seo.expert.review.prompt']),
                [$executionHold, $evidenceBoundary],
                self::PUBLIC_PAGE_FAMILIES,
                'review_verdict',
            );
        }

        $roles[] = $this->role(
            'seo.independent_reviewer',
            'review_mode',
            'independent_reviewer',
            'Independently verify evidence, policy, authority, and negative guarantees.',
            ['independent_registry_review'],
            $input,
            $output,
            $this->reference($prompts['seo.independent_reviewer.prompt']),
            [$executionHold, $evidenceBoundary, $releaseSeparation],
            self::PUBLIC_PAGE_FAMILIES,
            'review_verdict',
        );

        $roles[] = $this->role(
            'career.content_agent',
            'bounded_capability',
            'candidate_producer',
            'Produce bounded Career candidates, receipts, and a release-authority handoff only.',
            ['career_candidate_generation'],
            $input,
            $output,
            $this->reference($prompts['career.content_agent.prompt']),
            [$executionHold, $evidenceBoundary, $releaseSeparation],
            ['career'],
            'candidate_only',
        );

        return $roles;
    }

    /**
     * @param  array{id:string,version:string,hash:string}  $input
     * @param  array{id:string,version:string,hash:string}  $output
     * @param  array{id:string,version:string,hash:string}  $prompt
     * @param  list<array{id:string,version:string,hash:string}>  $policies
     * @param  list<string>  $pageFamilies
     * @return array<string, mixed>
     */
    private function role(
        string $roleId,
        string $classification,
        string $executionKind,
        string $goal,
        array $missions,
        array $input,
        array $output,
        array $prompt,
        array $policies,
        array $pageFamilies,
        string $authorityCeiling,
    ): array {
        $role = [
            'role_id' => $roleId,
            'role_version' => '1.0.0',
            'classification' => $classification,
            'execution_kind' => $executionKind,
            'runtime_state' => 'dormant_not_authorized',
            'goal' => $goal,
            'allowed_missions' => $missions,
            'input_schema' => $input,
            'output_schema' => $output,
            'prompt_ref' => $prompt,
            'policy_refs' => $policies,
            'tool_allowlist' => [],
            'egress_allowlist' => [],
            'page_family_scope' => $pageFamilies,
            'locale_scope' => ['en', 'zh-CN'],
            'authority_ceiling' => $authorityCeiling,
            'max_model_calls' => 0,
            'max_tool_calls' => 0,
            'max_execution_seconds' => 0,
            'max_retry_count' => 0,
            'max_cost' => ['amount' => 0, 'currency' => 'USD'],
            'allow_delegation' => false,
            'write_permissions' => [],
            'stop_conditions' => [
                'execution_not_authorized',
                'authority_unknown_or_private_excluded',
                'evidence_missing_stale_or_blocked',
                'write_or_external_egress_requested',
            ],
            'evidence_requirements' => [
                'current_authority_source',
                'sanitized_evidence_refs',
                'explicit_evidence_state',
                'deterministic_negative_guarantees',
            ],
            'owner_repository' => 'fap-api',
        ];
        $role['role_hash'] = $this->hasher->hash($role);

        return $role;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function capabilities(): array
    {
        $rows = [
            ['seo.runtime_health_review', 'review_mode', 'SeoIntel runtime read models', false, []],
            ['seo.search_measurement_review', 'review_mode', 'read-only GSC materialized evidence', false, []],
            ['seo.content_claim_review', 'review_mode', 'deterministic claim and content reviewers', false, []],
            ['seo.cms_gap_scan', 'deterministic_tool', 'CMS FAQ/TDK scanners', false, []],
            ['seo.decision_authority', 'deterministic_tool', 'SEO-PLATFORM-09 Decision authority', false, []],
            ['seo.material_lifecycle_review', 'review_mode', 'SEO-PLATFORM-10 lifecycle read model', false, []],
            ['seo.rollback_guard', 'bounded_capability', 'deterministic rollback guard', false, []],
            ['seo.cms_writer', 'bounded_capability', 'CMS draft/publish services', false, ['cms']],
            ['seo.search_submission', 'bounded_capability', 'Search Channel live executor', false, ['search_provider']],
            ['seo.url_truth_writer', 'bounded_capability', 'URL Truth write services', false, ['database']],
            ['career.content_candidate', 'bounded_capability', 'Career Content candidate harness', false, ['temporary_filesystem']],
            ['career.current_merger', 'deterministic_tool', 'Career release authority merger', false, ['repository_current']],
            ['career.page_assembly_preview', 'deterministic_tool', 'Page Assembly preview reader', false, []],
            ['career.page_assembly_import', 'deterministic_tool', 'controlled staging/approval/production importer', false, ['database']],
            ['personality.approval_queue_write', 'bounded_capability', 'Personality approval queue writer', false, ['database']],
            ['personality.approval_queue_review', 'review_mode', 'Personality approval queue read model', false, []],
            ['personality.big_five_draft', 'bounded_capability', 'Big Five draft writer', false, ['cms_draft']],
            ['personality.big_five_promote', 'bounded_capability', 'Big Five promotion service', false, ['cms_publish']],
            ['personality.post_promotion_search_review', 'review_mode', 'post-promotion search readiness review', false, []],
            ['seo.release_separation_policy', 'contract_only', 'release separation policy', false, []],
        ];

        return array_map(function (array $row): array {
            $capability = [
                'capability_id' => $row[0],
                'capability_version' => '1.0.0',
                'classification' => $row[1],
                'authority_source' => $row[2],
                'runtime_state' => 'dormant_not_authorized',
                'agent_invocable' => $row[3],
                'write_boundaries' => $row[4],
                'model_invocation' => false,
                'external_egress' => false,
                'owner_repository' => 'fap-api',
            ];
            $capability['capability_hash'] = $this->hasher->hash($capability);

            return $capability;
        }, $rows);
    }

    /**
     * @return list<array<string, string>>
     */
    private function supersededAssets(): array
    {
        $assets = array_map(static fn (string $command): array => [
            'asset_id' => 'fap-api.cli.seo-agent.'.$command,
            'classification' => 'historical_superseded',
            'replacement' => $command === 'opportunity-aggregate' ? 'seo.decision_authority' : 'registry capability or deterministic domain authority',
        ], self::LEGACY_SEO_AGENT_COMMANDS);

        foreach ([
            'agent_os_release_coordination',
            'seo_geo_control',
            'career_content_graph',
            'release_guard_agent',
            'fapweb_code_pr_writer',
        ] as $asset) {
            $assets[] = [
                'asset_id' => 'fap-web.legacy.'.$asset,
                'classification' => 'historical_superseded',
                'replacement' => $asset === 'release_guard_agent' ? 'seo.release_separation' : 'fap-api canonical registry',
            ];
        }

        return $assets;
    }

    /**
     * @param  array{id:string,version:string,hash:string,path:string}  $definition
     * @return array{id:string,version:string,hash:string}
     */
    private function reference(array $definition): array
    {
        return [
            'id' => $definition['id'],
            'version' => $definition['version'],
            'hash' => $definition['hash'],
        ];
    }
}
