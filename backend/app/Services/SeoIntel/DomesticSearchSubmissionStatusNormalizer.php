<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class DomesticSearchSubmissionStatusNormalizer
{
    public function normalize(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            '', 'dry-run', 'dry_run', 'fixture' => 'dry_run',
            'ok', 'accepted', 'submitted', 'queued' => 'accepted',
            'failed', 'error', 'timeout' => 'failed',
            'blocked', 'rejected', 'ineligible' => 'blocked',
            default => 'unknown',
        };
    }
}
