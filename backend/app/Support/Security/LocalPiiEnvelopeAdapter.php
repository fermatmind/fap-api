<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Security\PiiEnvelopeAdapter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class LocalPiiEnvelopeAdapter implements PiiEnvelopeAdapter
{
    public function encrypt(string $plaintext, ?int $keyVersion = null, ?string $keyId = null): string
    {
        $encrypter = $this->encrypterFor($keyVersion, $keyId);
        if ($encrypter instanceof Encrypter) {
            return $encrypter->encryptString($plaintext);
        }

        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $ciphertext, ?int $keyVersion = null, ?string $keyId = null): ?string
    {
        $encrypter = $this->encrypterFor($keyVersion, $keyId);
        if ($encrypter instanceof Encrypter) {
            try {
                return $encrypter->decryptString($ciphertext);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }

    private function encrypterFor(?int $keyVersion, ?string $keyId): ?Encrypter
    {
        $key = $this->configuredKey($keyVersion, $keyId);
        if ($key === null) {
            return null;
        }

        try {
            return new Encrypter($key, (string) config('app.cipher', 'AES-256-CBC'));
        } catch (\Throwable $exception) {
            throw new RuntimeException('Invalid configured PII local envelope key.', previous: $exception);
        }
    }

    private function configuredKey(?int $keyVersion, ?string $keyId): ?string
    {
        $keys = config('services.pii.local_keys', []);
        if (is_string($keys)) {
            $decoded = json_decode($keys, true);
            $keys = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($keys) || $keys === []) {
            return null;
        }

        $candidates = [];
        if ($keyVersion !== null && $keyVersion > 0) {
            $candidates[] = (string) $keyVersion;
        }
        $keyId = trim((string) $keyId);
        if ($keyId !== '') {
            $candidates[] = $keyId;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $value = $keys[$candidate] ?? null;
            if (is_array($value)) {
                $value = $value['key'] ?? null;
            }
            $normalized = $this->normalizeKeyMaterial($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeKeyMaterial(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            return is_string($decoded) && $decoded !== '' ? $decoded : null;
        }

        return $raw;
    }
}
