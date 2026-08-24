<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\PageFamily;

use RuntimeException;

final class PageFamilyPolicyRegistry
{
    public const VERSION = 'seo-page-family-policy.v1';

    public const PUBLIC_FAMILY_IDS = [
        'tests',
        'articles_topics',
        'career',
        'personality',
        'trust_method_help',
        'other_public',
    ];

    public const ISOLATION_FAMILY_IDS = ['unclassified', 'private_excluded'];

    public const REQUIRED_FIELDS = [
        'id',
        'label',
        'public_family',
        'authority',
        'business_priority',
        'locale_policy',
        'search_intent',
        'query_ownership',
        'supporting_relationships',
        'public_private_boundary',
        'canonical_policy',
        'indexability_policy',
        'hreflang_policy',
        'json_ld_eligibility',
        'sitemap_eligibility',
        'llms_eligibility',
        'required_visible_modules',
        'internal_link_entrypoints',
        'downstream_pages',
        'funnel_goals',
        'review_cycle_days',
        'agent_risk_cap',
        'canary_policy',
    ];

    /** @return array<string, array<string, mixed>> */
    public function families(): array
    {
        $commonLocalePolicy = [
            'supported' => ['zh-CN', 'en'],
            'translation_fallback_allowed' => false,
            'missing_translation_action' => 'hold_locale_surface',
        ];
        $commonCanary = [
            'scope' => 'exact_family_locale_authority_revision',
            'expansion_steps_percent' => [1, 5, 20, 50, 100],
            'stop_conditions' => ['classification_drift', 'private_leak', 'claim_gate_failure', 'canonical_or_hreflang_regression', 'smoke_failure'],
            'rollback' => 'restore_previous_verified_policy_and_active_authority_revision',
        ];

        return [
            'tests' => [
                'id' => 'tests',
                'label' => 'Tests',
                'public_family' => true,
                'authority' => [
                    'page_entity_types' => ['test_hub', 'test_detail'],
                    'entity_sources' => ['scales_registry', 'landing_surfaces', 'backend_authority'],
                    'source_authorities' => ['scale_catalog', 'backend_cms', 'backend_public_surface'],
                    'route_authority' => ['exact_static_templates' => ['/en/tests', '/zh/tests', '/en/tests/category/career', '/en/tests/category/personality', '/zh/tests/category/career', '/zh/tests/category/personality'], 'dynamic_route_source' => 'scale_catalog'],
                ],
                'business_priority' => 'L1_core_acquisition',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => ['assessment_discovery', 'test_selection', 'method_fit'],
                'query_ownership' => ['zh-CN' => 'tests.zh-CN.primary', 'en' => 'tests.en.primary'],
                'supporting_relationships' => ['articles_topics', 'personality', 'trust_method_help'],
                'public_private_boundary' => 'public_catalog_and_start_entry_only; attempts_results_reports_are_private_excluded',
                'canonical_policy' => 'one_backend_authority_canonical_per_locale',
                'indexability_policy' => 'index_only_when_public_active_and_complete',
                'hreflang_policy' => 'emit_only_existing_independently_owned_locale_pair',
                'json_ld_eligibility' => ['WebPage', 'Quiz', 'BreadcrumbList'],
                'sitemap_eligibility' => true,
                'llms_eligibility' => ['llms' => true, 'llms_full' => true],
                'required_visible_modules' => ['purpose', 'method_summary', 'time_and_question_count', 'start_cta', 'privacy_boundary'],
                'internal_link_entrypoints' => ['home', 'tests_hub', 'relevant_articles'],
                'downstream_pages' => ['test_start', 'methodology', 'public_personality'],
                'funnel_goals' => ['test_start', 'test_complete', 'result_view'],
                'review_cycle_days' => 90,
                'agent_risk_cap' => 'L2',
                'canary_policy' => $commonCanary + ['scope_detail' => 'one_scale_one_locale_then_family'],
            ],
            'articles_topics' => [
                'id' => 'articles_topics',
                'label' => 'Articles / Topics',
                'public_family' => true,
                'authority' => [
                    'page_entity_types' => ['article', 'article_hub', 'topic', 'topic_hub'],
                    'entity_sources' => ['articles', 'topics', 'public_topic_edges', 'landing_surfaces'],
                    'source_authorities' => ['backend_cms', 'backend_public_surface'],
                    'route_authority' => ['exact_static_templates' => ['/en/articles', '/zh/articles', '/en/topics', '/zh/topics'], 'dynamic_route_source' => 'cms_published_projection'],
                ],
                'business_priority' => 'L3_education_growth',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => ['learn', 'compare', 'problem_exploration'],
                'query_ownership' => ['zh-CN' => 'articles_topics.zh-CN.primary', 'en' => 'articles_topics.en.primary'],
                'supporting_relationships' => ['tests', 'career', 'personality', 'trust_method_help'],
                'public_private_boundary' => 'published_public_cms_revisions_only',
                'canonical_policy' => 'cms_published_revision_canonical',
                'indexability_policy' => 'published_indexable_claim_safe_only',
                'hreflang_policy' => 'translation_group_pairs_only_when_both_locales_published',
                'json_ld_eligibility' => ['Article', 'BreadcrumbList', 'FAQPage_when_visible'],
                'sitemap_eligibility' => true,
                'llms_eligibility' => ['llms' => true, 'llms_full' => true],
                'required_visible_modules' => ['title', 'reader_answer', 'sources_or_method', 'related_next_step'],
                'internal_link_entrypoints' => ['home', 'topic_hub', 'tests', 'career'],
                'downstream_pages' => ['supporting_article', 'test_detail', 'career_detail', 'methodology'],
                'funnel_goals' => ['qualified_test_start', 'career_exploration', 'supporting_page_depth'],
                'review_cycle_days' => 120,
                'agent_risk_cap' => 'L3',
                'canary_policy' => $commonCanary + ['scope_detail' => 'one_topic_cluster_one_locale'],
            ],
            'career' => [
                'id' => 'career',
                'label' => 'Career',
                'public_family' => true,
                'authority' => [
                    'page_entity_types' => ['career_job', 'career_recommendation', 'career_directory', 'career_guide', 'career_hub'],
                    'entity_sources' => ['career_directory_authority', 'career_runtime_publish_projection', 'career_recommendations', 'career_guides', 'landing_surfaces'],
                    'source_authorities' => ['career_runtime_publish_projection', 'backend_cms', 'backend_public_surface'],
                    'route_authority' => ['exact_static_templates' => ['/en/career', '/en/career/guides', '/en/career/recommendations', '/en/career/tests', '/zh/career', '/zh/career/guides', '/zh/career/recommendations', '/zh/career/tests'], 'dynamic_route_source' => 'CareerDirectoryAuthorityService', 'sitemap_role' => 'consumer_consistency_only'],
                ],
                'business_priority' => 'L3_career_discovery',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => ['occupation_research', 'career_comparison', 'next_step_planning'],
                'query_ownership' => ['zh-CN' => 'career.zh-CN.primary', 'en' => 'career.en.primary'],
                'supporting_relationships' => ['articles_topics', 'tests', 'trust_method_help'],
                'public_private_boundary' => 'runtime_publish_projection_only; shortlist_feedback_and_user_recommendations_private',
                'canonical_policy' => 'career_directory_authority_canonical_path',
                'indexability_policy' => 'detail_ready_and_runtime_projection_indexable_only',
                'hreflang_policy' => 'independent_directory_locale_authority_pair_only',
                'json_ld_eligibility' => ['Occupation', 'BreadcrumbList', 'FAQPage_when_visible'],
                'sitemap_eligibility' => true,
                'llms_eligibility' => ['llms' => true, 'llms_full' => true],
                'required_visible_modules' => ['occupation_summary', 'evidence_boundary', 'skills_or_tasks', 'related_careers', 'next_step'],
                'internal_link_entrypoints' => ['career_directory', 'career_family_hub', 'relevant_articles', 'tests'],
                'downstream_pages' => ['career_detail', 'career_family_hub', 'career_methodology'],
                'funnel_goals' => ['career_detail_view', 'career_comparison', 'qualified_test_start'],
                'review_cycle_days' => 120,
                'agent_risk_cap' => 'L3',
                'canary_policy' => [
                    'scope' => 'exact_family_locale_authority_revision',
                    'unit' => 'url_count',
                    'initial_canary' => ['minimum_urls' => 1, 'maximum_urls' => 3],
                    'expansion_sequence' => [3, 10, 50, 'complete_cohort'],
                    'cohort_key' => ['family', 'locale', 'authority_revision'],
                    'requires_previous_stage_success' => true,
                    'failure_action' => 'pause_and_rollback_failed_cohort_only',
                    'shared_template_or_api_change_gate' => [
                        'mode' => 'one_of',
                        'gates' => ['explicit_feature_flag', 'cohort_allowlist'],
                    ],
                    'stop_conditions' => ['classification_drift', 'private_leak', 'claim_gate_failure', 'canonical_or_hreflang_regression', 'smoke_failure'],
                    'rollback' => 'restore_previous_verified_policy_and_active_authority_revision_for_failed_cohort',
                ],
            ],
            'personality' => [
                'id' => 'personality',
                'label' => 'Personality',
                'public_family' => true,
                'authority' => [
                    'page_entity_types' => ['personality', 'personality_hub', 'personality_profile_variant', 'personality_profile_comparison', 'personality_public_content_asset'],
                    'entity_sources' => ['personality_profiles', 'personality_profile_variants', 'personality_public_content_assets', 'landing_surfaces'],
                    'source_authorities' => ['backend_cms', 'backend_public_surface'],
                    'route_authority' => ['exact_static_templates' => ['/en/personality', '/zh/personality'], 'dynamic_route_source' => 'personality_published_projection'],
                ],
                'business_priority' => 'L2_personality_discovery',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => ['type_or_trait_learning', 'comparison', 'self_reflection'],
                'query_ownership' => ['zh-CN' => 'personality.zh-CN.primary', 'en' => 'personality.en.primary'],
                'supporting_relationships' => ['tests', 'articles_topics', 'trust_method_help'],
                'public_private_boundary' => 'public_profiles_only; personalized_results_reports_history_and_share_private',
                'canonical_policy' => 'published_personality_asset_canonical',
                'indexability_policy' => 'public_published_index_eligible_only',
                'hreflang_policy' => 'exact_published_asset_pair_only',
                'json_ld_eligibility' => ['ProfilePage', 'WebPage', 'BreadcrumbList'],
                'sitemap_eligibility' => true,
                'llms_eligibility' => ['llms' => true, 'llms_full' => true],
                'required_visible_modules' => ['definition', 'strengths_and_limits', 'non_diagnostic_boundary', 'related_test', 'related_profiles'],
                'internal_link_entrypoints' => ['tests', 'personality_hub', 'relevant_articles'],
                'downstream_pages' => ['test_detail', 'personality_comparison', 'methodology'],
                'funnel_goals' => ['qualified_test_start', 'profile_depth', 'comparison_view'],
                'review_cycle_days' => 120,
                'agent_risk_cap' => 'L2',
                'canary_policy' => $commonCanary + ['scope_detail' => 'one_framework_entity_type_locale'],
            ],
            'trust_method_help' => [
                'id' => 'trust_method_help',
                'label' => 'Trust / Method / Help',
                'public_family' => true,
                'authority' => [
                    'page_entity_types' => ['methodology', 'dataset', 'research_report', 'content_page', 'support_article', 'interpretation_guide', 'support_hub'],
                    'entity_sources' => ['content_pages', 'research_reports', 'support_articles', 'interpretation_guides', 'landing_surfaces'],
                    'source_authorities' => ['backend_cms', 'backend_public_surface'],
                    'route_authority' => ['exact_static_templates' => ['/en/support', '/zh/support'], 'dynamic_route_source' => 'cms_published_projection'],
                ],
                'business_priority' => 'L2_trust_support',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => ['method_validation', 'policy_lookup', 'support_resolution'],
                'query_ownership' => ['zh-CN' => 'trust_method_help.zh-CN.primary', 'en' => 'trust_method_help.en.primary'],
                'supporting_relationships' => ['tests', 'articles_topics', 'career', 'personality'],
                'public_private_boundary' => 'approved_public_help_policy_and_method_content_only',
                'canonical_policy' => 'cms_public_canonical_path',
                'indexability_policy' => 'explicit_public_indexable_policy_per_page',
                'hreflang_policy' => 'emit_only_existing_approved_translation_pair',
                'json_ld_eligibility' => ['WebPage', 'FAQPage_when_visible', 'BreadcrumbList'],
                'sitemap_eligibility' => true,
                'llms_eligibility' => ['llms' => true, 'llms_full' => true],
                'required_visible_modules' => ['scope', 'answer_or_policy', 'evidence_or_effective_date', 'contact_or_next_step'],
                'internal_link_entrypoints' => ['footer', 'relevant_product_surface', 'help_hub'],
                'downstream_pages' => ['support_detail', 'methodology', 'relevant_public_surface'],
                'funnel_goals' => ['support_resolution', 'method_confidence', 'qualified_test_start'],
                'review_cycle_days' => 180,
                'agent_risk_cap' => 'L2',
                'canary_policy' => $commonCanary + ['scope_detail' => 'one_content_kind_locale'],
            ],
            'other_public' => [
                'id' => 'other_public',
                'label' => 'Other Public',
                'public_family' => true,
                'authority' => [
                    'page_entity_types' => ['home', 'landing_page', 'foundation_public_record', 'business_hub'],
                    'entity_sources' => ['backend_authority', 'landing_surfaces', 'foundation_public_records'],
                    'source_authorities' => ['backend_public_surface', 'backend_cms'],
                    'route_authority' => ['exact_static_templates' => ['/', '/en', '/en/business', '/zh/business'], 'dynamic_route_source' => 'explicit_registered_public_authority_only'],
                ],
                'business_priority' => 'L1_registered_misc_public',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => ['brand_navigation', 'campaign_or_public_record_lookup'],
                'query_ownership' => ['zh-CN' => 'other_public.zh-CN.primary', 'en' => 'other_public.en.primary'],
                'supporting_relationships' => ['tests', 'articles_topics', 'trust_method_help'],
                'public_private_boundary' => 'explicit_registered_public_authority_only; never_fallback',
                'canonical_policy' => 'exact_registered_authority_canonical',
                'indexability_policy' => 'explicit_per_surface_approval_only',
                'hreflang_policy' => 'exact_registered_locale_pair_only',
                'json_ld_eligibility' => ['WebPage', 'Organization_when_visible'],
                'sitemap_eligibility' => 'explicit_opt_in',
                'llms_eligibility' => ['llms' => 'explicit_opt_in', 'llms_full' => 'explicit_opt_in'],
                'required_visible_modules' => ['purpose', 'primary_navigation', 'ownership_or_provenance'],
                'internal_link_entrypoints' => ['home', 'footer', 'explicit_campaign_entry'],
                'downstream_pages' => ['registered_public_surface'],
                'funnel_goals' => ['public_navigation', 'qualified_test_start'],
                'review_cycle_days' => 180,
                'agent_risk_cap' => 'L1',
                'canary_policy' => $commonCanary + ['scope_detail' => 'one_exact_registered_surface'],
            ],
            'unclassified' => [
                'id' => 'unclassified',
                'label' => 'Unclassified',
                'public_family' => false,
                'authority' => ['page_entity_types' => [], 'entity_sources' => [], 'source_authorities' => [], 'route_authority' => []],
                'business_priority' => 'isolation',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => [],
                'query_ownership' => ['zh-CN' => null, 'en' => null],
                'supporting_relationships' => [],
                'public_private_boundary' => 'read_only_isolation_queue',
                'canonical_policy' => 'none',
                'indexability_policy' => 'blocked',
                'hreflang_policy' => 'blocked',
                'json_ld_eligibility' => [],
                'sitemap_eligibility' => false,
                'llms_eligibility' => ['llms' => false, 'llms_full' => false],
                'required_visible_modules' => [],
                'internal_link_entrypoints' => [],
                'downstream_pages' => [],
                'funnel_goals' => [],
                'review_cycle_days' => 0,
                'agent_risk_cap' => 'L0',
                'canary_policy' => ['scope' => 'none', 'expansion_steps_percent' => [], 'stop_conditions' => ['always'], 'rollback' => 'not_applicable'],
            ],
            'private_excluded' => [
                'id' => 'private_excluded',
                'label' => 'Private Excluded',
                'public_family' => false,
                'authority' => ['page_entity_types' => [], 'entity_sources' => [], 'source_authorities' => [], 'route_authority' => []],
                'business_priority' => 'permanent_exclusion',
                'locale_policy' => $commonLocalePolicy,
                'search_intent' => [],
                'query_ownership' => ['zh-CN' => null, 'en' => null],
                'supporting_relationships' => [],
                'public_private_boundary' => 'permanently_excluded_from_public_discoverability_and_operations_queue',
                'canonical_policy' => 'none',
                'indexability_policy' => 'permanently_blocked',
                'hreflang_policy' => 'blocked',
                'json_ld_eligibility' => [],
                'sitemap_eligibility' => false,
                'llms_eligibility' => ['llms' => false, 'llms_full' => false],
                'required_visible_modules' => [],
                'internal_link_entrypoints' => [],
                'downstream_pages' => [],
                'funnel_goals' => [],
                'review_cycle_days' => 0,
                'agent_risk_cap' => 'forbidden',
                'canary_policy' => ['scope' => 'none', 'expansion_steps_percent' => [], 'stop_conditions' => ['always'], 'rollback' => 'not_applicable'],
            ],
        ];
    }

