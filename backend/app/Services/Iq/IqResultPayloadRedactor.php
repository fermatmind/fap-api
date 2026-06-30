<?php

declare(strict_types=1);

namespace App\Services\Iq;

final class IqResultPayloadRedactor
{
    private const IQ_SCALE_CODES = [
        'IQ_RAVEN',
        'IQ_INTELLIGENCE_QUOTIENT',
    ];

    private const ANSWER_KEY_FIELDS = [
        'answer_key',
        'answerKey',
        'answer_key_version',
        'answerKeyVersion',
        'answer_key_status',
        'answerKeyStatus',
        'correct_answer',
        'correctAnswer',
        'correct_answers',
        'correctAnswers',
        'correct_option',
        'correctOption',
        'correct_options',
        'correctOptions',
        'solution_rule',
        'solutionRule',
        'solution_rules',
        'solutionRules',
        'solution_steps',
        'solutionSteps',
        'distractor_logic',
        'distractorLogic',
        'asset_hashes',
        'assetHashes',
        'generator_metadata',
        'generatorMetadata',
        'scoring_spec',
        'scoringSpec',
        'scoring_spec_json',
        'scoringSpecJson',
        'item_bank',
        'itemBank',
        'question_bank',
        'questionBank',
        'test_bank',
        'testBank',
        'private_payload',
        'privatePayload',
    ];

    public static function isIqScale(?string $scaleCode, ?string $scaleCodeV2 = null): bool
    {
        foreach ([$scaleCode, $scaleCodeV2] as $candidate) {
            $normalized = strtoupper(trim((string) $candidate));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, self::IQ_SCALE_CODES, true) || str_starts_with($normalized, 'IQ_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function redactAnswerKeys(array $payload): array
    {
        return self::redactNode($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private static function redactNode(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::ANSWER_KEY_FIELDS, true)) {
                continue;
            }

            $redacted[$key] = is_array($value) ? self::redactNode($value) : $value;
        }

        return $redacted;
    }
}
