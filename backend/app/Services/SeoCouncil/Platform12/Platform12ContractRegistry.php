<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform12\Measurement\Platform12MetricContract;
use App\Services\SeoCouncil\Platform12\Model\Platform12BoundedModelContract;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationPolicyContract;
use App\Services\SeoCouncil\Platform12\Tool\Platform12ToolManifest;

final class Platform12ContractRegistry
{
    public const MISSION_CATALOG_VERSION = '1.10.0';

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly Platform11ContractRegistry $platform11,
        private readonly Platform12BoundedModelContract $boundedModel,
        private readonly Platform12ToolManifest $tools,
        private readonly Platform12NotificationPolicyContract $notifications,
        private readonly Platform12MetricContract $metrics,
    ) {}

    /** @return array<string, mixed> */
    public function startReceiptSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_start_receipt.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'receipt_version', 'production_sha', 'foundation_state', 'foundation_build_allowed',
                'runtime_activation_allowed', 'runtime_activation_state', 'nightly_state',
                'measurement_baseline_state', 'dependency_refs', 'write_guards',
                'SEO-PLATFORM-11', 'SEO-PLATFORM-12', 'receipt_hash',
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function missionCatalogSchema(): array
    {
        $reference = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'version', 'hash'],
            'properties' => [
                'id' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{0,127}$'],
                'version' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9][a-zA-Z0-9._-]{0,31}$'],
                'hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_mission_catalog.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'schema_version', 'catalog_id', 'catalog_version', 'catalog_state',
                'dependency_refs', 'missions', 'runtime_activation_allowed', 'catalog_hash',
            ],
            'properties' => [
                'schema_version' => ['const' => 'seo.platform12_mission_catalog.v1'],
                'catalog_id' => ['const' => 'fermatmind.seo.platform12_mission_catalog'],
                'catalog_version' => ['type' => 'string', 'pattern' => '^\\d+\\.\\d+\\.\\d+$'],
                'catalog_state' => ['const' => 'FOUNDATION_ONLY'],
                'dependency_refs' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'role_registry', 'role_capability_binding', 'policy_registry',
                        'notification_policy', 'tool_manifest', 'schema_vector',
                    ],
                    'properties' => [
                        'role_registry' => $reference,
                        'role_capability_binding' => $reference,
                        'policy_registry' => $reference,
                        'notification_policy' => $reference,
                        'tool_manifest' => $reference,
                        'schema_vector' => $reference,
                    ],
                ],
                'missions' => [
                    'type' => 'array',
                    'maxItems' => 128,
                    'items' => ['$ref' => '#/$defs/mission'],
                ],
                'runtime_activation_allowed' => ['const' => false],
                'catalog_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
            '$defs' => [
                'mission' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'mission_id', 'cadence', 'timezone', 'natural_slot', 'family', 'locale',
                        'review_domain', 'required_evidence', 'eligible_capability', 'priority',
                        'timeout_seconds', 'max_attempts', 'budgets', 'failure_policy', 'output_schema',
                    ],
                    'properties' => [
                        'mission_id' => ['type' => 'string', 'pattern' => '^seo\\.platform12\\.[a-z0-9][a-z0-9._-]{0,95}$'],
                        'cadence' => ['enum' => ['daily', 'weekly', 'monthly']],
                        'timezone' => ['const' => 'Asia/Shanghai'],
                        'natural_slot' => ['type' => 'string', 'pattern' => '^(daily|weekly|monthly):[A-Z0-9]{2,3}:(?:[01][0-9]|2[0-3]):[0-5][0-9]$'],
                        'family' => ['enum' => ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public']],
                        'locale' => ['enum' => ['en', 'zh-CN']],
                        'review_domain' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                        'required_evidence' => [
                            'type' => 'array', 'minItems' => 1, 'maxItems' => 32, 'uniqueItems' => true,
                            'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9._-]{0,63}$'],
                        ],
                        'eligible_capability' => ['type' => 'string', 'pattern' => '^seo\\.[a-z][a-z0-9._-]{0,95}$'],
                        'priority' => ['enum' => ['critical', 'high', 'normal', 'low']],
                        'timeout_seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 900],
                        'max_attempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                        'budgets' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['model_calls', 'model_input_tokens', 'model_output_tokens', 'tool_calls', 'cost_microusd'],
                            'properties' => [
                                'model_calls' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 8],
                                'model_input_tokens' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                                'model_output_tokens' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20000],
                                'tool_calls' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 32],
                                'cost_microusd' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000000],
                            ],
                        ],
                        'failure_policy' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['terminal_state', 'retry_strategy', 'initial_backoff_seconds', 'max_backoff_seconds'],
                            'properties' => [
                                'terminal_state' => ['enum' => ['HOLD', 'FAILED']],
                                'retry_strategy' => ['enum' => ['none', 'bounded_exponential']],
                                'initial_backoff_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 300],
                                'max_backoff_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1800],
                            ],
                        ],
                        'output_schema' => $reference,
                    ],
                    'allOf' => [
                        [
                            'if' => ['properties' => ['cadence' => ['const' => 'daily']]],
                            'then' => ['properties' => ['natural_slot' => ['pattern' => '^daily:ALL:(?:[01][0-9]|2[0-3]):[0-5][0-9]$']]],
                        ],
                        [
                            'if' => ['properties' => ['cadence' => ['const' => 'weekly']]],
                            'then' => ['properties' => ['natural_slot' => ['pattern' => '^weekly:(MON|TUE|WED|THU|FRI|SAT|SUN):(?:[01][0-9]|2[0-3]):[0-5][0-9]$']]],
                        ],
                        [
                            'if' => ['properties' => ['cadence' => ['const' => 'monthly']]],
                            'then' => ['properties' => ['natural_slot' => ['pattern' => '^monthly:(0[1-9]|[12][0-9]|3[01]):(?:[01][0-9]|2[0-3]):[0-5][0-9]$']]],
                        ],
                    ],
                ],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function dailyGscCoreRuntimeOutputSchema(): array
    {
        $state = [
            'READY', 'DATA_FRESHNESS_HOLD', 'GSC_UNAVAILABLE_HOLD',
            'MAPPING_FAILED_HOLD', 'WINDOW_INCOMPLETE_HOLD', 'DATA_QUALITY_HOLD',
            'RUNTIME_UNAVAILABLE_HOLD', 'RUNTIME_READBACK_HOLD', 'INPUT_HOLD',
        ];
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_daily_gsc_core_runtime_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'receipt_version', 'mission_id', 'evaluated_at', 'state', 'gsc', 'runtime',
                'read_only', 'execution_allowed', 'writes_allowed', 'receipt_hash',
            ],
            'properties' => [
                'receipt_version' => ['const' => 'seo.platform12_daily_gsc_core_runtime.v1'],
                'mission_id' => ['const' => 'seo.platform12.daily_gsc_core_runtime'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => $state],
                'gsc' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'capability_state', 'scheduled_receipt_status', 'data_max_date', 'lag_days',
                        'row_count', 'mapping_state', 'data_quality_state', 'window_state', 'source_read_only',
                    ],
                    'properties' => [
                        'capability_state' => ['enum' => ['AVAILABLE', 'UNAVAILABLE', 'VALID_ZERO', 'DELAYED', 'MAPPING_FAILED', 'WINDOW_INCOMPLETE']],
                        'scheduled_receipt_status' => ['type' => ['string', 'null']],
                        'data_max_date' => ['type' => ['string', 'null']],
                        'lag_days' => ['type' => ['integer', 'null'], 'minimum' => 0],
                        'row_count' => ['type' => ['integer', 'null'], 'minimum' => 0],
                        'mapping_state' => ['enum' => ['READY', 'FAILED', 'UNAVAILABLE']],
                        'data_quality_state' => ['enum' => ['READY', 'HOLD', 'UNAVAILABLE']],
                        'window_state' => ['enum' => ['COMPLETE', 'INCOMPLETE', 'UNAVAILABLE']],
                        'source_read_only' => ['const' => true],
                    ],
                ],
                'runtime' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'core_runtime_state', 'public_api_state', 'readback_state',
                        'production_sha', 'readback_sha', 'sha_match',
                    ],
                    'properties' => [
                        'core_runtime_state' => ['enum' => ['AVAILABLE', 'UNAVAILABLE', 'FAILED']],
                        'public_api_state' => ['enum' => ['AVAILABLE', 'UNAVAILABLE', 'FAILED']],
                        'readback_state' => ['enum' => ['AVAILABLE', 'UNAVAILABLE', 'FAILED']],
                        'production_sha' => ['type' => ['string', 'null']],
                        'readback_sha' => ['type' => ['string', 'null']],
                        'sha_match' => ['type' => ['boolean', 'null']],
                    ],
                ],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'writes_allowed' => ['const' => false],
                'receipt_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function dailyUrlTruthOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_daily_url_truth_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'receipt_version', 'mission_id', 'evaluated_at', 'state',
                'authority_reconciliation', 'clustering_dedupe', 'd1_observation',
                'observation_boundaries', 'candidate_actions', 'read_only',
                'execution_allowed', 'writes', 'receipt_hash',
            ],
            'properties' => [
                'receipt_version' => ['const' => 'seo.platform12_daily_url_truth.v1'],
                'mission_id' => ['const' => 'seo.platform12.daily_url_truth_reconciliation'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'WRONG_CANONICAL_HOLD', 'FALSE_NOINDEX_HOLD', 'RECONCILIATION_INCOMPLETE_HOLD', 'URL_TRUTH_UNAVAILABLE_HOLD', 'CLUSTER_DEDUPE_UNAVAILABLE_HOLD', 'D1_OBSERVATION_HOLD', 'OBSERVATION_UNAVAILABLE_HOLD', 'INPUT_HOLD']],
                'authority_reconciliation' => ['type' => 'object'],
                'clustering_dedupe' => ['type' => 'object'],
                'd1_observation' => ['type' => 'object'],
                'observation_boundaries' => ['type' => 'object'],
                'candidate_actions' => ['type' => 'array', 'maxItems' => 0],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'writes' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['url_truth', 'canonical', 'robots', 'authority'],
                    'properties' => [
                        'url_truth' => ['const' => false],
                        'canonical' => ['const' => false],
                        'robots' => ['const' => false],
                        'authority' => ['const' => false],
                    ],
                ],
                'receipt_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function dailySecurityDriftOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_daily_security_drift_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'receipt_version', 'mission_id', 'evaluated_at', 'state', 'reason_codes',
                'private_routes', 'query_security', 'drift', 'evidence_freshness',
                'injection', 'tools', 'posture', 'automatic_repair_allowed',
                'authority_mutation_allowed', 'permission_mutation_allowed',
                'read_only', 'execution_allowed', 'receipt_hash',
            ],
            'properties' => [
                'receipt_version' => ['const' => 'seo.platform12_daily_security_drift.v1'],
                'mission_id' => ['const' => 'seo.platform12.daily_private_policy_evidence_drift'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'HOLD', 'DENY']],
                'reason_codes' => ['type' => 'array', 'maxItems' => 16, 'uniqueItems' => true, 'items' => ['type' => 'string']],
                'private_routes' => ['type' => 'object'],
                'query_security' => ['type' => 'object'],
                'drift' => ['type' => 'object'],
                'evidence_freshness' => ['type' => 'object'],
                'injection' => ['type' => 'object'],
                'tools' => ['type' => 'object'],
                'posture' => ['type' => 'object'],
                'automatic_repair_allowed' => ['const' => false],
                'authority_mutation_allowed' => ['const' => false],
                'permission_mutation_allowed' => ['const' => false],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'receipt_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function weeklyOpportunityOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_weekly_opportunity_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'artifact_version', 'mission_id', 'evaluated_at', 'locale', 'state',
                'reason_codes', 'candidate_count', 'unresolved_owner_conflict_count',
                'candidates', 'artifact_only', 'read_only', 'execution_allowed',
                'writes', 'artifact_hash',
            ],
            'properties' => [
                'artifact_version' => ['const' => 'seo.platform12_weekly_opportunity_candidates.v1'],
                'mission_id' => ['enum' => ['seo.platform12.weekly_opportunity_zh_cn', 'seo.platform12.weekly_opportunity_en']],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'locale' => ['enum' => ['zh-CN', 'en']],
                'state' => ['enum' => ['READY', 'VALID_ZERO', 'HOLD']],
                'reason_codes' => ['type' => 'array', 'maxItems' => 16, 'uniqueItems' => true, 'items' => ['type' => 'string']],
                'candidate_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'unresolved_owner_conflict_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'candidates' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                'artifact_only' => ['const' => true],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'writes' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['query_owner', 'url_truth', 'cms'],
                    'properties' => [
                        'query_owner' => ['const' => false],
                        'url_truth' => ['const' => false],
                        'cms' => ['const' => false],
                    ],
                ],
                'artifact_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function weeklyMeasurementOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_weekly_measurement_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'artifact_version', 'mission_id', 'evaluated_at', 'state', 'minimum_sample_size',
                'checkpoints', 'gai', 'public_funnel', 'cro_candidates', 'identity_data_read',
                'private_result_data_read', 'third_party_gai_sync_assumed', 'artifact_only',
                'read_only', 'execution_allowed', 'artifact_hash',
            ],
            'properties' => [
                'artifact_version' => ['const' => 'seo.platform12_weekly_measurement.v1'],
                'mission_id' => ['const' => 'seo.platform12.weekly_checkpoints_gai_funnel_cro'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'MEASUREMENT_HOLD']],
                'minimum_sample_size' => ['const' => 100],
                'checkpoints' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object']],
                'gai' => ['type' => 'object'],
                'public_funnel' => ['type' => 'object'],
                'cro_candidates' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object']],
                'identity_data_read' => ['const' => false],
                'private_result_data_read' => ['const' => false],
                'third_party_gai_sync_assumed' => ['const' => false],
                'artifact_only' => ['const' => true],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'artifact_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function weeklyEfficiencyOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_weekly_efficiency_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'artifact_version', 'mission_id', 'evaluated_at', 'state', 'routing', 'cost', 'budget',
                'human_time', 'locale_briefs', 'routine_time_excludes_projects_incidents_research_outreach',
                'artifact_only', 'read_only', 'execution_allowed', 'artifact_hash',
            ],
            'properties' => [
                'artifact_version' => ['const' => 'seo.platform12_weekly_efficiency.v1'],
                'mission_id' => ['const' => 'seo.platform12.weekly_routing_cost_time_locale_brief'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'BACKPRESSURE_HOLD', 'MEASUREMENT_HOLD']],
                'routing' => ['type' => 'object'],
                'cost' => ['type' => 'object'],
                'budget' => ['type' => 'object'],
                'human_time' => ['type' => 'object'],
                'locale_briefs' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => ['type' => 'object']],
                'routine_time_excludes_projects_incidents_research_outreach' => ['const' => true],
                'artifact_only' => ['const' => true],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'artifact_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function monthlyFamilyOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_monthly_family_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'artifact_version', 'mission_id', 'evaluated_at', 'state', 'family_maturity',
                'parity', 'authority', 'runtime_observation', 'public_funnel',
                'authority_runtime_separated', 'redirect_aliases_in_public_set',
                'artifact_only', 'read_only', 'execution_allowed', 'artifact_hash',
            ],
            'properties' => [
                'artifact_version' => ['const' => 'seo.platform12_monthly_family_maturity.v1'],
                'mission_id' => ['const' => 'seo.platform12.monthly_family_maturity_parity_public_url_set'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'MEASUREMENT_HOLD']],
                'family_maturity' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                'parity' => ['type' => 'object'],
                'authority' => ['type' => 'object'],
                'runtime_observation' => ['type' => 'object'],
                'public_funnel' => ['type' => 'object'],
                'authority_runtime_separated' => ['const' => true],
                'redirect_aliases_in_public_set' => ['const' => false],
                'artifact_only' => ['const' => true],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'artifact_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function monthlyLifecycleCandidatesOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_monthly_lifecycle_candidates_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'artifact_version', 'mission_id', 'evaluated_at', 'state', 'candidate_count',
                'candidates', 'forbidden_automatic_actions', 'artifact_only', 'read_only',
                'execution_allowed', 'writes', 'artifact_hash',
            ],
            'properties' => [
                'artifact_version' => ['const' => 'seo.platform12_monthly_lifecycle_candidates.v1'],
                'mission_id' => ['const' => 'seo.platform12.monthly_lifecycle_candidates'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'HOLD']],
                'candidate_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'candidates' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                'forbidden_automatic_actions' => ['const' => ['RETIRE', 'DELETE', 'UNPUBLISH', 'REDIRECT']],
                'artifact_only' => ['const' => true],
                'read_only' => ['const' => true],
                'execution_allowed' => ['const' => false],
                'writes' => ['type' => 'object'],
                'artifact_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, mixed> */
    public function monthlyEvalCapabilityLifecycleOutputSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_monthly_eval_capability_lifecycle_output.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'artifact_version', 'mission_id', 'evaluated_at', 'state', 'evaluations',
                'capabilities', 'all_team_invocation', 'artifact_only', 'read_only',
                'production_capability_activation_allowed', 'execution_allowed', 'artifact_hash',
            ],
            'properties' => [
                'artifact_version' => ['const' => 'seo.platform12_monthly_eval_capability_lifecycle.v1'],
                'mission_id' => ['const' => 'seo.platform12.monthly_eval_capability_lifecycle'],
                'evaluated_at' => ['type' => 'string', 'format' => 'date-time'],
                'state' => ['enum' => ['READY', 'HOLD']],
                'evaluations' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                'capabilities' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                'all_team_invocation' => ['const' => 0],
                'artifact_only' => ['const' => true],
                'read_only' => ['const' => true],
                'production_capability_activation_allowed' => ['const' => false],
                'execution_allowed' => ['const' => false],
                'artifact_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, array{id:string,version:string,hash:string}> */
    public function missionCatalogDependencyRefs(): array
    {
        $manifest = $this->platform11->manifest();
        $catalogSchema = $this->missionCatalogSchema();
        $schemaRefs = array_filter(
            $manifest,
            static fn (string $key): bool => str_ends_with($key, '_schema_ref') || $key === 'mission_request_ref',
            ARRAY_FILTER_USE_KEY,
        );
        $schemaRefs['platform12_mission_catalog_schema'] = [
            'id' => $catalogSchema['schema_id'],
            'version' => $catalogSchema['schema_version'],
            'hash' => $catalogSchema['schema_hash'],
        ];
        $schemaRefs['platform12_bounded_model_output_schema'] = $this->boundedModel->outputSchemaRef();
        $dailyOutputSchema = $this->dailyGscCoreRuntimeOutputSchema();
        $schemaRefs['platform12_daily_gsc_core_runtime_output_schema'] = [
            'id' => $dailyOutputSchema['schema_id'],
            'version' => $dailyOutputSchema['schema_version'],
            'hash' => $dailyOutputSchema['schema_hash'],
        ];
        $dailyUrlTruthSchema = $this->dailyUrlTruthOutputSchema();
        $schemaRefs['platform12_daily_url_truth_output_schema'] = [
            'id' => $dailyUrlTruthSchema['schema_id'],
            'version' => $dailyUrlTruthSchema['schema_version'],
            'hash' => $dailyUrlTruthSchema['schema_hash'],
        ];
        $dailySecuritySchema = $this->dailySecurityDriftOutputSchema();
        $schemaRefs['platform12_daily_security_drift_output_schema'] = [
            'id' => $dailySecuritySchema['schema_id'],
            'version' => $dailySecuritySchema['schema_version'],
            'hash' => $dailySecuritySchema['schema_hash'],
        ];
        $weeklyOpportunitySchema = $this->weeklyOpportunityOutputSchema();
        $schemaRefs['platform12_weekly_opportunity_output_schema'] = [
            'id' => $weeklyOpportunitySchema['schema_id'],
            'version' => $weeklyOpportunitySchema['schema_version'],
            'hash' => $weeklyOpportunitySchema['schema_hash'],
        ];
        $weeklyMeasurementSchema = $this->weeklyMeasurementOutputSchema();
        $schemaRefs['platform12_weekly_measurement_output_schema'] = [
            'id' => $weeklyMeasurementSchema['schema_id'],
            'version' => $weeklyMeasurementSchema['schema_version'],
            'hash' => $weeklyMeasurementSchema['schema_hash'],
        ];
        $weeklyEfficiencySchema = $this->weeklyEfficiencyOutputSchema();
        $schemaRefs['platform12_weekly_efficiency_output_schema'] = [
            'id' => $weeklyEfficiencySchema['schema_id'],
            'version' => $weeklyEfficiencySchema['schema_version'],
            'hash' => $weeklyEfficiencySchema['schema_hash'],
        ];
        $monthlyFamilySchema = $this->monthlyFamilyOutputSchema();
        $schemaRefs['platform12_monthly_family_output_schema'] = [
            'id' => $monthlyFamilySchema['schema_id'],
            'version' => $monthlyFamilySchema['schema_version'],
            'hash' => $monthlyFamilySchema['schema_hash'],
        ];
        $monthlyLifecycleSchema = $this->monthlyLifecycleCandidatesOutputSchema();
        $schemaRefs['platform12_monthly_lifecycle_candidates_output_schema'] = [
            'id' => $monthlyLifecycleSchema['schema_id'],
            'version' => $monthlyLifecycleSchema['schema_version'],
            'hash' => $monthlyLifecycleSchema['schema_hash'],
        ];
        $monthlyEvalLifecycleSchema = $this->monthlyEvalCapabilityLifecycleOutputSchema();
        $schemaRefs['platform12_monthly_eval_capability_lifecycle_output_schema'] = [
            'id' => $monthlyEvalLifecycleSchema['schema_id'],
            'version' => $monthlyEvalLifecycleSchema['schema_version'],
            'hash' => $monthlyEvalLifecycleSchema['schema_hash'],
        ];

        return [
            'role_registry' => $manifest['registry_ref'],
            'role_capability_binding' => $manifest['binding_ref'],
            'policy_registry' => $manifest['policy_ref'],
            'notification_policy' => $this->notifications->reference(),
            'tool_manifest' => $this->tools->reference(),
            'schema_vector' => [
                'id' => 'seo.platform12_mission_catalog_schema_vector',
                'version' => self::MISSION_CATALOG_VERSION,
                'hash' => $this->hasher->hash($schemaRefs),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function missionCatalog(): array
    {
        $catalog = [
            'schema_version' => 'seo.platform12_mission_catalog.v1',
            'catalog_id' => 'fermatmind.seo.platform12_mission_catalog',
            'catalog_version' => self::MISSION_CATALOG_VERSION,
            'catalog_state' => 'FOUNDATION_ONLY',
            'dependency_refs' => $this->missionCatalogDependencyRefs(),
            'missions' => [
                $this->dailyGscCoreRuntimeMission(),
                $this->dailyUrlTruthMission(),
                $this->dailySecurityDriftMission(),
                $this->weeklyOpportunityMission('zh-CN', 'weekly:MON:03:00'),
                $this->weeklyOpportunityMission('en', 'weekly:MON:03:10'),
                $this->weeklyMeasurementMission(),
                $this->weeklyEfficiencyMission(),
                $this->monthlyFamilyMission(),
                $this->monthlyLifecycleMission(),
                $this->monthlyEvalCapabilityLifecycleMission(),
            ],
            'runtime_activation_allowed' => false,
        ];
        $catalog['catalog_hash'] = $this->hasher->hash($catalog);

        return $catalog;
    }

    /** @return array<string, mixed> */
    private function dailyGscCoreRuntimeMission(): array
    {
        $output = $this->dailyGscCoreRuntimeOutputSchema();

        return [
            'mission_id' => 'seo.platform12.daily_gsc_core_runtime',
            'cadence' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'daily:ALL:06:20',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'runtime_health',
            'required_evidence' => [
                'gsc_scheduled_receipt', 'gsc_data_freshness', 'gsc_mapping_quality',
                'core_runtime_health', 'public_api_health', 'production_readback',
            ],
            'eligible_capability' => 'seo.runtime_health_review',
            'priority' => 'high',
            'timeout_seconds' => 120,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dailyUrlTruthMission(): array
    {
        $output = $this->dailyUrlTruthOutputSchema();

        return [
            'mission_id' => 'seo.platform12.daily_url_truth_reconciliation',
            'cadence' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'daily:ALL:06:25',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'technical',
            'required_evidence' => [
                'url_truth', 'backend_authority', 'canonical_indexability',
                'issue_cluster', 'dedupe_coverage', 'd1_observation',
                'runtime_observation', 'sitemap_observation',
            ],
            'eligible_capability' => 'seo.runtime_health_review',
            'priority' => 'high',
            'timeout_seconds' => 120,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dailySecurityDriftMission(): array
    {
        $output = $this->dailySecurityDriftOutputSchema();

        return [
            'mission_id' => 'seo.platform12.daily_private_policy_evidence_drift',
            'cadence' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'daily:ALL:06:30',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'stability',
            'required_evidence' => [
                'private_route_negative_set', 'query_hmac', 'query_pii',
                'role_binding_policy_vector', 'tool_schema_prompt_vector',
                'evidence_expiry', 'metadata_injection', 'tool_authorization',
                'retention_egress',
            ],
            'eligible_capability' => 'seo.release_separation_policy',
            'priority' => 'critical',
            'timeout_seconds' => 120,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function weeklyOpportunityMission(string $locale, string $slot): array
    {
        $output = $this->weeklyOpportunityOutputSchema();
        $localeCode = $locale === 'zh-CN' ? 'zh_cn' : 'en';

        return [
            'mission_id' => 'seo.platform12.weekly_opportunity_'.$localeCode,
            'cadence' => 'weekly',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => $slot,
            'family' => 'other_public',
            'locale' => $locale,
            'review_domain' => 'analytics',
            'required_evidence' => [
                'search_opportunity', 'content_decay', 'content_review_due',
                'query_owner', 'cannibalization', 'internal_link_graph', 'url_truth',
            ],
            'eligible_capability' => 'seo.search_measurement_review',
            'priority' => 'normal',
            'timeout_seconds' => 180,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function weeklyMeasurementMission(): array
    {
        $output = $this->weeklyMeasurementOutputSchema();

        return [
            'mission_id' => 'seo.platform12.weekly_checkpoints_gai_funnel_cro',
            'cadence' => 'weekly',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'weekly:MON:03:20',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'cro',
            'required_evidence' => [
                'd7_checkpoint', 'd14_checkpoint', 'd28_checkpoint',
                'gai_capability', 'public_funnel_aggregate', 'attribution_caveat',
            ],
            'eligible_capability' => 'seo.search_measurement_review',
            'priority' => 'normal',
            'timeout_seconds' => 180,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function weeklyEfficiencyMission(): array
    {
        $output = $this->weeklyEfficiencyOutputSchema();

        return [
            'mission_id' => 'seo.platform12.weekly_routing_cost_time_locale_brief',
            'cadence' => 'weekly',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'weekly:MON:03:30',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'analytics',
            'required_evidence' => [
                'routing_evaluation', 'required_mode_evaluation', 'all_team_invocation',
                'human_route_correction', 'model_tool_cost', 'mission_budget',
                'human_time_categories', 'locale_specific_brief_evidence',
            ],
            'eligible_capability' => 'seo.search_measurement_review',
            'priority' => 'normal',
            'timeout_seconds' => 180,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function monthlyFamilyMission(): array
    {
        $output = $this->monthlyFamilyOutputSchema();

        return [
            'mission_id' => 'seo.platform12.monthly_family_maturity_parity_public_url_set',
            'cadence' => 'monthly',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'monthly:01:04:00',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'analytics',
            'required_evidence' => [
                'family_maturity', 'locale_parity', 'public_url_authority',
                'runtime_url_observation', 'canonical_hreflang_indexability',
                'public_funnel_aggregate', 'url_set_drift',
            ],
            'eligible_capability' => 'seo.search_measurement_review',
            'priority' => 'normal',
            'timeout_seconds' => 180,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function monthlyLifecycleMission(): array
    {
        $output = $this->monthlyLifecycleCandidatesOutputSchema();

        return [
            'mission_id' => 'seo.platform12.monthly_lifecycle_candidates',
            'cadence' => 'monthly',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'monthly:01:04:10',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'content_lifecycle',
            'required_evidence' => [
                'material_change', 'traffic', 'authority_revision', 'locale_evidence',
                'rollback_readiness', 'shared_layer_risk', 'sensitive_claim_risk',
            ],
            'eligible_capability' => 'seo.content_decay_refresh_review',
            'priority' => 'normal',
            'timeout_seconds' => 180,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function monthlyEvalCapabilityLifecycleMission(): array
    {
        $output = $this->monthlyEvalCapabilityLifecycleOutputSchema();

        return [
            'mission_id' => 'seo.platform12.monthly_eval_capability_lifecycle',
            'cadence' => 'monthly',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'monthly:01:04:20',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'stability',
            'required_evidence' => [
                'detector_eval', 'agent_eval', 'family_locale_samples', 'sampling_method',
                'confidence_interval_95', 'capability_lifecycle', 'version_drift_vector',
            ],
            'eligible_capability' => 'seo.runtime_health_review',
            'priority' => 'normal',
            'timeout_seconds' => 180,
            'max_attempts' => 1,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'none',
                'initial_backoff_seconds' => 0,
                'max_backoff_seconds' => 0,
            ],
            'output_schema' => [
                'id' => $output['schema_id'],
                'version' => $output['schema_version'],
                'hash' => $output['schema_hash'],
            ],
        ];
    }

    public function verifyGenerated(): bool
    {
        try {
            foreach ($this->artifacts() as $relative => $expected) {
                $path = dirname(__DIR__, 4).'/'.$relative;
                $actual = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($actual) || ! hash_equals($this->hasher->hash($expected), $this->hasher->hash($actual))) {
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
        return [
            'resources/seo-agent/council/platform12/schemas/seo.platform12_start_receipt.v1.schema.json' => $this->startReceiptSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_mission_catalog.v1.schema.json' => $this->missionCatalogSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_daily_gsc_core_runtime_output.v1.schema.json' => $this->dailyGscCoreRuntimeOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_daily_url_truth_output.v1.schema.json' => $this->dailyUrlTruthOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_daily_security_drift_output.v1.schema.json' => $this->dailySecurityDriftOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_weekly_opportunity_output.v1.schema.json' => $this->weeklyOpportunityOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_weekly_measurement_output.v1.schema.json' => $this->weeklyMeasurementOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_weekly_efficiency_output.v1.schema.json' => $this->weeklyEfficiencyOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_monthly_family_output.v1.schema.json' => $this->monthlyFamilyOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_monthly_lifecycle_candidates_output.v1.schema.json' => $this->monthlyLifecycleCandidatesOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_monthly_eval_capability_lifecycle_output.v1.schema.json' => $this->monthlyEvalCapabilityLifecycleOutputSchema(),
            'resources/seo-agent/council/platform12/schemas/seo.platform12_metric_contract.v1.schema.json' => $this->metrics->schema(),
            'resources/seo-agent/council/platform12/catalogs/seo.platform12_mission_catalog.v1.json' => $this->missionCatalog(),
            'resources/seo-agent/council/platform12/measurement/seo.platform12_metric_contract.v1.json' => $this->metrics->contract(),
            ...$this->boundedModel->artifacts(),
            ...$this->tools->artifacts(),
            ...$this->notifications->artifacts(),
        ];
    }
}