    /** @return list<string> */
    public function privatePageEntityTypes(): array
    {
        return ['take', 'attempt', 'result', 'report', 'report_private', 'history', 'share', 'order', 'checkout', 'pay', 'payment', 'recovery', 'token', 'account'];
    }

    /** @return list<string> */
    public function privatePathSegments(): array
    {
        return ['take', 'attempt', 'attempts', 'result', 'results', 'report', 'reports', 'history', 'share', 'shares', 'order', 'orders', 'checkout', 'pay', 'payment', 'payments', 'recovery', 'recover', 'token', 'tokens', 'account', 'accounts'];
    }

    /** @return list<string> */
    public function nonPublicAuthorityStatuses(): array
    {
        return ['private', 'draft', 'unpublished', 'pending', 'retired', 'superseded', 'blocked'];
    }

    /** @return list<array<string, mixed>> */
    public function negativeSetProbes(): array
    {
        $probes = array_map(
            static fn (string $segment): array => ['canonical_path' => '/en/'.$segment.'/probe', 'page_entity_type' => 'home'],
            $this->privatePathSegments(),
        );

        foreach ($this->privatePageEntityTypes() as $type) {
            $probes[] = ['canonical_path' => '/en/public-probe', 'page_entity_type' => $type];
        }

        return $probes;
    }

    public function policyHash(): string
    {
        $payload = ['version' => self::VERSION, 'families' => $this->families()];
        $normalized = $this->sortRecursively($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function assertValid(): void
    {
        $families = $this->families();
        $expected = [...self::PUBLIC_FAMILY_IDS, ...self::ISOLATION_FAMILY_IDS];
        if (array_keys($families) !== $expected || count(array_unique($expected)) !== count($expected)) {
            throw new RuntimeException('Page family ids are missing, duplicated, or out of contract order.');
        }

        foreach ($families as $id => $family) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $family)) {
                    throw new RuntimeException("Page family {$id} is missing {$field}.");
                }
            }
            if (($family['id'] ?? null) !== $id) {
                throw new RuntimeException("Page family {$id} has a mismatched id.");
            }
            if (($family['locale_policy']['translation_fallback_allowed'] ?? null) !== false) {
                throw new RuntimeException("Page family {$id} must fail closed on missing translations.");
            }
            if (! array_key_exists('zh-CN', (array) ($family['query_ownership'] ?? []))
                || ! array_key_exists('en', (array) ($family['query_ownership'] ?? []))) {
                throw new RuntimeException("Page family {$id} must declare independent locale query owners.");
            }
        }
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
