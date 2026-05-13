<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecQualityRuleContract
{
    public const VERSION = 'riasec_quality_rule_spec_v2';

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function build(array $payload): array
    {
        $formCode = $this->formCode($payload);
        $flags = $this->flags($payload);
        $missingItems = $this->missingItems($payload, $formCode);
        $attentionFailCount = count(array_intersect($flags, ['attention_133_failed', 'attention_137_failed']));
        $attentionFlag = $attentionFailCount > 0;
        $inconsistencySignal = in_array('low_consistency', $flags, true);
        $idealizationSignal = in_array('idealization', $flags, true) || in_array('strong_idealization', $flags, true);
        $broadAgreementSignal = in_array('broad_agreement', $flags, true);
        $tooFast = $this->boolSignal($payload, 'too_fast');
        $neutralOveruse = $this->boolSignal($payload, 'neutral_overuse');

        $qualityState = $this->qualityState(
            formCode: $formCode,
            flags: $flags,
            missingItems: $missingItems,
            attentionFailCount: $attentionFailCount,
            tooFast: $tooFast,
            neutralOveruse: $neutralOveruse,
        );
        $readingStrength = $this->readingStrength($qualityState);

        return [
            'quality_rule_version' => self::VERSION,
            'quality_state' => $qualityState,
            'response_quality' => $qualityState,
            'reading_strength' => $readingStrength,
            'quality_flags' => $flags,
            'too_fast' => $tooFast,
            'neutral_overuse' => $neutralOveruse,
            'missing_items' => $missingItems,
            'inconsistency_signal' => $inconsistencySignal,
            'attention_flag' => $attentionFlag,
            'idealization_signal' => $idealizationSignal,
            'broad_agreement_signal' => $broadAgreementSignal,
            'quality_boundary_note' => $this->boundaryNote($formCode, $qualityState),
            'result_page_behavior' => $this->resultPageBehavior($qualityState),
            'module_policy' => [
                'hide_strong_modules' => $qualityState === 'low_quality',
                'show_activity_chain' => $qualityState !== 'low_quality',
                'show_occupation_examples' => $qualityState === 'normal',
                'allow_140q_cta' => $qualityState !== 'low_quality',
                'cta_strength' => match ($qualityState) {
                    'normal' => 'standard',
                    'caution' => 'soft_or_hidden',
                    default => 'hidden',
                },
            ],
            'score_mutation_allowed' => false,
            'measured_holland_code_mutation_allowed' => false,
            'field_authority' => [
                'quality_state' => 'backend_owned',
                'response_quality' => 'backend_owned',
                'reading_strength' => 'backend_owned',
                'quality_rule_version' => 'backend_owned',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function formCode(array $payload): string
    {
        $candidate = strtolower(trim((string) (
            $payload['form_code']
            ?? ($payload['measurement_contract_v1']['form']['form_code'] ?? null)
            ?? ''
        )));
        if (in_array($candidate, ['riasec_140', '140', 'enhanced', 'v1-enhanced-140'], true)) {
            return 'riasec_140';
        }
        if (in_array($candidate, ['riasec_60', '60', 'standard', 'v1-standard-60'], true)) {
            return 'riasec_60';
        }

        return ((int) ($payload['answer_count'] ?? 0)) >= 140 ? 'riasec_140' : 'riasec_60';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function flags(array $payload): array
    {
        $flags = $payload['quality_flags'] ?? ($payload['quality']['flags'] ?? []);
        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $flag): string => strtolower(trim((string) $flag)),
            $flags
        ))));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function missingItems(array $payload, string $formCode): bool
    {
        if ($this->boolSignal($payload, 'missing_items')) {
            return true;
        }

        $answerCount = (int) ($payload['answer_count'] ?? 0);
        if ($answerCount <= 0) {
            return false;
        }

        return $answerCount < ($formCode === 'riasec_140' ? 140 : 60);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function boolSignal(array $payload, string $key): bool
    {
        return filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  list<string>  $flags
     */
    private function qualityState(
        string $formCode,
        array $flags,
        bool $missingItems,
        int $attentionFailCount,
        bool $tooFast,
        bool $neutralOveruse,
    ): string {
        if ($missingItems) {
            return 'low_quality';
        }

        if ($formCode === 'riasec_140' && $attentionFailCount >= 2) {
            return 'low_quality';
        }

        if ($flags !== [] || $tooFast || $neutralOveruse) {
            return 'caution';
        }

        return 'normal';
    }

    private function readingStrength(string $qualityState): string
    {
        return match ($qualityState) {
            'low_quality' => 'retake_recommended',
            'caution' => 'cautious_reading',
            default => 'normal_reading',
        };
    }

    private function resultPageBehavior(string $qualityState): string
    {
        return match ($qualityState) {
            'low_quality' => 'show_retake_recommended_page',
            'caution' => 'show_cautious_result_page',
            default => 'show_standard_result_page',
        };
    }

    private function boundaryNote(string $formCode, string $qualityState): string
    {
        if ($formCode === 'riasec_60') {
            return $qualityState === 'low_quality'
                ? '60Q low_quality is limited to incomplete or missing required response evidence.'
                : '60Q quality is minimal and must not overclaim strong low_quality without approved signals.';
        }

        return '140Q quality uses existing attention, consistency and response-pattern flags as contextual readability evidence.';
    }
}
