<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti00aBaselineContractTest extends TestCase
{
    #[Test]
    public function baseline_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-00a-baseline-url-truth-telemetry-contract.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-00a-baseline-url-truth-telemetry-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-00A', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01-PREFACE', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('blocked_mbti_url_truth_unclear', $artifact['scan_blocker_resolved'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function url_truth_candidate_matrix_locks_public_candidates_and_deferred_families(): void
    {
        $matrix = $this->artifact()['url_truth_candidate_matrix'] ?? [];

        $this->assertCandidate($matrix, '/en/tests/mbti-personality-test-16-personality-types', 'test_detail', 'candidate_requiring_backend_authoritative_dry_run_confirmation');
        $this->assertCandidate($matrix, '/zh/tests/mbti-personality-test-16-personality-types', 'test_detail', 'candidate_requiring_backend_authoritative_dry_run_confirmation');
        $this->assertCandidate($matrix, '/en/research/mbti-personality-types-salary-turnover-report', 'research_report', 'candidate_requiring_claim_safe_verification');
        $this->assertCandidate($matrix, '/zh/research/mbti-personality-types-salary-turnover-report', 'research_report', 'candidate_requiring_claim_safe_verification');
        $this->assertCandidate($matrix, '/en/topics/mbti', 'topic', 'deferred_until_backend_cms_topic_authority_is_explicit');
        $this->assertCandidate($matrix, '/zh/topics/mbti', 'topic', 'deferred_until_backend_cms_topic_authority_is_explicit');

        $personalityPatterns = array_column($matrix, 'url_pattern');
        $this->assertContains('/en/personality/{type}', $personalityPatterns);
        $this->assertContains('/zh/personality/{type}', $personalityPatterns);
        $this->assertContains('backend_cms_article_urls', $personalityPatterns);

        $private = collect($matrix)->firstWhere('family', 'mbti_private_flows');
        $this->assertSame('noindex_private_excluded_from_url_truth_and_search_channel', $private['expected_status'] ?? null);
        $this->assertContains('take', $private['path_patterns'] ?? []);
        $this->assertContains('result', $private['path_patterns'] ?? []);
        $this->assertContains('report', $private['path_patterns'] ?? []);
        $this->assertContains('paywall', $private['path_patterns'] ?? []);
        $this->assertContains('order', $private['path_patterns'] ?? []);
        $this->assertContains('pdf', $private['path_patterns'] ?? []);
        $this->assertContains('history', $private['path_patterns'] ?? []);
    }

    #[Test]
    public function entity_map_contract_keeps_keys_independent_from_fallbacks_and_slugs(): void
    {
        $contract = $this->artifact()['entity_map_contract'] ?? [];
        $keys = $contract['future_entity_keys'] ?? [];

        foreach ([
            'mbti_test',
            'mbti_topic',
            'mbti_research_salary_turnover',
            'mbti_result_private',
            'mbti_paid_report_private',
            'mbti_type_intj',
            'mbti_type_infp',
            'mbti_type_entj',
        ] as $key) {
            $this->assertContains($key, $keys);
        }

        $rules = $contract['rules'] ?? [];

        foreach ([
            'entity_key_independent_from_url_slug',
            'translation_group_uuid_preferred_when_available',
            'translation_group_id_transitional_only',
            'slug_title_similarity_migration_helper_only_not_authority',
            'frontend_fallback_cannot_create_entity_key',
            'private_entities_not_public_url_truth_or_search_channel_candidates',
        ] as $rule) {
            $this->assertContains($rule, $rules);
        }
    }

    #[Test]
    public function telemetry_contract_splits_frontend_observation_from_backend_truth(): void
    {
        $contract = $this->artifact()['telemetry_contract'] ?? [];

        foreach ([
            'landing_view',
            'test_cta_click',
            'test_start_click',
            'report_preview_view',
            'unlock_click',
            'checkout_button_click',
            'email_form_view',
        ] as $event) {
            $this->assertContains($event, $contract['frontend_observation_events'] ?? []);
        }

        foreach ([
            'attempt_created',
            'attempt_submitted',
            'result_generated',
            'email_captured',
            'order_created',
            'payment_success',
            'benefit_granted',
            'report_access_granted',
            'pdf_generated',
        ] as $event) {
            $this->assertContains($event, $contract['backend_truth_events'] ?? []);
        }

        foreach ([
            'frontend_observation_is_not_backend_truth',
            'backend_payment_order_report_access_is_truth',
            'bot_crawler_traffic_excluded_from_conversion_funnel_formulas',
            'crawler_traffic_only_enters_crawler_aggregate_observation',
            'email_not_in_public_html_search_analytics_payloads_urls_or_digital_pr_artifacts',
        ] as $rule) {
            $this->assertContains($rule, $contract['rules'] ?? []);
        }
    }

    #[Test]
    public function claim_gate_and_search_channel_preconditions_are_required_before_growth_actions(): void
    {
        $artifact = $this->artifact();
        $claimGate = $artifact['claim_gate'] ?? [];

        foreach ([
            'search_channel_planning',
            'digital_pr_wave_2',
            'content_internal_link_wave',
            'growth_experiment_review',
        ] as $gate) {
            $this->assertContains($gate, $claimGate['required_before'] ?? []);
        }

        foreach ([
            'MBTI决定收入',
            'MBTI预测离职',
            '薪资保证',
            '招聘适配',
            '职业成功预测',
            '精准职业推荐',
            '最适合职业',
            '诊断',
            '确诊',
            '治疗',
            '治愈',
        ] as $claim) {
            $this->assertContains($claim, $claimGate['forbidden_claims'] ?? []);
        }

        foreach ([
            '模型化指数',
            '聚合层面',
            '方向性趋势',
            '非诊断',
            '结果仅供参考',
            '职业方向参考',
            '工作方式倾向',
            '探索建议',
        ] as $language) {
            $this->assertContains($language, $claimGate['allowed_bounded_language'] ?? []);
        }

        $this->assertFalse((bool) ($claimGate['auto_rewrite_allowed'] ?? true));

        foreach ([
            'url_truth_verified',
            'source_authority_allowed',
            'canonical_indexable_public',
            'claim_safe',
            'not_private',
            'not_draft',
            'not_noindex',
            'dry_run_before_enqueue',
            'human_approval_before_live_submit',
            'no_bulk_submit',
        ] as $precondition) {
            $this->assertContains($precondition, $artifact['search_channel_preconditions'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_confirm_this_preface_is_non_mutating(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_next_task_and_authority_boundaries(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-00a-baseline-url-truth-telemetry-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-00a-baseline-url-truth-telemetry-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'seo-growth-mbti-00a',
            'blocked_mbti_url_truth_unclear',
            'frontend fallback',
            'static sitemap',
            'static llms',
            'candidate requiring backend-authoritative dry-run confirmation',
            'candidate requiring claim-safe verification',
            'deferred until backend cms/topic authority is explicit',
            'excluded from url truth and search channel',
            'frontend observation is not backend truth',
            'backend payment, order, and report access are truth',
            'bot and crawler traffic must be excluded from conversion funnel formulas',
            'email must not enter public html, search, analytics payloads, urls, or digital pr artifacts',
            'dry-run before enqueue',
            'human approval before live submit',
            'no bulk submit',
            'seo-growth-mbti-pr-train-01',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $matrix
     */
    private function assertCandidate(array $matrix, string $url, string $pageEntityType, string $status): void
    {
        $candidate = collect($matrix)->firstWhere('url', $url);

        $this->assertIsArray($candidate, 'Missing URL Truth candidate '.$url);
        $this->assertSame($pageEntityType, $candidate['expected_page_entity_type'] ?? null);
        $this->assertSame($status, $candidate['status'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-00a-baseline-url-truth-telemetry-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
