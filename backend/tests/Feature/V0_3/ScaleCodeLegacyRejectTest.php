<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ScaleCodeLegacyRejectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{legacy:string,v2:string}>
     */
    private function sixScalePairs(): array
    {
        return [
            ['legacy' => 'MBTI', 'v2' => 'MBTI_PERSONALITY_TEST_16_TYPES'],
            ['legacy' => 'BIG5_OCEAN', 'v2' => 'BIG_FIVE_OCEAN_MODEL'],
            ['legacy' => 'CLINICAL_COMBO_68', 'v2' => 'CLINICAL_DEPRESSION_ANXIETY_PRO'],
            ['legacy' => 'SDS_20', 'v2' => 'DEPRESSION_SCREENING_STANDARD'],
            ['legacy' => 'IQ_RAVEN', 'v2' => 'IQ_INTELLIGENCE_QUOTIENT'],
            ['legacy' => 'EQ_60', 'v2' => 'EQ_EMOTIONAL_INTELLIGENCE'],
        ];
    }

    public function test_questions_rejects_legacy_codes_and_accepts_v2_codes_for_six_scales(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.accept_legacy_scale_code', false);

        foreach ($this->sixScalePairs() as $pair) {
            $legacy = (string) $pair['legacy'];
            $v2 = (string) $pair['v2'];

            $legacyResponse = $this->getJson("/api/v0.3/scales/{$legacy}/questions?region=CN_MAINLAND&locale=zh-CN");
            $legacyResponse->assertStatus(410);
            $legacyResponse->assertJsonPath('error_code', 'SCALE_CODE_LEGACY_NOT_ACCEPTED');
            $legacyResponse->assertJsonPath('details.requested_scale_code', $legacy);
            $legacyResponse->assertJsonPath('details.scale_code_legacy', $legacy);
            $legacyResponse->assertJsonPath('details.replacement_scale_code_v2', $v2);

            $v2Response = $this->getJson("/api/v0.3/scales/{$v2}/questions?region=CN_MAINLAND&locale=zh-CN");
            $v2Response->assertStatus(200);
            $v2Response->assertJsonPath('ok', true);
        }
    }

    public function test_attempt_start_rejects_legacy_scale_code_and_accepts_v2_scale_code(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.accept_legacy_scale_code', false);

        $legacyResponse = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => 'legacy_reject_attempt_start',
        ]);
        $legacyResponse->assertStatus(410);
        $legacyResponse->assertJsonPath('error_code', 'SCALE_CODE_LEGACY_NOT_ACCEPTED');
        $legacyResponse->assertJsonPath('details.requested_scale_code', 'MBTI');
        $legacyResponse->assertJsonPath('details.scale_code_legacy', 'MBTI');
        $legacyResponse->assertJsonPath('details.replacement_scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');

        $v2Response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'anon_id' => 'v2_accept_attempt_start',
        ]);
        $v2Response->assertStatus(200);
        $v2Response->assertJsonPath('ok', true);
        $v2Response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
    }

    public function test_questions_reject_demo_scales_when_demo_is_disabled(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.allow_demo_scales', false);

        $cases = [
            ['code' => 'DEMO_ANSWERS', 'replacement_v2' => 'IQ_INTELLIGENCE_QUOTIENT'],
            ['code' => 'SIMPLE_SCORE_DEMO', 'replacement_v2' => 'DEPRESSION_SCREENING_STANDARD'],
        ];

        foreach ($cases as $case) {
            $code = (string) $case['code'];
            $replacementV2 = (string) $case['replacement_v2'];

            $response = $this->getJson("/api/v0.3/scales/{$code}/questions?region=CN_MAINLAND&locale=zh-CN");
            $response->assertStatus(410);
            $response->assertJsonPath('error_code', 'SCALE_DEPRECATED');
            $response->assertJsonPath('details.requested_scale_code', $code);
            $response->assertJsonPath('details.scale_code_legacy', $code);
            $response->assertJsonPath('details.replacement_scale_code_v2', $replacementV2);
        }
    }
}
