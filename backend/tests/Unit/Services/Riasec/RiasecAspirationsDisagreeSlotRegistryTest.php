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
            $this->assertTrue($slot['validation_questions_only']);
            $this->assertFalse($slot['aspiration_override_allowed']);
            $this->assertFalse($slot['aspiration_replaces_measured_result_allowed']);
            $this->assertSame('validation_questions_and_low_risk_experiment', $slot['recommended_output']);
            $this->assertSame('overlay_only_does_not_mutate_measured_result', $slot['result_binding']);
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
            $this->assertTrue($slot['next_steps_only']);
            $this->assertFalse($slot['feedback_replaces_measured_result_allowed']);
            $this->assertFalse($slot['result_override_allowed']);
            $this->assertFalse($slot['snapshot_share_pdf_mutation_allowed']);
            $this->assertFalse($slot['raw_feedback_public_exposure_allowed']);
            $this->assertSame('next_steps_and_optional_retake_only', $slot['recommended_output']);
            $this->assertSame('overlay_only_does_not_mutate_snapshot_share_pdf', $slot['result_binding']);
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
        $this->assertTrue($aspiration['validation_questions_only']);
        $this->assertFalse($aspiration['aspiration_override_allowed']);
        $this->assertFalse($aspiration['aspiration_replaces_measured_result_allowed']);
        $this->assertSame('validation_questions_and_low_risk_experiment', $aspiration['recommended_output']);
        $this->assertSame('overlay_only_does_not_mutate_measured_result', $aspiration['result_binding']);
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
        $this->assertTrue($disagree['next_steps_only']);
        $this->assertFalse($disagree['feedback_replaces_measured_result_allowed']);
        $this->assertFalse($disagree['result_override_allowed']);
        $this->assertFalse($disagree['snapshot_share_pdf_mutation_allowed']);
        $this->assertFalse($disagree['raw_feedback_public_exposure_allowed']);
        $this->assertSame('next_steps_and_optional_retake_only', $disagree['recommended_output']);
        $this->assertSame('overlay_only_does_not_mutate_snapshot_share_pdf', $disagree['result_binding']);
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

    public function test_file_backed_aspiration_assets_are_validation_questions_only(): void
    {
        foreach ($this->contentAssetAspirationRows() as $index => $row) {
            $this->assertTrue($row['validation_questions_only'], 'line '.($index + 1).' must be validation-only.');
            $this->assertFalse($row['aspiration_override_allowed'], 'line '.($index + 1).' must not override results.');
            $this->assertFalse($row['aspiration_replaces_measured_result_allowed'], 'line '.($index + 1).' must not replace measured result.');
            $this->assertSame('validation_questions_and_low_risk_experiment', $row['recommended_output']);
            $this->assertSame('overlay_only_does_not_mutate_measured_result', $row['result_binding']);
            $this->assertStringContainsString('探索假设', $row['overlap_reading']);
            $this->assertStringContainsString('不覆盖本次测得的霍兰德代码或分数', $row['overlap_reading']);
            $this->assertStringContainsString('不把它当成职业推荐或测评修正', $row['next_low_risk_experiment']);
        }
    }

    public function test_aspiration_copy_rejects_override_flags(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $aspiration = $registry->aspirationsSlots()['product_ux_想了解'];
        $aspiration['validation_questions_only'] = false;
        $aspiration['aspiration_override_allowed'] = true;
        $aspiration['aspiration_replaces_measured_result_allowed'] = true;
        $aspiration['recommended_output'] = 'rewrite_result';
        $aspiration['result_binding'] = 'replace_measured_result';

        $errors = $registry->validateSlot($aspiration);

        $this->assertContains('aspirations_validation_questions_only_must_be_true', $errors);
        $this->assertContains('aspirations_aspiration_override_allowed_must_be_false', $errors);
        $this->assertContains('aspirations_aspiration_replaces_measured_result_allowed_must_be_false', $errors);
        $this->assertContains('unsupported_aspirations_recommended_output', $errors);
        $this->assertContains('unsupported_aspirations_result_binding', $errors);
    }

    public function test_file_backed_disagree_assets_are_next_steps_only(): void
    {
        foreach ($this->contentAssetDisagreeRows() as $index => $row) {
            $this->assertTrue($row['next_steps_only'], 'line '.($index + 1).' must be next-step only.');
            $this->assertFalse($row['feedback_replaces_measured_result_allowed'], 'line '.($index + 1).' must not replace measured result.');
            $this->assertFalse($row['result_override_allowed'], 'line '.($index + 1).' must not override results.');
            $this->assertFalse($row['snapshot_share_pdf_mutation_allowed'], 'line '.($index + 1).' must not mutate snapshot/share/PDF.');
            $this->assertFalse($row['raw_feedback_public_exposure_allowed'], 'line '.($index + 1).' must not expose raw feedback publicly.');
            $this->assertSame('next_steps_and_optional_retake_only', $row['recommended_output']);
            $this->assertSame('overlay_only_does_not_mutate_snapshot_share_pdf', $row['result_binding']);
            $this->assertMatchesRegularExpression('/不会|不能|不应|不比较|不把/u', $row['summary']);
            $this->assertMatchesRegularExpression('/不改|不修改|不覆盖|不互相覆盖/u', $row['recommended_next_action']);
        }
    }

    public function test_disagree_copy_rejects_result_and_public_surface_override_flags(): void
    {
        $registry = new RiasecDeepCopySlotRegistry;
        $disagree = $registry->disagreePathSlots()['normal_disagree_学生'];
        $disagree['next_steps_only'] = false;
        $disagree['feedback_replaces_measured_result_allowed'] = true;
        $disagree['result_override_allowed'] = true;
        $disagree['snapshot_share_pdf_mutation_allowed'] = true;
        $disagree['raw_feedback_public_exposure_allowed'] = true;
        $disagree['recommended_output'] = 'rewrite_result';
        $disagree['result_binding'] = 'replace_snapshot_share_pdf';

        $errors = $registry->validateSlot($disagree);

        $this->assertContains('disagree_next_steps_only_must_be_true', $errors);
        $this->assertContains('disagree_feedback_replaces_measured_result_allowed_must_be_false', $errors);
        $this->assertContains('disagree_result_override_allowed_must_be_false', $errors);
        $this->assertContains('disagree_snapshot_share_pdf_mutation_allowed_must_be_false', $errors);
        $this->assertContains('disagree_raw_feedback_public_exposure_allowed_must_be_false', $errors);
        $this->assertContains('unsupported_disagree_recommended_output', $errors);
        $this->assertContains('unsupported_disagree_result_binding', $errors);
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

    /**
     * @return list<array<string,mixed>>
     */
    private function contentAssetAspirationRows(): array
    {
        $path = __DIR__.'/../../../../content_assets/riasec/aspirations_calibration_v1.zh-CN.jsonl';
        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function contentAssetDisagreeRows(): array
    {
        $path = __DIR__.'/../../../../content_assets/riasec/disagree_path_v1.zh-CN.jsonl';
        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return $rows;
    }
}
