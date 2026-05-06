<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final readonly class BigFiveNormCaptureDecision
{
    private function __construct(
        public bool $allowed,
        public string $status,
        public string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'allowed', 'capture_allowed');
    }

    public static function reject(string $reason): self
    {
        return new self(false, 'rejected', $reason);
    }

    public static function skip(string $reason): self
    {
        return new self(false, 'skipped', $reason);
    }
}
