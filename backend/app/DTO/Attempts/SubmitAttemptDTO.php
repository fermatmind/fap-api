<?php

declare(strict_types=1);

namespace App\DTO\Attempts;

final class SubmitAttemptDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @param  array<int, array{item_id:string,code:int}>  $validityItems
     */
    public function __construct(
        public readonly array $answers,
        public readonly array $validityItems,
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

        $validityItems = $payload['validity_items'] ?? [];
        if (!is_array($validityItems)) {
            $validityItems = [];
        }

        $normalizedValidityItems = [];
        foreach ($validityItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = trim((string) ($item['item_id'] ?? ''));
            if ($itemId === '') {
                continue;
            }
            $code = (int) ($item['code'] ?? 0);
            if ($code < 1 || $code > 5) {
                continue;
            }
            $normalizedValidityItems[] = [
                'item_id' => $itemId,
                'code' => $code,
            ];
        }

        return new self(
            answers: $normalizedAnswers,
            validityItems: $normalizedValidityItems,
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
