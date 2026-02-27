<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

final class PiiCipher
{
    private const PLACEHOLDER_DOMAIN = 'privacy.local';

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

        return Crypt::encryptString($normalized);
    }

    public function decrypt(?string $ciphertext): ?string
    {
        $ciphertext = trim((string) $ciphertext);
        if ($ciphertext === '') {
            return null;
        }

        try {
            $plaintext = Crypt::decryptString($ciphertext);
        } catch (\Throwable) {
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
}
