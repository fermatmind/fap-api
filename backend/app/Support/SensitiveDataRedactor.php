<?php

declare(strict_types=1);

namespace App\Support;

final class SensitiveDataRedactor
{
    private const REDACTED = '[REDACTED]';
    private const REDACTED_PSYCH = '[REDACTED_PSYCH]';
    private const REDACTION_VERSION = 'v2';

    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'token',
        'secret',
        'password',
        'credit_card',
        'authorization',
        'cookie',
        'stripe-signature',
        'client_secret',
        'api_key',
        'private_key',
        'signature',
    ];

    /** @var list<string> */
    private const PSYCH_PRIVACY_KEY_PARTS = [
        'answer',
        'answers',
        'psychometric',
        'psychometrics',
        'report_',
        'report_json',
        'report',
    ];

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        return $this->redactWithMeta($data)['data'];
    }

    /**
     * @param array<mixed> $data
     * @return array{data: array<mixed>, count: int, version: string}
     */
    public function redactWithMeta(array $data): array
    {
        $count = 0;
        $redacted = $this->redactRecursive($data, $count);

        return [
            'data' => $redacted,
            'count' => $count,
            'version' => self::REDACTION_VERSION,
        ];
    }

    private function isSensitiveKey(int|string $key): bool
    {
        $lowerKey = strtolower((string) $key);

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if ($part !== '' && str_contains($lowerKey, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redactRecursive(array $data, int &$count): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;
                $count++;
                continue;
            }

            if ($this->isPsychPrivacyKey($key)) {
                $redacted[$key] = $this->redactPsychValue($value, $count);
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactRecursive($value, $count);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function isPsychPrivacyKey(int|string $key): bool
    {
        $lowerKey = strtolower((string) $key);

        foreach (self::PSYCH_PRIVACY_KEY_PARTS as $part) {
            if ($part !== '' && str_contains($lowerKey, $part)) {
                return true;
            }
        }

        return false;
    }

    private function redactPsychValue(mixed $value, int &$count): mixed
    {
        if (is_array($value)) {
            $entryCount = $this->countArrayLeaves($value);
            $count += $entryCount;

            return [
                '__redacted__' => true,
                'reason' => 'psych_privacy',
                'count' => $entryCount,
            ];
        }

        $count++;

        return self::REDACTED_PSYCH;
    }

    /**
     * @param array<mixed> $value
     */
    private function countArrayLeaves(array $value): int
    {
        $count = 0;

        foreach ($value as $item) {
            if (is_array($item)) {
                $count += $this->countArrayLeaves($item);
                continue;
            }

            $count++;
        }

        return max(1, $count);
    }
}
