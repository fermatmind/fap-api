<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationReferenceMediaRevisionApprovalTest extends TestCase
{
    public function test_approval_gate_remains_blocked_without_operator_inputs(): void
    {
        $approval = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-REFERENCE-MEDIA-REVISION-APPROVAL-01', $approval['task_id']);
        $this->assertSame('NO-GO_OPERATOR_INPUTS_REQUIRED', $approval['decision']);
        $this->assertFalse($approval['approval_passed']);
        $this->assertFalse($approval['operator_approval_claimed']);
        $this->assertFalse($approval['publish_allowed']);
        $this->assertFalse($approval['search_submit_allowed']);
        $this->assertFalse($approval['cms_mutation_performed']);
        $this->assertFalse($approval['deploy_performed']);
        $this->assertFalse($approval['content_rewrite_performed']);
        $this->assertFalse($approval['private_url_accessed']);
    }

    public function test_all_publish_blocking_approval_gates_are_recorded(): void
    {
        $approval = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json');
        $gates = collect($approval['approval_gates'])->keyBy('id');

        foreach ([
            'reference_acceptance',
            'media_resolution',
            'revision_approval',
            'claim_warning_acknowledgement',
            'conditional_internal_link_decision',
            'product_availability_confirmation',
            'controlled_publish_preflight',
        ] as $gateId) {
            $this->assertArrayHasKey($gateId, $gates);
            $this->assertSame('blocked', $gates[$gateId]['status']);
            $this->assertNotEmpty($gates[$gateId]['owner']);
            $this->assertNotEmpty($gates[$gateId]['required_input']);
            $this->assertNotEmpty($gates[$gateId]['acceptance_criteria']);
        }
    }

    public function test_unknown_operator_fields_are_not_coerced_to_false_or_zero(): void
    {
        $approval = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json');

        $this->assertSame('Unknown', $approval['operator_input_card']['reference_acceptance']['accepted_source_urls']);
        $this->assertSame('Unknown', $approval['operator_input_card']['media_resolution']['cms_media_id']);
        $this->assertSame('Unknown', $approval['operator_input_card']['revision_approval']['approved_by']);
        $this->assertSame('Unknown', $approval['operator_input_card']['claim_and_link_decisions']['claim_warning_acknowledgement']);
        $this->assertSame('Unknown', $approval['operator_input_card']['product_availability']['product_availability_confirmed']);
        $this->assertSame(2, $approval['operator_input_card']['claim_and_link_decisions']['zh_claim_warning_count']);
    }

    public function test_draft_records_remain_unpublished_non_public_and_non_indexable(): void
    {
        $approval = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json');
        $records = collect($approval['draft_records'])->keyBy('locale');

        foreach (['zh', 'en'] as $locale) {
            $this->assertSame('draft', $records[$locale]['status']);
            $this->assertFalse($records[$locale]['public']);
            $this->assertFalse($records[$locale]['indexable']);
            $this->assertSame('machine_draft', $records[$locale]['working_revision_status']);
            $this->assertNull($records[$locale]['approved_at']);
        }

        $this->assertSame(45, $records['zh']['working_revision_id']);
        $this->assertSame(46, $records['en']['working_revision_id']);
    }

    public function test_next_task_is_preflight_only_and_not_mutation_or_publish(): void
    {
        $approval = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-APPROVAL-PREFLIGHT-01', $approval['next_task_recommendation']['id']);
        $this->assertSame('blocked_until_operator_inputs_supplied', $approval['next_task_recommendation']['status']);
        $this->assertFalse($approval['next_task_recommendation']['cms_mutation_allowed']);
        $this->assertFalse($approval['next_task_recommendation']['publish_allowed']);
        $this->assertFalse($approval['next_task_recommendation']['search_submission_allowed']);
    }

    public function test_artifact_contains_only_allowed_public_routes_and_no_private_identifiers(): void
    {
        $approval = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json');
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-reference-media-revision-approval.v1.json') ?: '';

        $this->assertSame([
            '/zh/tests/holland-career-interest-test-riasec',
            '/en/tests/holland-career-interest-test-riasec',
            '/zh/career/jobs',
            '/en/career/jobs',
        ], $approval['safe_public_routes']);

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
