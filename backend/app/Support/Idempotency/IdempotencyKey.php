<?php

namespace App\Support\Idempotency;

use Carbon\Carbon;

class IdempotencyKey
{
    /**
     * Build a deterministic idempotency descriptor.
     *
     * Returns:
     *  - provider
     *  - external_id
     *  - recorded_at (normalized)
     *  - hash (sha256 of canonical payload)
     *  - key (composite string)
     */
    public static function build(string $provider, string $externalId, $recordedAt, $payload): array
    {
        $provider = trim($provider);
        $externalId = trim($externalId);
        $recordedAtStr = self::normalizeRecordedAt($recordedAt);
        $hash = self::hashPayload($payload);
        $key = self::composeKey($provider, $externalId, $recordedAtStr, $hash);

        return [
            'provider' => $provider,
            'external_id' => $externalId,
            'recorded_at' => $recordedAtStr,
            'hash' => $hash,
            'key' => $key,
        ];
    }

    public static function composeKey(string $provider, string $externalId, string $recordedAt, string $hash): string
    {
        return implode('|', [$provider, $externalId, $recordedAt, $hash]);
    }

    public static function hashPayload($payload): string
    {
        $canonical = self::canonicalJson($payload);
        return hash('sha256', $canonical);
    }

    public static function canonicalJson($payload): string
    {
        $normalized = self::normalize($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }
        return $json;
    }

    public static function normalizeRecordedAt($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateTimeString();
        }

        $s = trim((string) $value);
        if ($s === '') {
            return Carbon::now()->toDateTimeString();
        }

        try {
            return Carbon::parse($s)->toDateTimeString();
        } catch (\Throwable $e) {
            return $s;
        }
    }

    private static function normalize($value)
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                ksort($value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = self::normalize($v);
            }
            return $value;
        }

        if (is_object($value)) {
            $arr = (array) $value;
            ksort($arr);
            foreach ($arr as $k => $v) {
                $arr[$k] = self::normalize($v);
            }
            return $arr;
        }

        return $value;
    }
}
