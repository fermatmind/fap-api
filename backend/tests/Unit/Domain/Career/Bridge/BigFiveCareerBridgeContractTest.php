<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Bridge;

use App\Domain\Career\Bridge\BigFiveCareerBridgeContract;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BigFiveCareerBridgeContractTest extends TestCase
{
    private BigFiveCareerBridgeContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contract = new BigFiveCareerBridgeContract;
    }

    #[Test]
    public function schemas_lock_exact_required_fields_hashes_states_and_claim_boundaries(): void
    {
        $input = $this->contract->schemaDocument('input');
        $output = $this->contract->schemaDocument('output');

        $this->assertFalse($input['additionalProperties']);
        $this->assertFalse($output['additionalProperties']);
        foreach ([
            'bridge_contract_version',
            'locale',
            'big_five_asset_identity',
            'big_five_primary_status',
            'big_five_published_revision_id',
            'big_five_public_projection_hash',
            'big_five_claim_permissions',
            'big_five_source_permission',
            'big_five_reviewer_permission',
            'big_five_date_permission',
            'career_canonical_slug',
            'career_runtime_projection_version',
            'career_runtime_projection_hash',
            'career_publish_eligibility',
            'private_data_absent',
        ] as $field) {
            $this->assertContains($field, $input['required']);
        }

        $this->assertSame(
            ['blocked', 'generated_candidate', 'pending_manual_review', 'published_projection_ready'],
            $output['properties']['status']['enum'],
        );
        $this->assertSame('explanation_only', $output['properties']['claim_mode']['const']);
        foreach ([
            'reflection_signals',
            'environment_questions',
            'feedback_and_structure_preferences',
            'possible_friction_cues',
            'exploration_examples',
            'boundary_copy',
        ] as $field) {
            $this->assertContains($field, $output['properties']['content']['required']);
        }
        foreach (['recommendation_authority', 'ranking_allowed', 'hiring_use_allowed', 'pseo_allowed'] as $field) {
            $this->assertFalse($output['properties']['claim_boundary']['properties'][$field]['const']);
        }
    }

    #[Test]
    public function unknown_schema_kind_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->contract->schemaDocument('candidate');
    }

    #[Test]
    public function exact_published_projections_are_reader_eligible_only_when_every_lock_matches(): void
    {
        $assessment = $this->contract->assess($this->validInput(), $this->validOutput());

        $this->assertSame(BigFiveCareerBridgeContract::STATUS_PUBLISHED_PROJECTION_READY, $assessment['status']);
        $this->assertTrue($assessment['public_reader_allowed']);
        $this->assertSame([], $assessment['blockers']);
        $this->assertSame(BigFiveCareerBridgeContract::CLAIM_MODE, $assessment['claim_mode']);
    }

    #[Test]
    public function working_or_draft_revision_selection_fails_closed(): void
    {
        $input = $this->validInput();
        $input['big_five_primary_status'] = 'draft';
        $input['big_five_projection']['primary_status'] = 'draft';
        $input['big_five_projection']['selected_revision_source'] = 'working_revision';
        $input['big_five_projection']['working_revision_id'] = 456;
        $input['big_five_projection']['selected_revision_id'] = 456;
        $input['big_five_projection']['working_or_draft_revision_used'] = true;

        $assessment = $this->contract->assess($input, $this->validOutput());

        $this->assertFalse($assessment['public_reader_allowed']);
        $this->assertContains('input.big_five.primary_not_published', $assessment['blockers']);
        $this->assertContains('input.big_five.selected_source_not_published_revision', $assessment['blockers']);
        $this->assertContains('input.big_five.selected_revision_not_published_revision', $assessment['blockers']);
        $this->assertContains('input.big_five.working_or_draft_revision_used', $assessment['blockers']);
    }

    #[Test]
    public function projection_hash_permission_and_career_eligibility_drift_fail_closed(): void
    {
        $input = $this->validInput();
        $input['big_five_public_projection_hash'] = str_repeat('c', 64);
        $input['big_five_reviewer_permission'] = false;
        $input['big_five_projection']['visible_evidence']['reviewer_permission'] = false;
        $input['career_runtime_projection_hash'] = str_repeat('d', 64);
        $input['career_publish_eligibility'] = false;
        $input['career_projection']['publish_eligibility'] = false;

        $assessment = $this->contract->assess($input, $this->validOutput());

        $this->assertFalse($assessment['public_reader_allowed']);
        $this->assertContains('input.binding_mismatch:big_five_public_projection_hash', $assessment['blockers']);
        $this->assertContains('input.big_five.visible_evidence_reviewer_permission_missing', $assessment['blockers']);
        $this->assertContains('input.binding_mismatch:career_runtime_projection_hash', $assessment['blockers']);
        $this->assertContains('input.career.publish_eligibility_missing', $assessment['blockers']);
    }

    #[Test]
    public function private_assessment_or_user_data_is_rejected_recursively(): void
    {
        $input = $this->validInput();
        $input['big_five_projection']['score_vector'] = ['openness' => 0.8];
        $input['privacy_boundary']['contains_attempt_or_report_links'] = true;
        $input['private_data_absent'] = false;

        $assessment = $this->contract->assess($input, $this->validOutput());

        $this->assertFalse($assessment['public_reader_allowed']);
        $this->assertContains('input.forbidden_private_or_ranking_key:score_vector', $assessment['blockers']);
        $this->assertContains('input.privacy_boundary.contains_attempt_or_report_links_must_be_false', $assessment['blockers']);
    }

    #[Test]
    public function ranking_hiring_pseo_and_deterministic_claims_are_rejected(): void
    {
        $output = $this->validOutput();
        $output['claim_boundary']['recommendation_authority'] = true;
        $output['claim_boundary']['ranking_allowed'] = true;
        $output['claim_boundary']['hiring_use_allowed'] = true;
        $output['claim_boundary']['pseo_allowed'] = true;
        $output['content']['reflection_signals'] = ['The best career for you is software engineering.'];

        $assessment = $this->contract->assess($this->validInput(), $output);

        $this->assertFalse($assessment['public_reader_allowed']);
        $this->assertContains('output.claim_boundary.recommendation_authority_must_be_false', $assessment['blockers']);
        $this->assertContains('output.claim_boundary.ranking_allowed_must_be_false', $assessment['blockers']);
        $this->assertContains('output.claim_boundary.hiring_use_allowed_must_be_false', $assessment['blockers']);
        $this->assertContains('output.claim_boundary.pseo_allowed_must_be_false', $assessment['blockers']);
        $this->assertContains('output.deterministic_or_outcome_claim:the best career for you is', $assessment['blockers']);
    }

    #[Test]
    public function candidate_and_manual_review_states_never_allow_a_public_reader(): void
    {
        foreach ([
            BigFiveCareerBridgeContract::STATUS_GENERATED_CANDIDATE,
            BigFiveCareerBridgeContract::STATUS_PENDING_MANUAL_REVIEW,
        ] as $status) {
            $output = $this->validOutput();
            $output['status'] = $status;
            $output['public_reader_allowed'] = false;

            $assessment = $this->contract->assess($this->validInput(), $output);

            $this->assertSame(BigFiveCareerBridgeContract::STATUS_BLOCKED, $assessment['status']);
            $this->assertFalse($assessment['public_reader_allowed']);
            $this->assertContains('output.status_not_published_projection_ready', $assessment['blockers']);
        }
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        $bigFiveHash = str_repeat('a', 64);
        $careerHash = str_repeat('b', 64);

        return [
            'bridge_contract_version' => BigFiveCareerBridgeContract::INPUT_CONTRACT_VERSION,
            'bridge_id' => 'bridge-001',
            'locale' => 'en',
            'big_five_asset_identity' => 'big5.openness.high.en',
            'big_five_primary_status' => 'published',
            'big_five_published_revision_id' => 123,
            'big_five_public_projection_hash' => $bigFiveHash,
            'big_five_claim_permissions' => true,
            'big_five_source_permission' => true,
            'big_five_reviewer_permission' => true,
            'big_five_date_permission' => true,
            'career_canonical_slug' => 'software-engineers',
            'career_runtime_projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
            'career_runtime_projection_hash' => $careerHash,
            'career_publish_eligibility' => true,
            'private_data_absent' => true,
            'big_five_projection' => [
                'authority_surface' => 'personality_public_content_asset',
                'source_kind' => BigFiveCareerBridgeContract::BIG_FIVE_SOURCE_KIND,
                'framework' => 'big_five',
                'asset_id' => 'big5.openness.high.en',
                'locale' => 'en',
                'primary_status' => 'published',
                'is_public' => true,
                'published_revision_id' => 123,
                'selected_revision_id' => 123,
                'selected_revision_source' => BigFiveCareerBridgeContract::SELECTED_REVISION_SOURCE,
                'public_projection_hash' => $bigFiveHash,
                'working_revision_id' => 456,
                'public_projection_ready' => true,
                'visible_evidence' => [
                    'claim_permission' => true,
                    'source_permission' => true,
                    'reviewer_permission' => true,
                    'visible_date_permission' => true,
                ],
                'working_or_draft_revision_used' => false,
                'generated_authority_package_used' => false,
            ],
            'career_projection' => [
                'projection_kind' => BigFiveCareerBridgeContract::CAREER_PROJECTION_KIND,
                'projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
                'projection_hash' => $careerHash,
                'occupation_id' => 'occupation-001',
                'canonical_slug' => 'software-engineers',
                'locale' => 'en',
                'public_resolution_type' => 'public_canonical_job',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'release_gate_pass' => true,
                'publish_eligibility' => true,
                'public_projection_ready' => true,
                'blockers' => [],
            ],
            'signal_policy' => [
                'primary_career_interest_signal' => BigFiveCareerBridgeContract::PRIMARY_CAREER_SIGNAL,
                'big_five_role' => BigFiveCareerBridgeContract::BIG_FIVE_ROLE,
                'claim_mode' => BigFiveCareerBridgeContract::CLAIM_MODE,
                'occupation_ranking_allowed' => false,
                'hiring_use_allowed' => false,
                'outcome_prediction_allowed' => false,
                'pseo_expansion_allowed' => false,
            ],
            'privacy_boundary' => $this->privacyBoundary(),
        ];
    }

    /** @return array<string, mixed> */
    private function validOutput(): array
    {
        return [
            'contract_version' => BigFiveCareerBridgeContract::OUTPUT_CONTRACT_VERSION,
            'bridge_id' => 'bridge-001',
            'status' => BigFiveCareerBridgeContract::STATUS_PUBLISHED_PROJECTION_READY,
            'claim_mode' => BigFiveCareerBridgeContract::CLAIM_MODE,
            'public_reader_allowed' => true,
            'source_locks' => [
                'big_five_asset_id' => 'big5.openness.high.en',
                'big_five_locale' => 'en',
                'big_five_published_revision_id' => 123,
                'big_five_public_projection_hash' => str_repeat('a', 64),
                'career_occupation_id' => 'occupation-001',
                'career_canonical_slug' => 'software-engineers',
                'career_locale' => 'en',
                'career_projection_version' => BigFiveCareerBridgeContract::CAREER_PROJECTION_VERSION,
                'career_runtime_projection_hash' => str_repeat('b', 64),
            ],
            'content' => [
                'reflection_signals' => ['Notice which work rhythms help you stay engaged.'],
                'environment_questions' => ['How much structure helps you do your best work?'],
                'feedback_and_structure_preferences' => ['Compare frequent feedback with independent review cycles.'],
                'possible_friction_cues' => ['Explore how you respond when priorities change quickly.'],
                'exploration_examples' => ['Interview people in the occupation about day-to-day constraints.'],
                'boundary_copy' => ['Use this alongside interests, skills, values, experience, and real constraints.'],
            ],
            'claim_boundary' => [
                'big_five_role' => BigFiveCareerBridgeContract::BIG_FIVE_ROLE,
                'primary_career_interest_signal' => BigFiveCareerBridgeContract::PRIMARY_CAREER_SIGNAL,
                'recommendation_authority' => false,
                'ranking_allowed' => false,
                'hiring_use_allowed' => false,
                'pseo_allowed' => false,
            ],
            'privacy_boundary' => $this->privacyBoundary(),
            'discoverability_changes' => false,
            'blockers' => [],
        ];
    }

    /** @return array<string, false> */
    private function privacyBoundary(): array
    {
        return [
            'contains_private_assessment_data' => false,
            'contains_user_identifiers' => false,
            'contains_attempt_or_report_links' => false,
            'contains_order_or_payment_data' => false,
        ];
    }
}
