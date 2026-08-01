<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Riasec\RiasecActivityExplorerService;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecExplorationFeedbackOverlayService;
use App\Services\Riasec\RiasecLifecycleCopyService;
use App\Services\Riasec\RiasecPublicProjectionService;
use Tests\TestCase;

final class RiasecLocaleContentResolutionTest extends TestCase
{
    public function test_english_deep_activity_feedback_and_lifecycle_content_fail_closed_without_chinese_fallback(): void
    {
        $registry = app(RiasecDeepCopySlotRegistry::class);
        $deepSlot = $registry->resolveForLocale($registry->resolveDimensionSlot('R'), 'en-US');

        $this->assertSame('en', $deepSlot['locale']);
        $this->assertSame('zh-CN', $deepSlot['source_locale']);
        $this->assertSame('unavailable', $deepSlot['content_status']);
        $this->assertSame('omit_module', $deepSlot['fallback_behavior']);
        $this->assertFalse($deepSlot['frontend_fallback_allowed']);

        $activity = app(RiasecActivityExplorerService::class)->build('IAS', 'en-US');
        $this->assertSame('en', $activity['locale']);
        $this->assertSame('unavailable', $activity['status']);
        $this->assertSame([], $activity['dimension_activity_families']);
        $this->assertSame([], $activity['code_activity_pack']['activities']);

        $result = $this->resultFixture();
        $feedback = app(RiasecExplorationFeedbackOverlayService::class)->build($result, $this->projectionFixture(), true, 'en-US');
        $this->assertSame('en', $feedback['locale']);
        $this->assertSame('unavailable', $feedback['action_lab_v1']['status']);
        $this->assertSame('unavailable', $feedback['next_exploration_nodes_v1']['status']);
        $this->assertSame([], $feedback['action_lab_v1']['starter_actions']);
        $this->assertSame([], $feedback['next_exploration_nodes_v1']['nodes']);

        $lifecycle = app(RiasecLifecycleCopyService::class);
        $lifecycleContract = $lifecycle->runtimeLifecycleCopyContract(true, 'en-US');
        $this->assertSame('en', $lifecycleContract['locale']);
        $this->assertSame('unavailable', $lifecycleContract['status']);
        $this->assertSame([], $lifecycleContract['surfaces']);
        $this->assertSame([], $lifecycleContract['faq_items']);
        $this->assertFalse($lifecycleContract['faq_markdown_reference_available']);

        $serialized = json_encode([
            'deep' => $deepSlot,
            'activity' => $activity,
            'feedback' => $feedback,
            'lifecycle' => $lifecycleContract,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $serialized);
    }

    public function test_english_projection_omits_unavailable_deep_slots_and_keeps_every_returned_slot_locale_equal(): void
    {
        $projection = app(RiasecPublicProjectionService::class)->buildV2FromResult($this->resultFixture(), 'en-US', true);

        $this->assertSame('en', $projection['locale']);
        $this->assertSame('en', $projection['deep_content_slots_v1']['locale']);
        $this->assertSame([], $projection['deep_content_slots_v1']['slots']);
        $this->assertSame('unavailable', $projection['activity_explorer_v0_1']['status']);
        $this->assertSame('unavailable', $projection['exploration_feedback_overlay_v0_1']['action_lab_v1']['status']);
        $this->assertSame('unavailable', $projection['lifecycle_copy_v1']['status']);

        foreach ($projection['deep_content_slots_v1']['slots'] as $slot) {
            $this->assertSame('en', $slot['locale']);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function projectionFixture(): array
    {
        return [
            'holland_code' => ['code' => 'IAS'],
            'form' => [
                'form_code' => 'riasec_60',
                'score_space_version' => 'riasec_60_likert5_activity_sum_space.v1',
            ],
        ];
    }

    private function resultFixture(): Result
    {
        return new Result([
            'scale_code' => 'RIASEC',
            'type_code' => 'IAS',
            'scores_pct' => ['I' => 80, 'A' => 72, 'S' => 65, 'R' => 42, 'E' => 39, 'C' => 36],
            'result_json' => [
                'top_code' => 'IAS',
                'primary_type' => 'I',
                'secondary_type' => 'A',
                'tertiary_type' => 'S',
                'form_code' => 'riasec_60',
                'answer_count' => 60,
                'quality_grade' => 'A',
                'quality_flags' => [],
            ],
        ]);
    }
}
