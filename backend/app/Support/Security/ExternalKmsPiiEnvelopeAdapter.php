<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Security\PiiEnvelopeAdapter;

/**
 * External KMS adapter shell.
 *
 * This implementation keeps the same ciphertext semantics as local encryption
 * so we can switch adapters without changing envelope schema/contracts.
 */
final class ExternalKmsPiiEnvelopeAdapter implements PiiEnvelopeAdapter
{
    public function __construct(
        private readonly LocalPiiEnvelopeAdapter $localAdapter,
    ) {}

    public function encrypt(string $plaintext): string
    {
        return $this->localAdapter->encrypt($plaintext);
    }

    public function decrypt(string $ciphertext): ?string
    {
        return $this->localAdapter->decrypt($ciphertext);
    }
}

