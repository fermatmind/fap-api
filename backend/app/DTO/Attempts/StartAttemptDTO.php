<?php

declare(strict_types=1);

namespace App\DTO\Attempts;

final class StartAttemptDTO
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public readonly string $scaleCode,
        public readonly ?string $region,
        public readonly ?string $locale,
        public readonly ?string $anonId,
        public readonly ?string $clientPlatform,
        public readonly ?string $clientVersion,
        public readonly ?string $channel,
        public readonly ?string $referrer,
        public readonly ?array $meta,
        public readonly ?bool $consentAccepted,
        public readonly ?string $consentVersion,
        public readonly ?string $consentLocale,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $meta = $payload['meta'] ?? null;
        if (! is_array($meta)) {
            $meta = null;
        }

        $consent = $payload['consent'] ?? null;
        if (!is_array($consent)) {
            $consent = [];
        }

        return new self(
            scaleCode: strtoupper(trim((string) ($payload['scale_code'] ?? ''))),
            region: self::nullableString($payload['region'] ?? null),
            locale: self::nullableString($payload['locale'] ?? null),
            anonId: self::nullableString($payload['anon_id'] ?? null),
            clientPlatform: self::nullableString($payload['client_platform'] ?? null),
            clientVersion: self::nullableString($payload['client_version'] ?? null),
            channel: self::nullableString($payload['channel'] ?? null),
            referrer: self::nullableString($payload['referrer'] ?? null),
            meta: $meta,
            consentAccepted: array_key_exists('accepted', $consent) ? (bool) $consent['accepted'] : null,
            consentVersion: self::nullableString($consent['version'] ?? null),
            consentLocale: self::nullableString($consent['locale'] ?? null),
        );
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
