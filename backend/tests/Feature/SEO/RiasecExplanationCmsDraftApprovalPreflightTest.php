<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationCmsDraftApprovalPreflightTest extends TestCase
{
    public function test_preflight_blocks_draft_approval_without_operator_inputs(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-approval-preflight.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-APPROVAL-PREFLIGHT-01', $preflight['task_id']);
        $this->assertSame('NO-GO_OPERATOR_INPUTS_REQUIRED', $preflight['decision']);
        $this->assertFalse($preflight['preflight_passed']);
        $this->assertFalse($preflight['operator_approval_claimed']);
        $this->assertFalse($preflight['cms_draft_approval_allowed']);
        $this->assertFalse($preflight['cms_mutation_performed']);
        $this->assertFalse($preflight['publish_allowed']);
        $this->assertFalse($preflight['search_submit_allowed']);
        $this->assertFalse($preflight['deploy_performed']);
        $this->assertFalse($preflight['content_rewrite_performed']);
        $this->assertFalse($preflight['private_url_accessed']);
    }

    public function test_operator_inputs_remain_unknown_and_blocking(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-approval-preflight.v1.json');

        $this->assertSame('blocked', $preflight['operator_input_readiness']['status']);
        $this->assertTrue($preflight['operator_input_readiness']['unknown_fields_preserved']);
        $this->assertSame('Unknown', $preflight['operator_input_readiness']['ready_field_count']);
        $this->assertContains('accepted_source_urls', $preflight['operator_input_readiness']['missing_or_unknown_inputs']);
        $this->assertContains('cms_media_id', $preflight['operator_input_readiness']['missing_or_unknown_inputs']);
        $this->assertContains('approved_by', $preflight['operator_input_readiness']['missing_or_unknown_inputs']);
        $this->assertContains('claim_warning_acknowledgement', $preflight['operator_input_readiness']['missing_or_unknown_inputs']);
        $this->assertContains('product_availability_confirmed', $preflight['operator_input_readiness']['missing_or_unknown_inputs']);
    }

    public function test_preflight_checks_keep_external_blockers_and_safe_checks_separate(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-approval-preflight.v1.json');
        $checks = collect($preflight['approval_preflight_checks'])->keyBy('id');

        foreach ([
            'source_acceptance_ready',
            'media_ready',
            'revision_approval_ready',
            'claim_warning_ready',
            'internal_links_ready',
            'product_availability_ready',
        ] as $blockedCheck) {
            $this->assertSame('blocked', $checks[$blockedCheck]['status']);
        }

        $this->assertSame('pass', $checks['draft_public_safety']['status']);
        $this->assertSame('pass', $checks['public_route_safety']['status']);
    }

    public function test_draft_records_are_not_public_indexable_or_approved(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-approval-preflight.v1.json');
        $records = collect($preflight['draft_records'])->keyBy('locale');

        foreach (['zh', 'en'] as $locale) {
            $this->assertSame('draft', $records[$locale]['status']);
            $this->assertFalse($records[$locale]['public']);
            $this->assertFalse($records[$locale]['indexable']);
            $this->assertSame('machine_draft', $records[$locale]['working_revision_status']);
            $this->assertNull($records[$locale]['approved_at']);
            $this->assertSame('blocked', $records[$locale]['approval_preflight_status']);
        }
    }

    public function test_next_publish_preflight_is_no_mutation_and_expected_no_go_without_inputs(): void
    {
        $preflight = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-approval-preflight.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-PUBLISH-PREFLIGHT-01', $preflight['next_task_recommendation']['id']);
        $this->assertSame('NO-GO_OPERATOR_INPUTS_REQUIRED', $preflight['next_task_recommendation']['expected_decision_without_operator_inputs']);
        $this->assertFalse($preflight['next_task_recommendation']['cms_mutation_allowed']);
        $this->assertFalse($preflight['next_task_recommendation']['publish_allowed']);
        $this->assertFalse($preflight['next_task_recommendation']['search_submission_allowed']);
        $this->assertFalse($preflight['next_task_recommendation']['deploy_allowed']);
    }

    public function test_artifact_contains_no_forbidden_private_routes_or_identifiers(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-draft-approval-preflight.v1.json') ?: '';

        $this->assertDoesNotMatchRegularExpression('#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i', $contents);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\\b/i', $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
