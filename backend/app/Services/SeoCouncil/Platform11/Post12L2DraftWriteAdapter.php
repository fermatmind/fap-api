<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

final class Post12L2DraftWriteAdapter
{
    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function authorize(array $manifest): array
    {
        $reason = match (true) {
            ($manifest['signed'] ?? false) !== true => 'UNSIGNED_MANIFEST',
            ($manifest['expires_at'] ?? null) !== 'future_verified_by_gateway' => 'EXPIRED_OR_UNVERIFIED_MANIFEST',
            ($manifest['scope'] ?? null) !== 'limited_cms_draft_fields' => 'WRONG_SCOPE',
            default => 'POST12_WRITE_DISABLED',
        };

        return [
            'status' => 'DENY',
            'reason' => $reason,
            'adapter_state' => 'IMPLEMENTED_WRITE_DISABLED',
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
    }
}
