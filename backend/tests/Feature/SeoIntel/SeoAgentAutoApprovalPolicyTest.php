<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgent\AutoApprovalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentAutoApprovalPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function content_page_tdk_candidate_is_auto_approved_for_draft_publish_and_indexnow(): void
    {
        $decision = (new AutoApprovalPolicy)->evaluateCandidate($this->candidate([
            'target_model' => 'content_page',
            'subject_type' => 'content_page',
            'subject_ref' => 'content_page:5:zh-CN',
            'target_fields' => ['seo_title', 'seo_description', 'canonical_url_or_path', 'is_indexable_or_robots'],
        ]));

        $this->assertSame('auto_approved', $decision['approval_decision'] ?? null);
        $this->assertSame('low', $decision['risk_tier'] ?? null);
        $this->assertSame([
            'seo_title',
            'seo_description',
            'canonical_path',
            'is_indexable',
        ], $decision['normalized_target_fields'] ?? null);
        $this->assertContains('cms_draft_write_auto', $decision['allowed_next_actions'] ?? []);
        $this->assertContains('cms_publish_auto_canary', $decision['allowed_next_actions'] ?? []);
        $this->assertContains('post_publish_indexnow_auto', $decision['allowed_next_actions'] ?? []);
        $this->assertSame([], $decision['reason_codes'] ?? null);
    }

    #[Test]
    public function article_candidate_is_auto_approved_for_draft_only(): void
    {
        $decision = (new AutoApprovalPolicy)->evaluateCandidate($this->candidate([
            'target_model' => 'article',
            'subject_type' => 'article',
            'subject_ref' => 'article:9:zh-CN',
            'target_fields' => ['seo_title', 'seo_description'],
        ]));

        $this->assertSame('auto_approved', $decision['approval_decision'] ?? null);
        $this->assertContains('cms_draft_write_auto', $decision['allowed_next_actions'] ?? []);
        $this->assertNotContains('cms_publish_auto_canary', $decision['allowed_next_actions'] ?? []);
        $this->assertContains('cms_publish_auto_canary', $decision['blocked_actions'] ?? []);
        $this->assertContains('article_auto_publish', $decision['blocked_actions'] ?? []);
    }

    #[Test]
    public function non_low_risk_sources_are_blocked_with_specific_reasons(): void
    {
        $policy = new AutoApprovalPolicy;

        $runtime = $policy->evaluateCandidate($this->candidate([
            'source_family' => 'runtime_seo_qa',
            'target_fields' => ['manual_review_required'],
        ]));
        $gsc = $policy->evaluateCandidate($this->candidate([
            'source_family' => 'gsc_performance',
            'target_fields' => ['seo_title'],
        ]));

        $this->assertSame('blocked', $runtime['approval_decision'] ?? null);
        $this->assertContains('runtime_seo_qa_requires_technical_review', $runtime['reason_codes'] ?? []);
        $this->assertContains('target_field_not_allowed', $runtime['reason_codes'] ?? []);
        $this->assertSame('blocked', $gsc['approval_decision'] ?? null);
        $this->assertContains('gsc_performance_requires_manual_review_for_l5_auto_approval', $gsc['reason_codes'] ?? []);
    }

    #[Test]
    public function forbidden_fields_full_urls_and_claim_risks_fail_closed(): void
    {
        $policy = new AutoApprovalPolicy;

        $raw = $policy->evaluateCandidate($this->candidate([
            'raw_url' => 'https://fermatmind.com/zh/unsafe',
        ]));
        $claim = $policy->evaluateCandidate($this->candidate([
            'proposed_seo_description' => 'Clinically proven diagnostic guidance for hiring fit.',
        ]));
        $deterministicCareerClaim = $policy->evaluateCandidate($this->candidate([
            'proposed_seo_title' => 'Find your perfect match and ideal job',
            'proposed_seo_description' => '为你匹配最适合的职业，并决定你的职业。',
        ]));

        $this->assertSame('blocked', $raw['approval_decision'] ?? null);
        $this->assertContains('forbidden_field_present:raw_url', $raw['reason_codes'] ?? []);
        $this->assertContains('full_url_present', $raw['reason_codes'] ?? []);
        $this->assertSame('blocked', $claim['approval_decision'] ?? null);
        $this->assertContains('forbidden_claim_detected', $claim['reason_codes'] ?? []);
        $this->assertSame('blocked', $deterministicCareerClaim['approval_decision'] ?? null);
        $this->assertContains('forbidden_claim_detected', $deterministicCareerClaim['reason_codes'] ?? []);
    }

    #[Test]
    public function batch_evaluation_reports_counts_and_never_claims_side_effects(): void
    {
        $summary = (new AutoApprovalPolicy)->evaluateCandidates([
            $this->candidate(),
            $this->candidate(['severity' => 'p3']),
        ]);

        $this->assertSame('seo-agent-auto-approval-policy.v1', $summary['schema_version'] ?? null);
        $this->assertSame(2, $summary['candidate_count'] ?? null);
        $this->assertSame(1, $summary['auto_approved_count'] ?? null);
        $this->assertSame(1, $summary['blocked_count'] ?? null);

        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'google_indexing_live_api_call',
            'scheduler_activation',
            'queue_worker_started',
            'frontend_direct_push',
            'frontend_auto_deploy',
        ] as $field) {
            $this->assertFalse((bool) data_get($summary, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function generated_contract_documents_policy_boundaries(): void
    {
        $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-agent-auto-approval-policy.v1.json')), true);

        $this->assertSame('seo-agent-auto-approval-policy.v1', $artifact['version'] ?? null);
        $this->assertContains('cms_tdk_gap', $artifact['allowed_source_families'] ?? []);
        $this->assertContains('cms_faq_gap', $artifact['allowed_source_families'] ?? []);
        $this->assertContains('content_page', $artifact['allowed_target_models_for_auto_publish'] ?? []);
        $this->assertFalse((bool) data_get($artifact, 'limits.article_publish_auto_allowed', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_indexing_live_api_call', true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'source_family' => 'cms_tdk_gap',
            'source_id' => hash('sha256', 'candidate'),
            'target_model' => 'content_page',
            'subject_type' => 'content_page',
            'subject_ref' => 'content_page:1:zh-CN',
            'safe_path' => '/zh/content-page',
            'severity' => 'p2',
            'target_fields' => ['seo_title', 'seo_description'],
            'proposed_seo_title' => 'Content Page | FermatMind',
            'proposed_seo_description' => 'Review candidate with FermatMind guidance.',
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
        ], $overrides);
    }
}
