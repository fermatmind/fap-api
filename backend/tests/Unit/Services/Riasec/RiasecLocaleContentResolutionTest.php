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
    public function test_selected_english_modules_are_available_while_feedback_fails_closed(): void
    {
        $registry = app(RiasecDeepCopySlotRegistry::class);
        $deepSlot = $registry->resolveForLocale($registry->resolveDimensionSlot('R'), 'en-US');

        $this->assertSame('en', $deepSlot['locale']);
        $this->assertSame('authored', $deepSlot['content_status']);
        $this->assertSame('omit_module', $deepSlot['fallback_behavior']);
        $this->assertFalse($deepSlot['frontend_fallback_allowed']);

        $activity = app(RiasecActivityExplorerService::class)->build('IAS', 'en-US');
        $this->assertSame('en', $activity['locale']);
        $this->assertSame('content_examples_only', $activity['status']);
        $this->assertSame([], $activity['dimension_activity_families']);
        $this->assertCount(9, $activity['code_activity_pack']['activities']);

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
        $this->assertSame('available', $lifecycleContract['status']);
        $this->assertCount(5, $lifecycleContract['surfaces']);
        $this->assertCount(20, $lifecycleContract['faq_items']);
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
        $this->assertNotEmpty($projection['deep_content_slots_v1']['slots']);
        $this->assertSame('content_examples_only', $projection['activity_explorer_v0_1']['status']);
        $this->assertNull($projection['private_result_authority']);
        $this->assertSame('unavailable', $projection['exploration_feedback_overlay_v0_1']['action_lab_v1']['status']);
        $this->assertSame('available', $projection['lifecycle_copy_v1']['status']);

        foreach ($projection['deep_content_slots_v1']['slots'] as $slot) {
            $this->assertSame('en', $slot['locale']);
        }
    }

    public function test_result_summary_is_snapshot_bound_localized_and_within_three_minute_limits(): void
    {
        $service = app(RiasecPublicProjectionService::class);
        $this->assertArrayNotHasKey('result_summary_v1', $service->buildV2FromResult($this->resultFixture(), 'zh-CN', false));
        $zh = $service->buildV2FromResult($this->resultFixture(), 'zh-CN', true)['result_summary_v1'];
        $en = $service->buildV2FromResult($this->resultFixture(), 'en-US', true)['result_summary_v1'];

        foreach ([[$zh, 'zh-CN'], [$en, 'en']] as [$summary, $locale]) {
            $this->assertSame('riasec.result_summary.v1', $summary['schema_version']);
            $this->assertSame($locale, $summary['locale']);
            $this->assertSame(180, $summary['estimated_read_seconds']);
            $this->assertTrue($summary['snapshot_bound']);
            $this->assertNotSame('', $summary['headline']);
            $this->assertNotSame('', $summary['ranking_display']);
            $this->assertNotSame('', $summary['quality_summary']);
            $this->assertNotSame('', $summary['next_step']);
            $this->assertNotSame('', $summary['boundary']);
            $this->assertCount(3, $summary['highlights']);
            $this->assertSame(['I', 'A', 'S'], array_column($summary['highlights'], 'dimension_code'));
        }

        $zhText = implode('', $this->summaryStrings($zh));
        $enText = implode(' ', $this->summaryStrings($en));
        $this->assertLessThanOrEqual(900, mb_strlen($zhText));
        $this->assertLessThanOrEqual(500, str_word_count($enText));
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $enText);
    }

    public function test_same_band_summary_highlights_remain_dimension_specific_and_concise(): void
    {
        $service = app(RiasecPublicProjectionService::class);
        foreach ([
            ['SEC', ['S' => 60, 'E' => 59, 'C' => 58, 'R' => 20, 'I' => 19, 'A' => 18]],
            ['ACE', ['A' => 30, 'C' => 29, 'E' => 28, 'R' => 20, 'I' => 19, 'S' => 18]],
        ] as [$code, $scores]) {
            $result = $this->resultFixture();
            $result->type_code = $code;
            $result->scores_pct = $scores;
            $result->result_json = array_merge((array) $result->result_json, [
                'top_code' => $code,
                'primary_type' => $code[0],
                'secondary_type' => $code[1],
                'tertiary_type' => $code[2],
            ]);
            $highlights = $service->buildV2FromResult($result, 'zh-CN', true)['result_summary_v1']['highlights'];
            $texts = array_column($highlights, 'text');
            $this->assertCount(3, array_unique($texts));
            foreach ($texts as $text) {
                $this->assertLessThanOrEqual(60, mb_strlen($text));
            }
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

    /** @return list<string> */
    private function summaryStrings(array $value): array
    {
        $strings = [];
        array_walk_recursive($value, static function (mixed $item) use (&$strings): void {
            if (is_string($item)) {
                $strings[] = $item;
            }
        });

        return $strings;
    }
}
