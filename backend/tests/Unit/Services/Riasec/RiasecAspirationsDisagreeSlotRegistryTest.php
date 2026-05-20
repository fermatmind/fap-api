<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use PHPUnit\Framework\TestCase;

final class RiasecAspirationsDisagreeSlotRegistryTest extends TestCase
{
    public function test_aspirations_slots_are_backend_authored_and_non_mutating(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->aspirationsSlots();

        foreach ([
            'intro',
            'input_boundary',
            'overlap_reading',
            'tension_reading',
            'reality_questions',
            'education_skill_qualification_boundary',
            'next_experiment_prompt',
            'no_score_mutation_boundary',
        ] as $slotName) {
            $slot = $slots[$slotName] ?? null;
            $this->assertIsArray($slot, $slotName.' slot should exist.');
            $this->assertSame('aspirations_copy', $slot['slot_group']);
            $this->assertSame('authored', $slot['content_status']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->aspirationsRequiredFields() as $field) {
                $this->assertArrayHasKey($field, $slot);
            }

            $this->assertFalse($slot['affects_measured_code']);
            $this->assertFalse($slot['affects_score']);
            $this->assertFalse($slot['report_snapshot_mutation_allowed']);
            $this->assertFalse($slot['share_pdf_payload_expansion_allowed']);
            $this->assertFalse($slot['raw_feedback_exposure_allowed']);
            $this->assertSame([], $registry->validateSlot($slot), $slotName.' should be contract-clean.');
        }
    }

    public function test_disagree_path_slots_are_backend_authored_and_non_mutating(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $slots = $registry->disagreePathSlots();

        foreach ([
            'user_not_wrong_message',
            'possible_reasons',
            'retake_when',
            'experiment_when',
            'record_preferred_direction_boundary',
            'feedback_no_mutation_boundary',
            'next_step',
        ] as $slotName) {
            $slot = $slots[$slotName] ?? null;
            $this->assertIsArray($slot, $slotName.' slot should exist.');
            $this->assertSame('feedback_response_copy', $slot['slot_group']);
            $this->assertSame('authored', $slot['content_status']);
            $this->assertFalse($slot['frontend_fallback_allowed']);

            foreach ($registry->disagreePathRequiredFields() as $field) {
                $this->assertArrayHasKey($field, $slot);
            }

            $this->assertFalse($slot['affects_measured_code']);
            $this->assertFalse($slot['affects_score']);
            $this->assertFalse($slot['report_snapshot_mutation_allowed']);
            $this->assertFalse($slot['share_pdf_payload_expansion_allowed']);
            $this->assertFalse($slot['raw_feedback_exposure_allowed']);
            $this->assertSame([], $registry->validateSlot($slot), $slotName.' should be contract-clean.');
        }
    }

    public function test_aspirations_and_disagree_state_enums_cover_required_states(): void
    {
        foreach (['not_provided', 'overlap', 'tension', 'needs_reality_check', 'high_risk_boundary'] as $state) {
            $this->assertContains($state, RiasecDeepCopySlotRegistry::ASPIRATIONS_STATES);
        }

        foreach (['disagrees_quality_normal', 'disagrees_quality_caution', 'retake_recommended', 'save_feedback_only'] as $state) {
            $this->assertContains($state, RiasecDeepCopySlotRegistry::DISAGREE_STATES);
        }
    }

