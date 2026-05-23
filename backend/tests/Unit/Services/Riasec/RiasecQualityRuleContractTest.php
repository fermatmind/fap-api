<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecQualityRuleContract;
use PHPUnit\Framework\TestCase;

final class RiasecQualityRuleContractTest extends TestCase
{
    public function test_standard_60_defaults_to_normal_with_minimal_quality_boundary(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_60',
            'answer_count' => 60,
            'quality_flags' => [],
        ]);

        $this->assertSame(RiasecQualityRuleContract::VERSION, $state['quality_rule_version']);
        $this->assertSame('normal', $state['quality_state']);
        $this->assertSame('normal_reading', $state['reading_strength']);
        $this->assertSame('show_standard_result_page', $state['result_page_behavior']);
        $this->assertTrue($state['module_policy']['allow_140q_cta']);
        $this->assertStringContainsString('minimal', $state['quality_boundary_note']);
        $this->assertFalse($state['score_mutation_allowed']);
        $this->assertFalse($state['measured_holland_code_mutation_allowed']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_standard_60_supported_weak_signal_routes_to_caution_not_low_quality(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_60',
            'answer_count' => 60,
            'neutral_overuse' => true,
        ]);

        $this->assertSame('caution', $state['quality_state']);
        $this->assertSame('cautious_reading', $state['reading_strength']);
        $this->assertSame('show_cautious_result_page', $state['result_page_behavior']);
        $this->assertTrue($state['module_policy']['allow_140q_cta']);
        $this->assertSame('soft_or_hidden', $state['module_policy']['cta_strength']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_standard_60_incomplete_attempt_is_low_quality_boundary_only(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_60',
            'answer_count' => 52,
        ]);

        $this->assertSame('low_quality', $state['quality_state']);
        $this->assertSame('retake_recommended', $state['reading_strength']);
        $this->assertSame('show_retake_recommended_page', $state['result_page_behavior']);
        $this->assertFalse($state['module_policy']['allow_140q_cta']);
        $this->assertSame('hidden', $state['module_policy']['cta_strength']);
        $this->assertStringContainsString('incomplete', $state['quality_boundary_note']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_140q_single_attention_flag_routes_to_caution(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'quality_flags' => ['attention_133_failed'],
        ]);

        $this->assertSame('caution', $state['quality_state']);
        $this->assertTrue($state['attention_flag']);
        $this->assertSame('cautious_reading', $state['reading_strength']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_nested_quality_boolean_signals_are_respected(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'quality' => [
                'too_fast' => true,
            ],
        ]);

        $this->assertSame('caution', $state['quality_state']);
        $this->assertTrue($state['too_fast']);
        $this->assertSame('cautious_reading', $state['reading_strength']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_140q_two_attention_flags_route_to_low_quality_and_hide_140q_upsell(): void
    {
        $state = (new RiasecQualityRuleContract)->build([
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'quality_flags' => ['attention_133_failed', 'attention_137_failed'],
        ]);

        $this->assertSame('low_quality', $state['quality_state']);
        $this->assertTrue($state['attention_flag']);
        $this->assertSame('retake_recommended', $state['reading_strength']);
        $this->assertTrue($state['module_policy']['hide_strong_modules']);
        $this->assertFalse($state['module_policy']['allow_140q_cta']);
        $this->assertSame('hidden', $state['module_policy']['cta_strength']);
        $this->assertNoForbiddenClaims($state);
    }

    public function test_contract_does_not_mutate_payload(): void
    {
        $payload = [
            'form_code' => 'riasec_140',
            'answer_count' => 140,
            'quality_flags' => ['low_consistency', 'broad_agreement'],
        ];
        $before = $payload;

        $state = (new RiasecQualityRuleContract)->build($payload);

        $this->assertSame($before, $payload);
        $this->assertSame('caution', $state['quality_state']);
        $this->assertTrue($state['inconsistency_signal']);
        $this->assertTrue($state['broad_agreement_signal']);
        $this->assertNoForbiddenClaims($state);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoForbiddenClaims(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $forbidden = [
            'Matches',
            'career match',
            'occupation match',
            'job fit',
            'fit score',
            'success prediction',
            'best career',
            'recommended career',
            '适合度',
            '匹配度',
            '最适合',
            '职业成功',
            '岗位匹配',
            'more accurate',
            '更准确',
            'raw delta',
            'score increased',
            'score decreased',
            '140Q more accurate',
            '60Q wrong',
            'AI-generated formal report',
        ];

        foreach ($forbidden as $phrase) {
            $this->assertStringNotContainsString($phrase, $json);
        }
    }
}
