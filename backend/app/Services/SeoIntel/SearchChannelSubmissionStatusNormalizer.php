<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SearchChannelSubmissionStatusNormalizer
{
    public function normalize(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'accepted', 'success', 'submitted' => 'accepted',
            'failed', 'error' => 'failed',
            'blocked', 'rejected' => 'blocked',
            'dry_run', 'dry-run', '' => 'dry_run',
            default => 'unknown',
        };
    }
}
