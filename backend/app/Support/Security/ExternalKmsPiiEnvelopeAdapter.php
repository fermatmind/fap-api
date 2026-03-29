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
        $maxRetries = max(0, (int) config('services.pii.external_kms.max_retries', 2));
        $retryBackoffMs = max(0, (int) config('services.pii.external_kms.retry_backoff_ms', 50));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $this->simulateExternalDependency($operation, $attempt);
                $result = $localOp();

                if ($attempt > 1) {
                    Log::info('external_kms_retry_succeeded', [
                        'operation' => $operation,
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'fallback_used' => false,
                    ]);
                }

                return $result;
            } catch (ExternalKmsContractException $e) {
                if ($e->isRetryable() && $attempt <= $maxRetries) {
                    Log::warning('external_kms_retryable_failure', [
                        'operation' => $operation,
                        'error_code' => $e->errorCode(),
                        'category' => $e->category(),
                        'retryable' => $e->isRetryable(),
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'fallback_used' => false,
                    ]);

                    if ($retryBackoffMs > 0) {
                        usleep($retryBackoffMs * 1000);
                    }

                    continue;
                }

                if ($e->isRetryable() && $this->allowLocalFallback()) {
                    Log::warning('external_kms_fallback_to_local', [
                        'operation' => $operation,
                        'error_code' => $e->errorCode(),
                        'category' => $e->category(),
                        'retryable' => $e->isRetryable(),
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'fallback_used' => true,
                    ]);

                    return $localOp();
                }

                throw $e;
            }
        }
    }

    private function simulateExternalDependency(string $operation, int $attempt): void
    {
        $simulateRaw = strtolower(trim((string) config('services.pii.external_kms.simulate', 'none')));
        $simulate = match ($simulateRaw) {
            'timeout_once' => $attempt === 1 ? 'timeout' : 'none',
            'retryable_once' => $attempt === 1 ? 'retryable' : 'none',
            'failure' => 'retryable',
            default => $simulateRaw,
        };
        $timeoutMs = max(1, (int) config('services.pii.external_kms.timeout_ms', 800));

        if ($simulate === 'timeout') {
            throw new ExternalKmsContractException(
                'EXTERNAL_KMS_TIMEOUT',
                "external kms {$operation} timeout after {$timeoutMs}ms",
                true,
                'timeout',
                $operation
            );
        }

        if ($simulate === 'retryable') {
            throw new ExternalKmsContractException(
                'EXTERNAL_KMS_RETRYABLE',
                "external kms {$operation} transient dependency failure",
                true,
                'retryable',
                $operation
            );
        }

        if ($simulate === 'non_retryable') {
            throw new ExternalKmsContractException(
                'EXTERNAL_KMS_NON_RETRYABLE',
                "external kms {$operation} non-retryable dependency failure",
                false,
                'non_retryable',
                $operation
            );
        }
    }

    private function allowLocalFallback(): bool
    {
        return (bool) config('services.pii.external_kms.dry_run', false)
            || (bool) config('services.pii.external_kms.fallback_to_local', false);
    }
}
