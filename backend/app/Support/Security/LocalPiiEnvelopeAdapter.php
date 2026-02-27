<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Security\PiiEnvelopeAdapter;
use Illuminate\Support\Facades\Crypt;

final class LocalPiiEnvelopeAdapter implements PiiEnvelopeAdapter
{
    public function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): ?string
    {
        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }
}
