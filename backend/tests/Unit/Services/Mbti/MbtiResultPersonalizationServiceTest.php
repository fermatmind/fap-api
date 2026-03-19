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
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'F', 'state' => 'balanced'],
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
            'engine_version' => 'v1.2',
        ]);

        $strong = $service->buildForReportPayload($strongPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'v1.2',
        ]);

        $this->assertSame('ENFP-T', $clear['type_code']);
        $this->assertSame('T', $clear['identity']);
        $this->assertSame('clear', data_get($clear, 'axis_bands.EI'));
        $this->assertSame('strong', data_get($strong, 'axis_bands.EI'));
        $this->assertSame(false, data_get($clear, 'boundary_flags.EI'));
        $this->assertSame(false, data_get($strong, 'boundary_flags.EI'));
        $this->assertSame('MBTI.cn-mainland.zh-CN.v0.3', $clear['pack_id']);
        $this->assertSame('v1.2', $clear['engine_version']);
        $this->assertNotSame(
            data_get($clear, 'variant_keys.overview'),
            data_get($strong, 'variant_keys.overview')
        );
        $this->assertSame(
            'overview:EI.E.clear:identity.T:boundary.TF',
            data_get($clear, 'variant_keys.overview')
        );
        $this->assertSame(
            'overview:EI.E.strong:identity.T:boundary.TF',
            data_get($strong, 'variant_keys.overview')
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
    }
}
