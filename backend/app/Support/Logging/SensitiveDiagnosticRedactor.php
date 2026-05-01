<?php

declare(strict_types=1);

namespace App\Support\Logging;

final class SensitiveDiagnosticRedactor
{
    public const REDACTED = '[REDACTED]';

    /** @var array<string, true> */
    private const SENSITIVE_EXACT_KEYS = [
        'anon_id' => true,
        'api_key' => true,
        'authorization' => true,
        'attempt_id' => true,
        'actor_anon_id' => true,
        'client_anon_id' => true,
        'client_secret' => true,
        'compare_invite_id' => true,
        'cookie' => true,
        'credit_card' => true,
        'email' => true,
        'fm_anon_id' => true,
        'id_card' => true,
        'id_number' => true,
        'invite_code' => true,
        'invite_id' => true,
        'invite_token' => true,
        'invite_unlock_code' => true,
        'candidate_invitee_anon_id' => true,
        'candidate_invitee_attempt_id' => true,
        'invitee_anon_id' => true,
        'invitee_attempt_id' => true,
        'password' => true,
        'password_confirmation' => true,
        'phone' => true,
        'private_key' => true,
        'signature' => true,
        'target_attempt_id' => true,
        'token' => true,
        'unlock_code' => true,
        'webhook_secret' => true,
    ];

    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'client_secret',
        'credit_card',
        'email',
        'id_card',
        'id_number',
        'password',
        'phone',
        'private_key',
        'secret',
        'signature',
        'token',
        'webhook_secret',
    ];

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $result[$key] = self::redactArray($value);

                continue;
            }

            $result[$key] = is_string($value) ? self::redactString($value) : $value;
        }

        return $result;
    }

    public static function redactString(string $value): string
    {
        $value = preg_replace(
            '/\b((?:webhook_)?secret|client_secret|api_key|token|invite_token|invite_unlock_code|invite_code|anon_id|attempt_id|target_attempt_id|signature)=([^&\s"\']+)/i',
            '$1='.self::REDACTED,
            $value
        ) ?? $value;

        $value = preg_replace(
            '/("(?:(?:webhook_)?secret|client_secret|api_key|token|invite_token|invite_unlock_code|invite_code|anon_id|attempt_id|target_attempt_id|signature)"\s*:\s*")([^"]*)(")/i',
            '$1'.self::REDACTED.'$3',
            $value
        ) ?? $value;

        return preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer '.self::REDACTED, $value) ?? $value;
    }

    public static function fingerprint(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return 'sha256:'.substr(hash('sha256', $normalized), 0, 16);
    }

    public static function isSensitiveKey(int|string $key): bool
    {
        $normalized = strtolower((string) $key);

        if (isset(self::SENSITIVE_EXACT_KEYS[$normalized])) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if ($part !== '' && str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }
}
