<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgent\AutoApprovalPolicy;
use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyGuard;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform02PageFamilyPolicyTest extends TestCase
{
    #[Test]
    public function registry_schema_family_ids_required_fields_and_hash_are_stable(): void
    {
        $registry = new PageFamilyPolicyRegistry;
        $registry->assertValid();

        $this->assertSame(
            [...PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, ...PageFamilyPolicyRegistry::ISOLATION_FAMILY_IDS],
            array_keys($registry->families()),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $registry->policyHash());
        $this->assertSame($registry->policyHash(), (new PageFamilyPolicyRegistry)->policyHash());

        foreach ($registry->families() as $family) {
            foreach (PageFamilyPolicyRegistry::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $family);
            }
        }
    }

    #[Test]
    public function stable_authorities_match_exactly_one_registered_family(): void
    {
        $classifier = new PageFamilyClassifier;
        $cases = [
            ['test_detail', 'scales_registry', 'scale_catalog', '/en/tests/mbti', 'tests'],
            ['article', 'articles', 'backend_cms', '/zh/articles/example', 'articles_topics'],
            ['career_job', 'career_directory_authority', 'career_runtime_publish_projection', '/en/career/jobs/example', 'career'],
            ['personality_public_content_asset', 'personality_public_content_assets', 'backend_cms', '/zh/personality/example', 'personality'],
            ['research_report', 'research_reports', 'backend_cms', '/en/research/example', 'trust_method_help'],
            ['home', 'backend_authority', 'backend_public_surface', '/en', 'other_public'],
        ];

        foreach ($cases as [$type, $entitySource, $sourceAuthority, $path, $expected]) {
            $result = $classifier->classify([
                'canonical_path' => $path,
                'page_entity_type' => $type,
                'entity_source' => $entitySource,
                'source_authority' => $sourceAuthority,
                'authority_status' => 'published_approved',
                'indexability_state' => 'indexable',
            ]);

            $this->assertSame('classified', $result['classification_status'], $type);
            $this->assertSame($expected, $result['family_id'], $type);
            $this->assertCount(1, $result['matched_family_ids'], $type);
        }
    }

    #[Test]
    public function zero_and_multiple_matches_fail_closed_to_unclassified(): void
    {
        $classifier = new PageFamilyClassifier;
        $zero = $classifier->classify([
            'canonical_path' => '/en/unknown-surface',
            'page_entity_type' => 'unknown_public_type',
            'entity_source' => 'unknown_source',
            'source_authority' => 'backend_cms',
        ]);
        $multiple = $classifier->classify([
            'canonical_path' => '/en/tests',
            'page_entity_type' => 'landing_page',
            'entity_source' => 'landing_surfaces',
            'source_authority' => 'backend_cms',
        ]);

        $this->assertSame('unclassified', $zero['classification_status']);
        $this->assertSame('unclassified', $zero['family_id']);
        $this->assertSame('ambiguous', $multiple['classification_status']);
        $this->assertSame('unclassified', $multiple['family_id']);
        $this->assertGreaterThan(1, count($multiple['matched_family_ids']));
    }

    #[Test]
    public function career_dynamic_authority_classification_never_depends_on_a_fixed_count_or_sitemap(): void
    {
        $classifier = new PageFamilyClassifier;
        foreach (['software-engineer', 'licensed-practical-nurse', 'future-dynamic-career'] as $slug) {
            $result = $classifier->classify([
                'canonical_path' => '/en/career/jobs/'.$slug,
                'locale' => 'en',
                'page_entity_type' => 'career_job',
                'entity_source' => 'career_directory_authority',
                'source_authority' => 'career_runtime_publish_projection',
                'authority_status' => 'published_approved',
                'indexability_state' => 'indexable',
            ]);

            $this->assertSame('career', $result['family_id']);
            $this->assertSame('classified', $result['classification_status']);
        }

        $policy = (new PageFamilyPolicyRegistry)->families()['career'];
        $this->assertSame('CareerDirectoryAuthorityService', data_get($policy, 'authority.route_authority.dynamic_route_source'));
        $this->assertSame('consumer_consistency_only', data_get($policy, 'authority.route_authority.sitemap_role'));
        $this->assertSame(['minimum_urls' => 1, 'maximum_urls' => 3], data_get($policy, 'canary_policy.initial_canary'));
        $this->assertSame([3, 10, 50, 'complete_cohort'], data_get($policy, 'canary_policy.expansion_sequence'));
        $this->assertSame(['family', 'locale', 'authority_revision'], data_get($policy, 'canary_policy.cohort_key'));
        $this->assertTrue((bool) data_get($policy, 'canary_policy.requires_previous_stage_success'));
        $this->assertSame('pause_and_rollback_failed_cohort_only', data_get($policy, 'canary_policy.failure_action'));
        $this->assertSame(
            ['mode' => 'one_of', 'gates' => ['explicit_feature_flag', 'cohort_allowlist']],
            data_get($policy, 'canary_policy.shared_template_or_api_change_gate'),
        );
        $encodedPolicy = json_encode($policy, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('percent', strtolower($encodedPolicy));
        $this->assertStringNotContainsString('%', $encodedPolicy);
        $this->assertStringNotContainsString('2092', $encodedPolicy);
        $this->assertStringNotContainsString('2118', json_encode($policy, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2643', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function dynamic_authority_fields_are_required_while_exact_static_routes_may_be_completed(): void
    {
        $classifier = new PageFamilyClassifier;
        $base = [
            'canonical_path' => '/en/career/jobs/future-dynamic-career',
            'locale' => 'en',
            'page_entity_type' => 'career_job',
        ];
        $cases = [
            [$base + ['source_authority' => 'career_runtime_publish_projection'], ['missing_entity_source']],
            [$base + ['entity_source' => 'career_directory_authority'], ['missing_source_authority']],
            [$base, ['missing_entity_source', 'missing_source_authority']],
        ];

        foreach ($cases as [$authority, $expectedReasons]) {
            $result = $classifier->classify($authority);
            $this->assertSame('unclassified', $result['classification_status']);
            $this->assertSame('unclassified', $result['family_id']);
            $this->assertSame($expectedReasons, $result['blocking_reasons']);
        }

        $registeredStatic = $classifier->classify([
            'canonical_path' => '/en/tests',
            'page_entity_type' => 'test_hub',
        ]);
        $unknownStatic = $classifier->classify([
            'canonical_path' => '/en/tests/not-a-registered-static-route',
            'page_entity_type' => 'test_hub',
        ]);

        $this->assertSame('classified', $registeredStatic['classification_status']);
        $this->assertSame('tests', $registeredStatic['family_id']);
        $this->assertSame('unclassified', $unknownStatic['classification_status']);
        $this->assertSame(['missing_entity_source', 'missing_source_authority'], $unknownStatic['blocking_reasons']);
    }

    #[Test]
    public function classifier_returns_classification_facts_without_execution_authorization(): void
    {
        $result = (new PageFamilyClassifier)->classify([
            'canonical_path' => '/en/articles/example',
            'page_entity_type' => 'article',
            'entity_source' => 'articles',
            'source_authority' => 'backend_cms',
        ]);

        $this->assertSame([
            'policy_version',
            'policy_hash',
            'family_id',
            'classification_status',
            'matched_family_ids',
            'locale',
            'agent_risk_cap',
            'blocking_reasons',
        ], array_keys($result));

        foreach ([
            'allowed',
            'automated_publication_allowed',
            'search_submission_allowed',
            'canary_allowed',
            'expansion_allowed',
            'operations_queue_eligible',
        ] as $forbiddenField) {
            $this->assertArrayNotHasKey($forbiddenField, $result);
        }
    }

    #[Test]
    public function locale_query_owners_are_independent_and_missing_translation_never_falls_back(): void
    {
        foreach ((new PageFamilyPolicyRegistry)->families() as $family) {
            $this->assertArrayHasKey('zh-CN', $family['query_ownership']);
            $this->assertArrayHasKey('en', $family['query_ownership']);
            if ($family['public_family']) {
                $this->assertNotSame($family['query_ownership']['zh-CN'], $family['query_ownership']['en']);
            }
            $this->assertFalse($family['locale_policy']['translation_fallback_allowed']);
            $this->assertSame('hold_locale_surface', $family['locale_policy']['missing_translation_action']);
        }
    }

    #[Test]
    public function private_negative_set_and_non_public_states_are_permanently_excluded(): void
    {
        $registry = new PageFamilyPolicyRegistry;
        $classifier = new PageFamilyClassifier($registry);

        foreach ($registry->negativeSetProbes() as $probe) {
            $result = $classifier->classify($probe);
            $this->assertSame('private_excluded', $result['classification_status']);
            $this->assertSame('private_excluded', $result['family_id']);
            $this->assertNotContains('missing_entity_source', $result['blocking_reasons']);
            $this->assertNotContains('missing_source_authority', $result['blocking_reasons']);
        }

        $draft = $classifier->classify([
            'canonical_path' => '/en/articles/draft',
            'page_entity_type' => 'article',
            'authority_status' => 'draft',
        ]);
        $this->assertSame('private_excluded', $draft['classification_status']);
    }

    #[Test]
    public function agent_and_cms_automation_cannot_bypass_family_risk_cap_or_isolation(): void
    {
        $guard = new PageFamilyPolicyGuard;
        $tooRisky = $guard->evaluate([
            'canonical_path' => '/en',
            'page_entity_type' => 'home',
            'entity_source' => 'backend_authority',
            'source_authority' => 'backend_public_surface',
        ], 'L2');
        $this->assertFalse($tooRisky['family_policy_allowed']);
        $this->assertFalse($tooRisky['action_authorization_granted']);
        $this->assertArrayNotHasKey('allowed', $tooRisky);
        $this->assertContains('agent_risk_exceeds_family_cap', $tooRisky['blocking_reasons']);

        $decision = (new AutoApprovalPolicy($guard))->evaluateCandidate([
            'source_family' => 'cms_tdk_gap',
            'target_model' => 'content_page',
            'subject_type' => 'content_page',
            'subject_ref' => 'content_page:1:en',
            'safe_path' => '/en/account/settings',
            'severity' => 'p2',
            'target_fields' => ['seo_title'],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
        ]);

        $this->assertSame('blocked', $decision['approval_decision']);
        $this->assertNotContains('cms_draft_write_auto', $decision['allowed_next_actions']);
        $this->assertNotContains('cms_publish_auto_canary', $decision['allowed_next_actions']);
        $this->assertContains('cms_publish_auto_canary', $decision['blocked_actions']);
        $this->assertSame('private_excluded', data_get($decision, 'page_family_policy.classification_status'));
        $this->assertFalse((bool) data_get($decision, 'page_family_policy.action_authorization_granted', true));
    }

    #[Test]
    public function generated_coverage_contract_matches_registry_and_is_sanitized(): void
    {
        $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-platform-02-page-family-policy-coverage.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $registry = new PageFamilyPolicyRegistry;

        $this->assertSame(PageFamilyPolicyRegistry::VERSION, $artifact['policy_version'] ?? null);
        $this->assertSame($registry->policyHash(), $artifact['policy_hash'] ?? null);
        $this->assertSame(0, data_get($artifact, 'coverage.unclassified_count'));
        $this->assertSame(0, data_get($artifact, 'coverage.ambiguous_count'));
        $this->assertSame(0, data_get($artifact, 'private_negative_set.public_authority_leak_count'));
        $this->assertFalse((bool) data_get($artifact, 'boundaries.raw_url_emitted', true));
        $this->assertFalse((bool) data_get($artifact, 'boundaries.url_hash_emitted', true));
        $this->assertFalse((bool) data_get($artifact, 'boundaries.private_path_example_emitted', true));
        $this->assertFalse((bool) data_get($artifact, 'url_truth_missing_handoff.write_or_repair_performed', true));
    }

    #[Test]
    public function ci_requires_focused_tests_for_seo_runtime_changes(): void
    {
        $workflow = (string) file_get_contents(base_path('../.github/workflows/ci.yml'));

        $this->assertStringContainsString('SEO runtime changes must include focused changed tests.', $workflow);
        $this->assertStringContainsString('php artisan test "${changed_tests[@]}" --no-ansi', $workflow);
    }
}
