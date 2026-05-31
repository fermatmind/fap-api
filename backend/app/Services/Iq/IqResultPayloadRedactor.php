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
        'correct_answer',
        'correctAnswer',
        'solution_rule',
        'solutionRule',
        'distractor_logic',
        'distractorLogic',
        'asset_hashes',
        'assetHashes',
        'generator_metadata',
        'generatorMetadata',
    ];

    public static function isIqScale(?string $scaleCode, ?string $scaleCodeV2 = null): bool
    {
        return in_array(strtoupper(trim((string) $scaleCode)), self::IQ_SCALE_CODES, true)
            || in_array(strtoupper(trim((string) $scaleCodeV2)), self::IQ_SCALE_CODES, true);
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
