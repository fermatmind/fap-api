<?php

declare(strict_types=1);

namespace App\Services\Results;

use App\Support\PiiCipher;

final class ResultEmailLookupService
{
    public function __construct(
        private readonly PiiCipher $piiCipher,
    ) {}

    /**
     * @return array{ok:bool,items:list<array<string,mixed>>,email_verification_required:bool,message:string}
     */
    public function lookup(string $email, int $orgId, ?string $locale = null): array
    {
        unset($orgId, $locale);
        $this->piiCipher->emailHash($this->piiCipher->normalizeEmail($email));

        return [
            'ok' => true,
            'items' => [],
            'email_verification_required' => true,
            'message' => 'Email ownership verification is required before saved results can be listed.',
        ];
    }
}
