<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final readonly class BigFiveNormCaptureResult
{
    public function __construct(
        public bool $captured,
        public string $status,
        public string $reason,
        public ?string $observationId = null,
    ) {}

    public static function captured(string $observationId): self
    {
        return new self(true, 'captured', 'inserted', $observationId);
    }

    public static function skipped(string $reason): self
    {
        return new self(false, 'skipped', $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, 'rejected', $reason);
    }

    public static function duplicate(string $observationId): self
    {
        return new self(false, 'duplicate_replay', 'idempotency_key_already_seen', $observationId);
    }
}
