<?php

declare(strict_types=1);

namespace App\Support;

final class SensitiveDataRedactor
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'token',
        'secret',
        'password',
        'authorization',
        'cookie',
        'stripe-signature',
        'client_secret',
        'api_key',
        'private_key',
        'signature',
    ];

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
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
}
