<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Security\PiiEnvelopeAdapter;
use Illuminate\Support\Facades\Log;

final class ExternalKmsPiiEnvelopeAdapter implements PiiEnvelopeAdapter
{
    public function __construct(
        private readonly LocalPiiEnvelopeAdapter $localAdapter,
    ) {}

    public function encrypt(string $plaintext): string
    {
        return $this->runWithContract('encrypt', fn (): string => $this->localAdapter->encrypt($plaintext));
    }

    public function decrypt(string $ciphertext): ?string
    {
        return $this->runWithContract('decrypt', fn (): ?string => $this->localAdapter->decrypt($ciphertext));
    }

    private function runWithContract(string $operation, callable $localOp): mixed
    {
        try {
            $this->simulateExternalDependency($operation);

            return $localOp();
        } catch (ExternalKmsContractException $e) {
            if ($this->allowLocalFallback()) {
                Log::warning('external_kms_fallback_to_local', [
                    'operation' => $operation,
                    'error_code' => $e->errorCode(),
                ]);

                return $localOp();
            }

            throw $e;
        }
    }

    private function simulateExternalDependency(string $operation): void
    {
        $simulate = strtolower(trim((string) config('services.pii.external_kms.simulate', 'none')));
        $timeoutMs = max(1, (int) config('services.pii.external_kms.timeout_ms', 800));

        if ($simulate === 'timeout') {
            throw new ExternalKmsContractException(
                'EXTERNAL_KMS_TIMEOUT',
                "external kms {$operation} timeout after {$timeoutMs}ms"
            );
        }

        if ($simulate === 'failure') {
            throw new ExternalKmsContractException(
                'EXTERNAL_KMS_FAILED',
                "external kms {$operation} dependency failure"
            );
        }
    }

    private function allowLocalFallback(): bool
    {
        return (bool) config('services.pii.external_kms.dry_run', false)
            || (bool) config('services.pii.external_kms.fallback_to_local', false);
    }
}
