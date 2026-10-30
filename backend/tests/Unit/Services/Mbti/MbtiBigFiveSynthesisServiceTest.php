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

    public function test_all_nine_new_and_saved_enhancements_preserve_measurement_and_unknown_state(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/mbti_big5_cross_copy.json')), true, 64, JSON_THROW_ON_ERROR);
        $service = app(MbtiBigFiveSynthesisService::class);
        $reader = app(\App\Services\Mbti\MbtiResultPersonalizationService::class);
        $ownedCases = json_decode((string) file_get_contents(base_path('tests/Fixtures/mbti_precloseout_personalization_20261030.json')), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $ownedCase = collect($ownedCases)->firstWhere('locale', 'en');
        foreach ($fixture as $case) {
            $before = $case['before'];
            $after = $case['after'];
            $band = explode('.', $before['influence_keys'][0])[3];
            $authority = $service->buildAuthorityFromProjection(['trait_bands' => ['N' => $band, 'C' => $band]], [], 'en', 'fixed-no-answers');
            $this->assertSame($after, $authority['section_enhancements'][$before['section_key']]);
            $authority['section_enhancements'][$before['section_key']] = $before;
            $authority['future'] = ['preserve' => true];
            $saved = ['schema_version' => 'mbti.personalization.phase9e.v1', 'locale' => 'en',
                'interpretation_copy_version' => 'mbti.interpretation_copy.20261030.v1',
                'scores' => ['N' => 67, 'C' => 41], 'cross_assessment_v1' => $authority];
            $expected = $saved;
            $expected['cross_assessment_v1']['section_enhancements'][$before['section_key']] = $after;
            $this->assertSame($expected, $service->refreshSavedEnglishCopy($saved, 'en'));
            $this->assertSame($expected, $service->refreshSavedEnglishCopy($expected, 'en'));
            $this->assertSame($expected, $reader->applyToProjection(['sections' => []], $saved)['_meta']['personalization']);
            $this->assertSame($authority, $saved['cross_assessment_v1']);
            $fullSaved = $ownedCase['personalization'];
            $fullSaved['cross_assessment_v1'] = $authority;
            $viaExisting = $reader->attachCtaBundleForExistingPersonalization($fullSaved, $ownedCase['context']);
            $this->assertSame($expected['cross_assessment_v1'], $viaExisting['cross_assessment_v1']);
            $this->assertSame($fullSaved['axis_vector'], $viaExisting['axis_vector']);
            $this->assertSame($authority, $fullSaved['cross_assessment_v1']);
            foreach (['schema_version', 'version', 'title', 'body', 'supporting_scale', 'synthesis_key', 'influence_keys'] as $field) {
                $unknown = $saved;
                if ($field === 'schema_version') {
                    $unknown[$field] = 'future';
                } elseif ($field === 'version') {
                    $unknown['cross_assessment_v1'][$field] = 'future';
                } else {
                    $unknown['cross_assessment_v1']['section_enhancements'][$before['section_key']][$field] = 'future';
                }
                $this->assertSame($unknown, $service->refreshSavedEnglishCopy($unknown, 'en'), $field);
            }
            $this->assertSame($saved, $service->refreshSavedEnglishCopy($saved, 'zh-CN'));
            $future = $saved;
            $future['interpretation_copy_version'] = 'mbti.interpretation_copy.future';
            $this->assertSame($future, $reader->applyToProjection(['sections' => []], $future)['_meta']['personalization']);
        }
    }
}
