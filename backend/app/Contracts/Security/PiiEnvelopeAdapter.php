<?php

declare(strict_types=1);

namespace App\Contracts\Security;

interface PiiEnvelopeAdapter
{
    public function encrypt(string $plaintext, ?int $keyVersion = null, ?string $keyId = null): string;

    public function decrypt(string $ciphertext, ?int $keyVersion = null, ?string $keyId = null): ?string;
}
