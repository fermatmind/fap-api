<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;

final class PolicyGatewayPrivacyGuard
{
    public function __construct(private readonly SeoPrivateDataScanner $scanner) {}

    public function containsPrivateData(mixed $value): bool
    {
        if ($this->declaresPrivateData($value)) {
            return true;
        }

        return (bool) ($this->scanner->scan($this->normalizeHashes($value))['private_data_present'] ?? true);
    }

    private function normalizeHashes(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $childKey => $child) {
                $safeKey = (string) $childKey === 'private_data_present' ? 'contract_data_flag' : $childKey;
                $normalized[$safeKey] = $this->normalizeHashes($child, (string) $childKey);
            }

            return $normalized;
        }
        $hashKey = $key === 'hash'
            || $key === 'context_id'
            || str_ends_with((string) $key, '_hash')
            || str_ends_with((string) $key, '_revision')
            || str_ends_with((string) $key, '_ref');
        $shaKey = $key === 'sha' || str_ends_with((string) $key, '_sha');
        if (is_string($value)
            && (($hashKey && preg_match('/^[a-f0-9]{64}$/D', $value) === 1)
                || ($shaKey && preg_match('/^[a-f0-9]{40}$/D', $value) === 1))) {
            return 'verified_sha256';
        }

        return $value;
    }

    private function declaresPrivateData(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            if ((string) $key === 'private_data_present' && $child !== false) {
                return true;
            }
            if ($this->declaresPrivateData($child)) {
                return true;
            }
        }

        return false;
    }
}
