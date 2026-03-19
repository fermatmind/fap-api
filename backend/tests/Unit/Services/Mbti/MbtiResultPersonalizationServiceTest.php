<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiResultPersonalizationService;
use Tests\TestCase;

final class MbtiResultPersonalizationServiceTest extends TestCase
{
    public function test_it_emits_distinct_overview_variants_for_same_type_when_ei_strength_changes(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $clearPayload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 67, 'delta' => 17, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 64, 'delta' => 14, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 57, 'delta' => 7, 'side' => 'P', 'state' => 'moderate'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
        ];

        $strongPayload = $clearPayload;
        $strongPayload['scores']['EI'] = ['pct' => 77, 'delta' => 27, 'side' => 'E', 'state' => 'strong'];
        $strongPayload['axis_states']['EI'] = 'strong';

        $clear = $service->buildForReportPayload($clearPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase2_contract',
        ]);

        $strong = $service->buildForReportPayload($strongPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase2_contract',
        ]);

        $this->assertSame('ENFP-T', $clear['type_code']);
        $this->assertSame('T', $clear['identity']);
        $this->assertSame('mbti.personalization.phase2.v1', $clear['schema_version']);
        $this->assertSame('clear', data_get($clear, 'axis_bands.EI'));
        $this->assertSame('strong', data_get($strong, 'axis_bands.EI'));
        $this->assertSame(false, data_get($clear, 'boundary_flags.EI'));
        $this->assertSame(false, data_get($strong, 'boundary_flags.EI'));
        $this->assertSame('MBTI.cn-mainland.zh-CN.v0.3', $clear['pack_id']);
        $this->assertSame('report_phase2_contract', $clear['engine_version']);
        $this->assertSame('work.primary.EI.E.clear', data_get($clear, 'scene_fingerprint.work.style_key'));
        $this->assertSame('work.primary.EI.E.strong', data_get($strong, 'scene_fingerprint.work.style_key'));
        $this->assertSame(
            [
                'relationships.primary.TF.T.boundary',
                'relationships.support.EI.E.clear',
                'relationships.identity.T',
                'relationships.boundary.TF',
                'relationships.boundary.JP',
            ],
            data_get($clear, 'relationship_style_keys')
        );
        $this->assertNotSame(
            data_get($clear, 'variant_keys.overview'),
            data_get($strong, 'variant_keys.overview')
        );
        $this->assertSame(
            'overview:EI.E.clear:identity.T:boundary.none',
            data_get($clear, 'variant_keys.overview')
        );
        $this->assertSame(
            'overview:EI.E.strong:identity.T:boundary.none',
            data_get($strong, 'variant_keys.overview')
        );
        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            $clear['variant_keys']['relationships.rel_risks'] ?? null
        );
        $this->assertNotSame(
            data_get($clear, 'sections.overview.selected_blocks.0'),
            data_get($strong, 'sections.overview.selected_blocks.0')
        );
        $this->assertStringContainsString(
            '稳定的外倾倾向',
            (string) data_get($clear, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '外倾偏好已经很鲜明',
            (string) data_get($strong, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '两套判断入口之间来回校准',
            (string) ($clear['sections']['relationships.rel_risks']['blocks'][3]['text'] ?? '')
        );
        $this->assertStringContainsString(
            '压力升高时',
            (string) data_get($clear, 'scene_fingerprint.stress_recovery.summary', '')
        );
    }

    public function test_it_falls_back_to_english_defaults_when_requested_locale_does_not_match_pack_locale(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $payload = [
            'versions' => [
                'engine' => 'report_phase2_contract',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 67, 'delta' => 17, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 64, 'delta' => 14, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 57, 'delta' => 7, 'side' => 'P', 'state' => 'moderate'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
        ];

        $english = $service->buildForReportPayload($payload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'en',
            'engine_version' => 'report_phase2_contract',
        ]);

        $this->assertSame('en', $english['locale']);
        $this->assertStringContainsString(
            'stable Extraversion preference',
            (string) data_get($english, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            'At work, you usually start through',
            (string) data_get($english, 'scene_fingerprint.work.summary', '')
        );
        $this->assertStringNotContainsString(
            '稳定的外倾倾向',
            (string) data_get($english, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringNotContainsString(
            '在工作里',
            (string) data_get($english, 'scene_fingerprint.work.summary', '')
        );
    }
}