    public function test_file_backed_aspirations_and_disagree_assets_are_imported_as_non_mutating_slots(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $aspirationSlots = array_filter(
            $registry->aspirationsSlots(),
            fn (array $slot): bool => ($slot['content_version'] ?? null) === 'aspirations_calibration_v1.zh-CN'
        );
        $disagreeSlots = array_filter(
            $registry->disagreePathSlots(),
            fn (array $slot): bool => ($slot['content_version'] ?? null) === 'disagree_path_v1.zh-CN'
        );

        $this->assertCount(70, $aspirationSlots);
        $this->assertCount(45, $disagreeSlots);

        $aspiration = $aspirationSlots['product_ux_想了解'] ?? null;
        $this->assertIsArray($aspiration);
        $this->assertSame('aspirations_copy', $aspiration['slot_group']);
        $this->assertSame('overlap', $aspiration['aspirations_state']);
        $this->assertSame('产品 / 用户研究｜想了解', $aspiration['title']);
        $this->assertFalse($aspiration['affects_measured_code']);
        $this->assertFalse($aspiration['affects_score']);
        $this->assertFalse($aspiration['report_snapshot_mutation_allowed']);
        $this->assertFalse($aspiration['share_pdf_payload_expansion_allowed']);
        $this->assertFalse($aspiration['raw_feedback_exposure_allowed']);
        $this->assertFalse($aspiration['frontend_fallback_allowed']);
        $this->assertSame([], $registry->validateSlot($aspiration));

        $disagree = $disagreeSlots['normal_disagree_学生'] ?? null;
        $this->assertIsArray($disagree);
        $this->assertSame('feedback_response_copy', $disagree['slot_group']);
        $this->assertSame('disagrees_quality_normal', $disagree['disagree_state']);
        $this->assertSame('如果你不认同结果｜学生', $disagree['title']);
        $this->assertFalse($disagree['affects_measured_code']);
        $this->assertFalse($disagree['affects_score']);
        $this->assertFalse($disagree['report_snapshot_mutation_allowed']);
        $this->assertFalse($disagree['share_pdf_payload_expansion_allowed']);
        $this->assertFalse($disagree['raw_feedback_exposure_allowed']);
        $this->assertFalse($disagree['frontend_fallback_allowed']);
        $this->assertSame([], $registry->validateSlot($disagree));
    }

    public function test_missing_aspirations_and_disagree_content_fails_closed(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $missingAspiration = $registry->resolveAspirationsSlot('unsupported_slot');
        $missingDisagree = $registry->resolveDisagreePathSlot('unsupported_slot');

        foreach ([$missingAspiration, $missingDisagree] as $missing) {
            $this->assertSame('unavailable', $missing['content_status']);
            $this->assertSame('omitted', $missing['module_state']);
            $this->assertSame('omit_module', $missing['fallback_behavior']);
            $this->assertFalse($missing['frontend_fallback_allowed']);
        }
    }

    public function test_high_risk_boundary_slot_names_education_skill_qualification_and_ethics(): void
    {
        $slot = (new RiasecDeepCopySlotRegistry)->aspirationsSlots()['education_skill_qualification_boundary'];

        $this->assertSame('high_risk_boundary', $slot['aspirations_state']);
        $this->assertStringContainsString('教育', $slot['summary']);
        $this->assertStringContainsString('技能', $slot['summary']);
        $this->assertStringContainsString('资格', $slot['summary']);
        $this->assertStringContainsString('伦理', $slot['summary']);
    }

    public function test_aspirations_and_disagree_copy_rejects_result_override_claims(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $aspiration = $registry->aspirationsSlots()['intro'];
        $aspiration['summary'] = '系统判定你适合这个方向，愿望覆盖测评结果。';

        $disagree = $registry->disagreePathSlots()['feedback_no_mutation_boundary'];
        $disagree['summary'] = '系统修正了你的 Code，反馈会改分，所以你不适合原方向。';

        $this->assertContains('forbidden_claim_phrase_non_ascii', $registry->validateSlot($aspiration));
        $this->assertContains('forbidden_claim_phrase_non_ascii', $registry->validateSlot($disagree));
    }

    public function test_existing_feedback_overlay_does_not_mutate_snapshot_share_or_pdf_payloads(): void
    {
        $overlay = (new RiasecExplorationFeedbackOverlayService)->build(
            new Result([
                'scale_code' => 'RIASEC',
                'type_code' => 'IAS',
                'result_json' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ]),
            [
                'holland_code' => ['code' => 'IAS'],
                'form' => [
                    'form_code' => 'riasec_60',
                    'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
                ],
            ],
            true
        );

        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.scores_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.holland_code_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'measured_result_guard.report_snapshot_mutation_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.share_pdf_exposure_allowed'));
        $this->assertFalse((bool) data_get($overlay, 'surface_policy.raw_feedback_public_exposure_allowed'));
    }
}
