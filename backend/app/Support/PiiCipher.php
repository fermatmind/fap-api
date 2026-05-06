<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Security\PiiEnvelopeAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

final class PiiCipher
{
    private const PLACEHOLDER_DOMAIN = 'privacy.local';

    private const DEFAULT_KEY_ID = 'local-app-key';

    private const DEFAULT_ALGO = 'laravel-crypt-v1';

    public function __construct(
        private readonly PiiEnvelopeAdapter $envelopeAdapter,
    ) {}

    public function currentKeyVersion(): int
    {
        $version = (int) config('services.pii.key_version', 1);

        return $version > 0 ? $version : 1;
    }

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function normalizePhone(string $phone): string
    {
        return trim($phone);
    }

    public function emailHash(string $email): string
    {
        return $this->hash($this->normalizeEmail($email), 'email');
    }

    public function phoneHash(string $phone): string
    {
        return $this->hash($this->normalizePhone($phone), 'phone');
    }

    public function encrypt(?string $value): ?string
    {
        return $this->encryptWithKeyVersion($value, $this->currentKeyVersion());
    }

    public function encryptWithKeyVersion(?string $value, int $version): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $resolvedVersion = $version > 0 ? $version : $this->currentKeyVersion();
        $keyId = $this->keyIdForVersion($resolvedVersion);
        $envelope = [
            'ciphertext' => $this->envelopeAdapter->encrypt($normalized, $resolvedVersion, $keyId),
            'key_id' => $keyId,
            'key_version' => $resolvedVersion,
            'algo' => $this->algoForVersion($resolvedVersion),
        ];

        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || trim($encoded) === '') {
            return (string) $envelope['ciphertext'];
        }

        return $encoded;
    }

    public function activateKeyVersion(int $version): void
    {
        if ($version <= 0) {
            return;
        }

        config(['services.pii.key_version' => $version]);
        try {
            Cache::forever($this->activeKeyVersionCacheKey(), $version);
        } catch (\Throwable $cacheError) {
            // Cache persistence is auxiliary; runtime config is the source of truth.
            report($cacheError);
        }
    }

    public function decrypt(?string $ciphertext): ?string
    {
        $ciphertext = trim((string) $ciphertext);
        if ($ciphertext === '') {
            return null;
        }

        $envelope = $this->decodeEnvelope($ciphertext);
        if ($envelope !== null) {
            $plaintext = $this->envelopeAdapter->decrypt(
                (string) $envelope['ciphertext'],
                (int) $envelope['key_version'],
                (string) $envelope['key_id']
            );
            if ($plaintext === null) {
                $plaintext = $this->decryptLegacyCiphertext((string) $envelope['ciphertext']);
            }
        } else {
            $plaintext = $this->envelopeAdapter->decrypt($ciphertext);
            if ($plaintext === null) {
                $plaintext = $this->decryptLegacyCiphertext($ciphertext);
            }
        }

        if ($plaintext === null) {
            return null;
        }

        $plaintext = trim($plaintext);

        return $plaintext === '' ? null : $plaintext;
    }

    /**
     * @return array{ciphertext:string,key_id:string,key_version:int,algo:string}|null
     */
    public function envelopeMetadata(?string $ciphertext): ?array
    {
        return $this->decodeEnvelope(trim((string) $ciphertext));
    }

    public function legacyEmailPlaceholder(string $emailHash): string
    {
        $prefix = substr(strtolower(trim($emailHash)), 0, 20);
        if ($prefix === '') {
            $prefix = 'unknown';
        }

        return 'redacted+'.$prefix.'@'.self::PLACEHOLDER_DOMAIN;
    }

    private function hash(string $value, string $namespace): string
    {
        $salt = (string) config('app.key', 'fap-key');

        return hash_hmac('sha256', $namespace.'|'.$value, $salt);
    }

    private function keyIdForVersion(int $version): string
    {
        $keyId = trim((string) $this->configuredVersionValue('key_ids', $version));
        if ($keyId === '') {
            $keyId = trim((string) config('services.pii.key_id', self::DEFAULT_KEY_ID));
        }

        return $keyId !== '' ? $keyId : self::DEFAULT_KEY_ID;
    }

    private function algoForVersion(int $version): string
    {
        $algo = trim((string) $this->configuredVersionValue('algos', $version));
        if ($algo === '') {
            $algo = trim((string) config('services.pii.algo', self::DEFAULT_ALGO));
        }

        return $algo !== '' ? $algo : self::DEFAULT_ALGO;
    }

    private function configuredVersionValue(string $mapKey, int $version): mixed
    {
        $map = config('services.pii.'.$mapKey, []);
        if (is_string($map)) {
            $decoded = json_decode($map, true);
            $map = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($map)) {
            return null;
        }

        return $map[(string) $version] ?? $map[$version] ?? null;
    }

    private function activeKeyVersionCacheKey(): string
    {
        $key = trim((string) config('services.pii.active_key_version_cache_key', 'pii:active_key_version'));

        return $key !== '' ? $key : 'pii:active_key_version';
    }

    /**
     * @return array{ciphertext:string,key_id:string,key_version:int,algo:string}|null
     */
    private function decodeEnvelope(string $value): ?array
    {
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return null;
        }

        $ciphertext = trim((string) ($decoded['ciphertext'] ?? ''));
        $keyId = trim((string) ($decoded['key_id'] ?? ''));
        $algo = trim((string) ($decoded['algo'] ?? ''));
        $keyVersion = (int) ($decoded['key_version'] ?? 0);

        if ($ciphertext === '' || $keyId === '' || $algo === '' || $keyVersion <= 0) {
            return null;
        }

        return [
            'ciphertext' => $ciphertext,
            'key_id' => $keyId,
            'key_version' => $keyVersion,
            'algo' => $algo,
        ];
    }

    private function decryptLegacyCiphertext(string $ciphertext): ?string
    {
        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }
}
