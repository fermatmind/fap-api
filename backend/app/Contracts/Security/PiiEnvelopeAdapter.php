<?php

declare(strict_types=1);

namespace App\Contracts\Security;

interface PiiEnvelopeAdapter
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): ?string;
}
