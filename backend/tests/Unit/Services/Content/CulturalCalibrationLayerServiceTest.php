<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\CulturalCalibrationLayerService;
use Tests\TestCase;

final class CulturalCalibrationLayerServiceTest extends TestCase
{
    public function test_it_builds_mbti_calibration_from_content_governance_when_pack_context_is_available(): void
    {
        $service = app(CulturalCalibrationLayerService::class);

        $calibration = $service->buildForMbti([
            'action_focus_key' => 'growth.next_actions',
            'working_life_v1' => [
                'career_focus_key' => 'career.next_step',
            ],
        ], [
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
        ]);

        $this->assertSame('cultural_calibration.v1', $calibration['version']);
        $this->assertSame('zh-CN', $calibration['locale_context']);
        $this->assertSame('CN_MAINLAND.zh-CN', $calibration['cultural_context']);
        $this->assertSame('governance.v1', $calibration['calibration_policy_version']);
        $this->assertSame('content_governance', $calibration['calibration_source']);
        $this->assertContains('career.next_step', $calibration['calibrated_section_keys']);
        $this->assertContains('relationships.communication_style', $calibration['calibrated_section_keys']);
        $this->assertNotSame('', trim((string) ($calibration['narrative_overrides']['intro'] ?? '')));
        $this->assertNotSame('', trim((string) ($calibration['working_life_summary'] ?? '')));
        $this->assertNotSame('', trim((string) ($calibration['calibration_fingerprint'] ?? '')));
    }

    public function test_it_builds_runtime_policy_calibration_for_english_mbti_and_big_five(): void
    {
        $service = app(CulturalCalibrationLayerService::class);

        $mbtiCalibration = $service->buildForMbti([
            'action_focus_key' => 'growth.next_actions',
            'working_life_v1' => [
                'career_focus_key' => 'career.next_step',
            ],
        ], [
            'locale' => 'en-US',
            'region' => 'US',
        ]);

        $bigFiveCalibration = $service->buildForBigFive([
            'variant_keys' => ['profile:explorer'],
        ], [
            'locale' => 'en-US',
            'region' => 'US',
        ]);

        $this->assertSame('en-US', $mbtiCalibration['locale_context']);
        $this->assertSame('US.en-US', $mbtiCalibration['cultural_context']);
        $this->assertSame('runtime_policy', $mbtiCalibration['calibration_source']);
        $this->assertSame('runtime.locale_policy.v1', $mbtiCalibration['calibration_policy_version']);
        $this->assertContains('growth.next_actions', $mbtiCalibration['calibrated_section_keys']);

        $this->assertSame('en-US', $bigFiveCalibration['locale_context']);
        $this->assertSame('US.en-US', $bigFiveCalibration['cultural_context']);
        $this->assertSame('runtime_policy', $bigFiveCalibration['calibration_source']);
        $this->assertContains('traits.overview', $bigFiveCalibration['calibrated_section_keys']);
        $this->assertNotSame('', trim((string) ($bigFiveCalibration['narrative_overrides']['summary'] ?? '')));
    }
}
