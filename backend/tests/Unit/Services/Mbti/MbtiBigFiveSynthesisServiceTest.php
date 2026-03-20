<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiBigFiveSynthesisService;
use Tests\TestCase;

final class MbtiBigFiveSynthesisServiceTest extends TestCase
{
    public function test_it_builds_stable_cross_assessment_authority_from_big_five_projection(): void
    {
        $service = app(MbtiBigFiveSynthesisService::class);

        $authority = $service->buildAuthorityFromProjection([
            'trait_bands' => [
                'O' => 'mid',
                'C' => 'low',
                'E' => 'mid',
                'A' => 'high',
                'N' => 'high',
            ],
            'dominant_traits' => [
                ['key' => 'N'],
                ['key' => 'A'],
                ['key' => 'O'],
            ],
        ], [
            'locale' => 'zh-CN',
        ], 'zh-CN', 'big5-attempt-1');

        $this->assertSame('mbti_big5.cross_assessment.v1', $authority['version']);
        $this->assertSame(['BIG5_OCEAN'], $authority['supporting_scales']);
        $this->assertSame('big5-attempt-1', $authority['supporting_attempt_id']);
        $this->assertSame(
            [
                'big5.neuroticism.high.buffer_reactivity',
                'big5.conscientiousness.low.use_external_scaffolding',
                'big5.career_next_step.low.reduce_activation_friction',
            ],
            $authority['synthesis_keys']
        );
        $this->assertSame(
            ['growth.stability_confidence', 'growth.next_actions', 'career.next_step'],
            $authority['mbti_adjusted_focus_keys']
        );
        $this->assertSame(['N', 'A', 'O'], $authority['supporting_traits']);
        $stability = $authority['section_enhancements']['growth.stability_confidence'] ?? [];
        $nextActions = $authority['section_enhancements']['growth.next_actions'] ?? [];
        $careerNextStep = $authority['section_enhancements']['career.next_step'] ?? [];
        $this->assertSame(
            'big5.neuroticism.high.buffer_reactivity',
            $stability['synthesis_key'] ?? null
        );
        $this->assertStringContainsString(
            '情绪性更高',
            (string) ($stability['body'] ?? '')
        );
        $this->assertSame(
            'big5.conscientiousness.low.use_external_scaffolding',
            $nextActions['synthesis_key'] ?? null
        );
        $this->assertStringContainsString(
            '外部提醒',
            (string) ($nextActions['body'] ?? '')
        );
        $this->assertSame(
            'big5.career_next_step.low.reduce_activation_friction',
            $careerNextStep['synthesis_key'] ?? null
        );
        $this->assertStringContainsString(
            '职业下一步',
            (string) ($careerNextStep['title'] ?? '')
        );
    }
}
