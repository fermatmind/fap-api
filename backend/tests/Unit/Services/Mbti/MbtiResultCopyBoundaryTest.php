<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiResultPersonalizationService;
use Tests\TestCase;

final class MbtiResultCopyBoundaryTest extends TestCase
{
    public function test_actual_personalization_keeps_scores_and_only_describes_real_close_calls(): void
    {
        foreach (['MBTI-CN-v0.3', 'MBTI-CN-v0.3-form-93'] as $directory) {
            foreach (['en', 'zh-CN'] as $locale) {
                foreach ([50, 55, 70, 95] as $percent) {
                    $scores = [];
                    foreach (['EI' => 'I', 'SN' => 'N', 'TF' => 'T', 'JP' => 'J', 'AT' => 'T'] as $axis => $side) {
                        $scores[$axis] = ['pct' => $percent, 'delta' => abs($percent - 50), 'side' => $side, 'state' => ''];
                    }
                    $payload = ['versions' => ['engine' => 'v1.2', 'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3', 'dir_version' => $directory],
                        'profile' => ['type_code' => 'INTJ-T'], 'scores' => $scores];
                    $before = $payload;
                    $result = app(MbtiResultPersonalizationService::class)->buildForReportPayload($payload, [
                        'type_code' => 'INTJ-T', 'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3', 'dir_version' => $directory,
                        'locale' => $locale, 'engine_version' => 'v1.2',
                    ]);
                    $this->assertSame($before, $payload);
                    $this->assertSame('INTJ-T', $result['type_code']);
                    $this->assertSame('T', $result['identity']);
                    foreach ($result['axis_vector'] as $axis) {
                        $this->assertSame($percent, $axis['pct']);
                        $this->assertSame(abs($percent - 50), $axis['delta']);
                    }
                    $this->assertCount($percent <= 55 ? 2 : 0, $result['close_call_axes']);
                    $text = implode(' ', array_column($result['sections']['traits.close_call_axes']['blocks'], 'text'));
                    if ($percent === 50) {
                        $this->assertStringContainsString($locale === 'en' ? 'tied' : '持平', $text);
                    } elseif ($percent === 55) {
                        $this->assertStringContainsString($locale === 'en' ? '5 percentage points from the 50% midpoint' : '距50%中点5个百分点', $text);
                    }
                }
            }
        }
    }

    public function test_saved_before_change_copy_is_refreshed_at_both_existing_read_entrypoints(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/mbti_precloseout_personalization_20261030.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($fixture['no_user_answers']);
        $this->assertCount(8, $fixture['cases']);
        $service = app(MbtiResultPersonalizationService::class);
        foreach ($fixture['cases'] as $case) {
            $legacy = $case['personalization'];
            $before = $legacy;
            $base = ['sections' => array_map(static fn (string $key): array => ['key' => $key, 'body_md' => 'Native type body', 'payload' => []], array_keys($legacy['sections']))];
            $projected = $service->applyToProjection($base, $legacy);
            $current = $projected['_meta']['personalization'];
            $this->assertSame($before, $legacy);
            $this->assertStringStartsWith('mbti.interpretation_copy.', $current['interpretation_copy_version']);
            $copyFields = ['sections', 'close_call_axes', 'scene_fingerprint', 'action_plan_summary', 'explainability_summary', 'work_style_summary', 'interpretation_copy_version'];
            $this->assertSame(array_diff_key($before, array_flip($copyFields)), array_diff_key($current, array_flip($copyFields)));
            foreach ($before['scene_fingerprint'] as $key => $scene) {
                $this->assertSame(array_diff_key($scene, ['summary' => true]), array_diff_key($current['scene_fingerprint'][$key], ['summary' => true]));
            }
            foreach (['axis_strength', 'identity'] as $kind) {
                $old = collect($before['sections']['overview']['blocks'])->firstWhere('kind', $kind)['text'];
                $new = collect($current['sections']['overview']['blocks'])->firstWhere('kind', $kind)['text'];
                $this->assertNotSame($old, $new);
            }
            foreach (['overview' => 'scene', 'career.summary' => 'work_style', 'career.preferred_roles' => 'role_fit'] as $key => $kind) {
                $old = collect($before['sections'][$key]['blocks'])->firstWhere('kind', $kind)['text'];
                $new = collect($current['sections'][$key]['blocks'])->firstWhere('kind', $kind)['text'];
                $this->assertNotSame($old, $new);
            }
            $this->assertNotSame($before['action_plan_summary'], $current['action_plan_summary']);
            $closeSection = collect($projected['sections'])->firstWhere('key', 'traits.close_call_axes');
            if ($case['percent'] === 80) {
                $this->assertSame([], $current['close_call_axes']);
                $this->assertSame([], $current['sections']['traits.close_call_axes']['close_call_axes']);
                $this->assertNotContains('borderline_axis', array_column($closeSection['payload']['blocks'] ?? [], 'kind'));
            } else {
                $text = implode(' ', array_column($closeSection['payload']['blocks'] ?? [], 'text'));
                $this->assertStringContainsString($case['locale'] === 'en' ? 'tied' : '持平', $text);
            }
            $viaExisting = $service->attachCtaBundleForExistingPersonalization($legacy, $case['context']);
            $this->assertSame($current['close_call_axes'], $viaExisting['close_call_axes']);
            foreach (['axis_vector', 'axis_bands', 'type_code', 'identity', 'user_state_v1', 'longitudinal_memory_v1', 'adaptive_selection_v1', 'comparative_v1'] as $key) {
                $this->assertSame($before[$key] ?? null, $viaExisting[$key] ?? null);
            }
            $this->assertSame($projected, $service->applyToProjection($base, $current));
        }
        $incomplete = $fixture['cases'][0]['personalization'];
        unset($incomplete['axis_vector']['AT']);
        $result = $service->applyToProjection(['sections' => []], $incomplete);
        $this->assertSame($incomplete, $result['_meta']['personalization']);
        $this->assertArrayNotHasKey('interpretation_copy_version', $result['_meta']['personalization']);
        $missingPrimary = $fixture['cases'][0]['personalization'];
        unset($missingPrimary['sections']['overview']['primary_axis']);
        $this->assertSame($missingPrimary, $service->applyToProjection(['sections' => []], $missingPrimary)['_meta']['personalization']);
        foreach (array_keys($fixture['cases'][0]['personalization']['scene_fingerprint']) as $scene) {
            foreach (['primary_axis', 'summary'] as $field) {
                $incompleteScene = $fixture['cases'][0]['personalization'];
                unset($incompleteScene['scene_fingerprint'][$scene][$field]);
                $this->assertSame($incompleteScene, $service->applyToProjection(['sections' => []], $incompleteScene)['_meta']['personalization']);
            }
        }
        $incompleteAxis = $fixture['cases'][0]['personalization'];
        unset($incompleteAxis['sections']['overview']['primary_axis']['pct']);
        $this->assertSame($incompleteAxis, $service->applyToProjection(['sections' => []], $incompleteAxis)['_meta']['personalization']);
        $future = $fixture['cases'][0]['personalization'];
        $future['interpretation_copy_version'] = 'mbti.interpretation_copy.20990101.v1';
        $this->assertSame($future, $service->applyToProjection(['sections' => []], $future)['_meta']['personalization']);

    }
}
