<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class CareerRolloutReportAuthoritySigner
{
    public const SCHEMA_VERSION = 'career_rollout_report_authority.v1';

    public const SOURCE = 'career:execute-canonical-rollout-batch';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sign(
        array $payload,
        ?DateTimeImmutable $signedAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): array {
        $signedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt ??= $signedAt->modify('+14 days');

        $authority = [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => self::SOURCE,
            'batch_id' => $this->stringValue($payload['batch_id'] ?? null),
            'status' => $this->stringValue($payload['status'] ?? null),
            'dry_run' => (bool) ($payload['dry_run'] ?? true),
            'writes_database' => (bool) ($payload['writes_database'] ?? false),
            'write_verified' => (bool) ($payload['write_verified'] ?? false),
            'promoted_slugs' => $this->slugList($payload['promoted_slugs'] ?? []),
            'promoted_locale_rows' => (int) ($payload['promoted_locale_rows'] ?? 0),
            'signed_at' => $signedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'expires_at' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
        $authority['promoted_slug_count'] = count($authority['promoted_slugs']);
        $authority['signature'] = $this->signature($authority);

        return $authority;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isTrusted(array $payload): bool
    {
        $authority = $payload['authority'] ?? null;
        if (! is_array($authority) || array_is_list($authority)) {
            return false;
        }

        $signature = $this->stringValue($authority['signature'] ?? null);
        if ($signature === '') {
            return false;
        }

        try {
            $expectedSignature = $this->signature($authority);
        } catch (Throwable) {
            return false;
        }

        if (! hash_equals($expectedSignature, $signature)) {
            return false;
        }

        if (($authority['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return false;
        }

        if (($authority['source'] ?? null) !== self::SOURCE) {
            return false;
        }

        if (($authority['batch_id'] ?? null) !== $this->stringValue($payload['batch_id'] ?? null)) {
            return false;
        }

        if (($authority['status'] ?? null) !== 'promoted_success' || ($payload['status'] ?? null) !== 'promoted_success') {
            return false;
        }

        if (($authority['dry_run'] ?? null) !== false || ($payload['dry_run'] ?? null) !== false) {
            return false;
        }

        if (($authority['writes_database'] ?? null) !== true || ($payload['writes_database'] ?? null) !== true) {
            return false;
        }

        if (($authority['write_verified'] ?? null) !== true || ($payload['write_verified'] ?? null) !== true) {
            return false;
        }

        $authoritySlugs = $this->slugList($authority['promoted_slugs'] ?? []);
        $payloadSlugs = $this->slugList($payload['promoted_slugs'] ?? []);
        if ($authoritySlugs !== $payloadSlugs || (int) ($authority['promoted_slug_count'] ?? -1) !== count($payloadSlugs)) {
            return false;
        }

        if ((int) ($authority['promoted_locale_rows'] ?? -1) !== (int) ($payload['promoted_locale_rows'] ?? 0)) {
            return false;
        }

        try {
            $signedAt = new DateTimeImmutable($this->stringValue($authority['signed_at'] ?? null));
            $expiresAt = new DateTimeImmutable($this->stringValue($authority['expires_at'] ?? null));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } catch (Throwable) {
            return false;
        }

        if ($signedAt > $now->modify('+5 minutes')) {
            return false;
        }

        return $expiresAt >= $now;
    }

    /**
     * @param  array<string, mixed>  $authority
     */
    private function signature(array $authority): string
    {
        $secret = $this->secret();
        if ($secret === null) {
            throw new RuntimeException('career_rollout_report_authority_secret_missing');
        }

        unset($authority['signature']);
        ksort($authority);
        $encoded = json_encode($authority, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'sha256='.hash_hmac('sha256', $encoded, $secret);
    }

    private function secret(): ?string
    {
        $key = trim((string) config('app.key', ''));
        if ($key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && $decoded !== '' ? $decoded : null;
        }

        return $key;
    }

    /**
     * @return list<string>
     */
    private function slugList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $slugs = [];
        foreach ($value as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $normalized = strtolower(trim($slug));
            if ($normalized !== '') {
                $slugs[$normalized] = true;
            }
        }

        $result = array_keys($slugs);
        sort($result);

        return $result;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
