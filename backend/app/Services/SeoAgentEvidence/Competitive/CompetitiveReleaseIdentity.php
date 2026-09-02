<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use InvalidArgumentException;

final class CompetitiveReleaseIdentity
{
    public function reference(string $environment, string $releaseSha): string
    {
        if (! in_array($environment, ['staging', 'production'], true)
            || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1) {
            throw new InvalidArgumentException('COMPETITIVE_RELEASE_IDENTITY_INVALID');
        }

        return 'release_'.strtr(hash('sha256', $environment.'|'.$releaseSha), '0123456789', 'ghijklmnop');
    }
}
