<?php

declare(strict_types=1);

namespace App\DTO\Attempts;

final class SubmitAttemptDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $answers
     */
    public function __construct(
        public readonly array $answers,
        public readonly int $durationMs,
        public readonly string $inviteToken,
        public readonly ?string $userId,
        public readonly ?string $anonId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $answers = $payload['answers'] ?? [];
        if (! is_array($answers)) {
            $answers = [];
        }

        $normalizedAnswers = [];
        foreach ($answers as $answer) {
            if (is_array($answer)) {
                $normalizedAnswers[] = $answer;
            }
        }

        return new self(
            answers: $normalizedAnswers,
            durationMs: max(0, (int) ($payload['duration_ms'] ?? 0)),
            inviteToken: trim((string) ($payload['invite_token'] ?? '')),
            userId: self::normalizeUserId($payload['user_id'] ?? null),
            anonId: self::nullableString($payload['anon_id'] ?? null),
        );
    }

    private static function normalizeUserId(mixed $value): ?string
    {
        if (is_int($value) || is_string($value) || is_float($value)) {
            $normalized = trim((string) $value);
            if ($normalized !== '' && preg_match('/^\d+$/', $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $normalized = trim((string) $value);

            return $normalized === '' ? null : $normalized;
        }

        return null;
    }
}
