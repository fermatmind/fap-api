<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Security\PiiEnvelopeAdapter;
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
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $envelope = [
            'ciphertext' => $this->envelopeAdapter->encrypt($normalized),
            'key_id' => $this->currentKeyId(),
            'key_version' => $this->currentKeyVersion(),
            'algo' => $this->currentAlgo(),
        ];

        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || trim($encoded) === '') {
            return (string) $envelope['ciphertext'];
        }

        return $encoded;
    }

    public function decrypt(?string $ciphertext): ?string
    {
        $ciphertext = trim((string) $ciphertext);
        if ($ciphertext === '') {
            return null;
        }

        $envelope = $this->decodeEnvelope($ciphertext);
        if ($envelope !== null) {
            $plaintext = $this->envelopeAdapter->decrypt((string) $envelope['ciphertext']);
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

    private function currentKeyId(): string
    {
        $keyId = trim((string) config('services.pii.key_id', self::DEFAULT_KEY_ID));

        return $keyId !== '' ? $keyId : self::DEFAULT_KEY_ID;
    }

    private function currentAlgo(): string
    {
        $algo = trim((string) config('services.pii.algo', self::DEFAULT_ALGO));

        return $algo !== '' ? $algo : self::DEFAULT_ALGO;
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
